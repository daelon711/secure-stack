<?php

// The DB password is loaded from /etc/securestack/db.env at runtime

// ---- Load the DB password ---------
$envFile = '/etc/securestack/db.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        putenv("$k=$v");
        $_ENV[$k] = $v;
    }
}

// defining constants
define('DB_HOST', getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', getenv('DB_NAME') ?: 'securestack');
define('DB_USER', getenv('DB_USER') ?: 'ss_app');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', '/uploads/');
define('MAX_AVATAR_SIZE', 2 * 1024 * 1024);
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png',  'image/webp']);

// Password policy
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_REQUIRE_UPPER',   true);
define('PASSWORD_REQUIRE_LOWER',   true);
define('PASSWORD_REQUIRE_NUMBER',  true);
define('PASSWORD_REQUIRE_SPECIAL', true);

// ---- Session cookie hardening -----------

// intentional
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => false, // intentional
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');

// ---- Database -----------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            die('Database unavailable. Please try again later.');
        }
    }
    return $pdo;
}

// ---- Application event logging -----------------
function log_event(string $type, ?int $userId, string $usernameAttempted = '', string $details = ''): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (event_type, user_id, username_attempted, ip_address, user_agent, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $userId, $usernameAttempted, $ip, $ua, $details]);
    } catch (PDOException $e) {
        error_log('log_event failed: ' . $e->getMessage());
    }
    // JSON
    $jsonLog = [
        '@timestamp' => date('c'),
        'app'        => 'epiphany',
        'event'      => $type,
        'user_id'    => $userId,
        'username'   => $usernameAttempted,
        'source_ip'  => $ip,
        'user_agent' => $ua,
        'details'    => $details,
    ];
    @file_put_contents(
        '/var/log/securestack/app.log',
        json_encode($jsonLog, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ---- Trap logging (robots.txt lure) --------------------
function log_honeypot(string $trapName, string $layer, array $extra = []): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $ip = $forwarded;
        }
    }
    $ip = trim(explode(',', $ip)[0]);

    $data = array_merge([
        'post_data'    => $_POST,
        'cookies'      => $_COOKIE,
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'all_headers'  => function_exists('getallheaders') ? getallheaders() : [],
        'detected_tool' => detect_tool($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ], $extra);

    try {
        $stmt = db()->prepare(
            'INSERT INTO trap_log (trap_name, layer, source_ip, method, request_uri, user_agent, referer, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $trapName,
            $layer,
            $ip,
            $_SERVER['REQUEST_METHOD'] ?? '',
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_REFERER'] ?? '',
            json_encode($data, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (PDOException $e) {
        error_log('log_honeypot failed: ' . $e->getMessage());
    }

    $jsonLog = [
        '@timestamp' => date('c'),
        'app'        => 'epiphany',
        'trap_name'  => $trapName,
        'layer'      => $layer,
        'source_ip'  => $ip,
        'method'     => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'        => $_SERVER['REQUEST_URI'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referer'    => $_SERVER['HTTP_REFERER'] ?? '',
        'details'    => $data,
    ];
    @file_put_contents(
        '/var/log/securestack/trap.log',
        json_encode($jsonLog, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ---- Detect common attack-tool signatures ----------
function detect_tool(string $ua): string
{
    $signatures = [
        'sqlmap'          => 'sqlmap',
        'nikto'           => 'nikto',
        'gobuster'        => 'gobuster',
        'dirbuster'       => 'dirbuster',
        'feroxbuster'     => 'feroxbuster',
        'ffuf'            => 'ffuf',
        'wfuzz'           => 'wfuzz',
        'burp'            => 'burpsuite',
        'nmap'            => 'nmap',
        'masscan'         => 'masscan',
        'hydra'           => 'hydra',
        'medusa'          => 'medusa',
        'wpscan'          => 'wpscan',
        'curl/'           => 'curl',
        'python-requests' => 'python-requests',
        'wget/'           => 'wget',
        'zaproxy'         => 'owasp-zap',
        'zgrab'           => 'zgrab',
    ];
    $uaLower = strtolower($ua);
    foreach ($signatures as $sig => $tool) {
        if (str_contains($uaLower, $sig)) return $tool;
    }
    return '';
}

// ---- Session / Auth -----------------
function current_user(): ?array
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    if (!$user['is_enabled']) {
        session_destroy();
        header('Location: /login.php?error=disabled');
        exit;
    }
    return $user;
}

// ---- Output escaping ------------
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- CSRF tokens -------------------
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    return hash_equals(csrf_token(), $token);
}

// ---- Password validation --------------------
function validate_password(string $password): string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH)
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    if (PASSWORD_REQUIRE_UPPER && !preg_match('/[A-Z]/', $password))
        return 'Password must contain at least one uppercase letter (A-Z).';
    if (PASSWORD_REQUIRE_LOWER && !preg_match('/[a-z]/', $password))
        return 'Password must contain at least one lowercase letter (a-z).';
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password))
        return 'Password must contain at least one number (0-9).';
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[\W_]/', $password))
        return 'Password must contain at least one special character (!@#$%...).';
    return '';
}

// ---- Sudoku puzzle source (Dosuku - free, no API key) -----------------
// Docs: https://sudoku-api.vercel.app/
define('SUDOKU_API_URL', 'https://sudoku-api.vercel.app/api/dosuku?query={newboard(limit:1){grids{value,solution,difficulty}}}');

/**
 * Fetches a puzzle + its solution from the Dosuku API.
 * Returns ['puzzle' => 9x9 array, 'solution' => 9x9 array, 'difficulty' => string]
 * or null if the API call fails / times out / returns something unexpected.
 */
function fetch_sudoku_api(): ?array
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 5, // seconds
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents(SUDOKU_API_URL, false, $context);

    if ($raw === false) {
        error_log('Sudoku API request failed or timed out.');
        return null;
    }

    $data = json_decode($raw, true);
    $grid = $data['newboard']['grids'][0] ?? null;

    if (!$grid || !isset($grid['value'], $grid['solution']) || count($grid['value']) !== 9) {
        error_log('Sudoku API returned an unexpected response shape.');
        return null;
    }

    return [
        'puzzle'     => $grid['value'],
        'solution'   => $grid['solution'],
        'difficulty' => $grid['difficulty'] ?? 'Unknown',
    ];
}

/**
 * A single hardcoded puzzle + solution used only if the Dosuku API is
 * unreachable, so the game still works offline / if the API is down.
 */
function fallback_sudoku_api(): array
{
    return [
        'puzzle' => [
            [5, 3, 0, 0, 7, 0, 0, 0, 0],
            [6, 0, 0, 1, 9, 5, 0, 0, 0],
            [0, 9, 8, 0, 0, 0, 0, 6, 0],
            [8, 0, 0, 0, 6, 0, 0, 0, 3],
            [4, 0, 0, 8, 0, 3, 0, 0, 1],
            [7, 0, 0, 0, 2, 0, 0, 0, 6],
            [0, 6, 0, 0, 0, 0, 2, 8, 0],
            [0, 0, 0, 4, 1, 9, 0, 0, 5],
            [0, 0, 0, 0, 8, 0, 0, 7, 9],
        ],
        'solution' => [
            [5, 3, 4, 6, 7, 8, 9, 1, 2],
            [6, 7, 2, 1, 9, 5, 3, 4, 8],
            [1, 9, 8, 3, 4, 2, 5, 6, 7],
            [8, 5, 9, 7, 6, 1, 4, 2, 3],
            [4, 2, 6, 8, 5, 3, 7, 9, 1],
            [7, 1, 3, 9, 2, 4, 8, 5, 6],
            [9, 6, 1, 5, 3, 7, 2, 8, 4],
            [2, 8, 7, 4, 1, 9, 6, 3, 5],
            [3, 4, 5, 2, 8, 6, 1, 7, 9],
        ],
        'difficulty' => 'Easy',
    ];
}

/** Fetches from the API, falling back to the hardcoded puzzle on failure. */
function get_sudoku_api(): array
{
    return fetch_sudoku_api() ?? fallback_sudoku_api();
}
