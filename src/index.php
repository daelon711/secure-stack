<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$error = '';

// ---- POST handling — now returns JSON when called via AJAX ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = require_login();

    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid request.']);
            exit;
        }
        $error = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                $errMsg = 'Note cannot be empty.';
            } elseif (strlen($title) > 255) {
                $errMsg = 'Note is too long (max 255 chars).';
            } else {
                $stmt = db()->prepare('INSERT INTO notes (user_id, title) VALUES (?, ?)');
                $stmt->execute([$user['id'], $title]);
                $errMsg = '';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['note_id'] ?? 0);
            $stmt = db()->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $user['id']]);
            $errMsg = '';
        } else {
            $errMsg = 'Unknown action.';
            log_honeypot($errMsg, 'trap', ['action' => $action]);
        }

        if ($isAjax) {
            // Return fresh notes list + streak so JS can re-render without reload
            if (!empty($errMsg)) {
                header('Content-Type: application/json');
                echo json_encode(['error' => $errMsg]);
                exit;
            }
            $stmt = db()->prepare('SELECT id, title, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC');
            $stmt->execute([$user['id']]);
            $freshNotes = $stmt->fetchAll();

            $stmt = db()->prepare('SELECT DISTINCT DATE(created_at) AS note_date FROM notes WHERE user_id = ? ORDER BY note_date DESC');
            $stmt->execute([$user['id']]);
            $noteDates = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $streak = 0;
            if (!empty($noteDates)) {
                $mostRecent = new DateTime($noteDates[0]);
                $today      = new DateTime('today');
                $yesterday  = (clone $today)->modify('-1 day');
                if ($mostRecent == $today || $mostRecent == $yesterday) {
                    $streak = 1;
                    $expected = clone $mostRecent;
                    for ($i = 1; $i < count($noteDates); $i++) {
                        $expected->modify('-1 day');
                        if (new DateTime($noteDates[$i]) == $expected) $streak++;
                        else break;
                    }
                }
            }
            header('Content-Type: application/json');
            echo json_encode(['notes' => $freshNotes, 'streak' => $streak]);
            exit;
        }
    }

    header('Location: /index.php' . ($error ? '?err=' . urlencode($error) : '') . '#notes');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$user = start_page('Epiphany');

if (isset($_GET['err'])) $error = $_GET['err'];

$chatErr = '';
if (isset($_GET['chat_err'])) {
    $chatErr = match ($_GET['chat_err']) {
        'empty'           => 'Message cannot be empty.',
        'too_long'        => 'Message is too long (max 500 characters).',
        'invalid_request' => 'Invalid request.',
        default           => 'Something went wrong.',
    };
}

// ---- Notes ----
$stmt = db()->prepare('SELECT id, title, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$notes = $stmt->fetchAll();

// ---- Notes streak ----
$stmt = db()->prepare('SELECT DISTINCT DATE(created_at) AS note_date FROM notes WHERE user_id = ? ORDER BY note_date DESC');
$stmt->execute([$user['id']]);
$noteDates = $stmt->fetchAll(PDO::FETCH_COLUMN);

$streak = 0;
if (!empty($noteDates)) {
    $mostRecent = new DateTime($noteDates[0]);
    $today      = new DateTime('today');
    $yesterday  = (clone $today)->modify('-1 day');

    if ($mostRecent == $today || $mostRecent == $yesterday) {
        $streak = 1;
        $expected = clone $mostRecent;
        for ($i = 1; $i < count($noteDates); $i++) {
            $expected->modify('-1 day');
            if (new DateTime($noteDates[$i]) == $expected) {
                $streak++;
            } else {
                break;
            }
        }
    }
}

$quotes = [
    'Small steps still move you forward.',
    'Future you is watching. Make them proud.',
    'One thing at a time.',
    'Progress, not perfection.',
];
$dailyQuote = $quotes[date('z') % count($quotes)];

// ---- Personal best per game ----
$stmt = db()->prepare('SELECT MAX(score) as best, MIN(time_sec) as fastest FROM game_scores WHERE user_id = ? AND game = ?');
$stmt->execute([$user['id'], 'maze']);
$mazePersonal = $stmt->fetch();

$stmt = db()->prepare('SELECT MAX(score) as best, MIN(time_sec) as fastest, COUNT(*) as completed FROM game_scores WHERE user_id = ? AND game = ?');
$stmt->execute([$user['id'], 'sudoku']);
$sudokuPersonal = $stmt->fetch();

// Sudoku puzzle
$sudokuGame = get_sudoku_api();
$puzzle     = $sudokuGame['puzzle'];
$solution   = $sudokuGame['solution'];
$difficulty = $sudokuGame['difficulty'];

// ---- Leaderboard ----
$boards = [];
foreach (['maze'] as $g) {
    $stmt = db()->prepare(
        'SELECT * FROM (
             SELECT gs.*, u.username, u.avatar,
                    ROW_NUMBER() OVER (PARTITION BY gs.user_id ORDER BY gs.score DESC, gs.time_sec ASC) AS rn
             FROM game_scores gs
             JOIN users u ON u.id = gs.user_id
             WHERE gs.game = ?
         ) ranked
         WHERE ranked.rn = 1
         ORDER BY ranked.score DESC
         LIMIT 20'
    );
    $stmt->execute([$g]);
    $boards[$g] = $stmt->fetchAll();
}

// ---- Chat messages ----
$stmt = db()->prepare(
    'SELECT m.*, u.username, u.avatar
     FROM messages m
     JOIN users u ON u.id = m.user_id
     ORDER BY m.created_at DESC
     LIMIT 100'
);
$stmt->execute();
$messages = array_reverse($stmt->fetchAll());
?>
<div class="container dashboard-container">

    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="dashboard-grid">

        <!-- ===== LEFT COLUMN: Maze + Scores ===== -->
        <div class="dash-col dash-col-left">

            <!-- ============ MAZE ============ -->
            <section id="games" class="dash-card-section">
                <h1 class="page-title page-title-sm">Maze</h1>
                <div class="card card-center">
                    <canvas id="mazeCanvas" width="360" height="360"></canvas>

                    <div id="gameStatus" class="game-status">
                        Press any arrow key to start!
                    </div>

                    <div class="game-stats-row">
                        <div>
                            <span class="stat-label">Time</span>
                            <div id="timer" class="stat-value">0.0s</div>
                        </div>
                        <div>
                            <span class="stat-label">Moves</span>
                            <div id="moves" class="stat-value">0</div>
                        </div>
                    </div>

                    <?php if ($mazePersonal['best']): ?>
                        <div class="personal-best">
                            Best: <?= (int)$mazePersonal['best'] ?> pts (<?= round($mazePersonal['fastest'], 1) ?>s)
                        </div>
                    <?php endif; ?>

                    <div class="mt2">
                        <button id="newMazeBtn" class="btn btn-outline btn-sm">New Maze</button>
                    </div>
                </div>
            </section>

            <!-- ============ SCORES / LEADERBOARD ============ -->
            <section id="leaderboard" class="dash-card-section">
                <h1 class="page-title page-title-sm">Scores</h1>

                <div class="card board-maze">
                    <?php if (empty($boards['maze'])): ?>
                        <div class="empty-state">
                            No scores yet! Be the first to play.
                        </div>
                    <?php else: ?>
                        <ol class="leaderboard-list">
                            <?php foreach ($boards['maze'] as $i => $entry): ?>
                                <li class="leaderboard-item<?= ((int)$entry['user_id'] === (int)$user['id']) ? ' leaderboard-item--you' : '' ?>">
                                    <span class="leaderboard-rank"><?= $i + 1 ?></span>
                                    <span class="leaderboard-name">
                                        <?= h($entry['username']) ?>
                                        <?php if ((int)$entry['user_id'] === (int)$user['id']): ?>
                                            <span class="you-tag">(you)</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="leaderboard-score"><?= (int)$entry['score'] ?> pts</span>
                                    <span class="leaderboard-time"><?= round($entry['time_sec'], 1) ?>s</span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </section>

        </div>

        <!-- ===== CENTER COLUMN ===== -->
        <div class="dash-col dash-col-center">

            <!-- ============ NOTES ============ -->
            <section id="notes" class="dash-card-section dash-card-tall">
                <div class="section-header-row">
                    <h1 class="page-title page-title-notes">Today I realized ... </h1>
                    <p class="streak-badge">journaling streak: <?= (int)$streak ?> day<?= $streak === 1 ? '' : 's' ?></p>
                </div>

                <div class="card">
                    <form method="POST" action="/index.php" class="note-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>" />
                        <input type="hidden" name="action" value="add" />
                        <input type="text" name="title" placeholder="Write a note of the day..." maxlength="255" required />
                        <button type="submit" class="btn btn-primary">+ Add</button>
                    </form>

                    <!-- JS renders envelope cards into this div — no page reload -->
                    <div class="note-list-wrap"></div>
                </div>
            </section>

        </div>

        <!-- ===== RIGHT COLUMN: Sudoku + Chat ===== -->
        <div class="dash-col dash-col-right">

            <!-- ============ SUDOKU ============ -->
            <section id="sudoku" class="dash-card-section">
                <h1 class="page-title page-title-sm">Sudoku</h1>

                <div class="card card-center">
                    <div class="sudoku-board-wrap">
                        <div id="sudokuGrid" class="sudoku-grid"></div>
                        <div id="sudokuPalette" class="sudoku-palette"></div>
                    </div>
                    <div id="sudokuStatus" class="mt-08 sudoku-status"></div>
                    <div class="sudoku-stats-row">
                        <span>Time: <span id="sudokuTimer">0.0s</span></span>
                        <span>Strikes: <span id="sudokuStrikes">0</span>/3</span>
                    </div>
                    <div class="mt-08">
                        <button id="checkSudokuBtn" class="btn btn-primary btn-sm">Check</button>
                        <button id="refreshSudokuBtn" class="btn btn-outline btn-sm ml-08">Refresh</button>
                    </div>
                    <div class="sudoku-info">
                        <p class="page-subtitle" id="sudokuDifficulty">difficulty: <span><?= h($difficulty) ?></span></p>
                        <p>You've completed <?= (int)($sudokuPersonal['completed'] ?? 0) ?> sudoku game<?= ((int)($sudokuPersonal['completed'] ?? 0) === 1) ? '' : 's' ?>.</p>
                    </div>
                </div>
            </section>

            <!-- ============ CHAT ============ -->
            <section id="chat" class="dash-card-section">
                <?php if ($chatErr): ?>
                    <div class="alert alert-error"><?= h($chatErr) ?></div>
                <?php endif; ?>

                <h1 class="page-title page-title-sm">Post it note</h1>

                <div class="card">
                    <div class="chat-messages" id="chatMessages">
                        <?php if (empty($messages)): ?>
                            <div class="empty-state">
                                No messages yet. Say something!
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="chat-bubble">
                                    <div class="chat-bubble-avatar">
                                        <?php if (!empty($msg['avatar']) && file_exists(UPLOAD_DIR . $msg['avatar'])): ?>
                                            <img src="<?= h(UPLOAD_URL . $msg['avatar']) ?>" class="chat-avatar-img" />
                                        <?php endif; ?>
                                    </div>
                                    <div class="chat-bubble-body">
                                        <div class="chat-bubble-name"><?= h($msg['username']) ?></div>
                                        <!-- intentional -->
                                        <div class="chat-bubble-text"><?= $msg['content'] ?></div>
                                        <div class="chat-bubble-time"><?= h(date('M j, g:ia', strtotime($msg['created_at']))) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="/chat.php" class="chat-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>" />
                        <div class="hp-field" aria-hidden="true">
                            <input type="text" name="fax_number" tabindex="-1" autocomplete="off" />
                        </div>
                        <input type="text" name="content" placeholder="Type a message..." maxlength="500" required
                            autocomplete="off" />
                        <button type="submit" class="btn btn-primary">Send</button>
                    </form>
                </div>
            </section>

        </div>

    </div>

</div>

<div id="app-data" class="visually-hidden"
     data-csrf="<?= h(csrf_token()) ?>"
     data-puzzle="<?= h(json_encode($puzzle)) ?>"
     data-solution="<?= h(json_encode($solution)) ?>"></div>

<!-- notes data - read by notes.js via .dataset, never a <script> element so CSP's script-src never evaluates it -->
<div id="notes-data" class="visually-hidden"
     data-csrf="<?= h(csrf_token()) ?>"
     data-notes="<?= h(json_encode($notes)) ?>"
     data-streak="<?= h((string)$streak) ?>"></div>

<script src="/js/app.js"></script>
<script src="/js/maze.js"></script>
<script src="/js/sudoku.js"></script>
<script src="/js/notes.js"></script>
<?php end_page(); ?>
