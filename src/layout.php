<?php
// calls start_page() and end_page()
require_once __DIR__ . '/config.php';

function start_page(string $title, bool $requireLogin = true): array
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = [];
    if ($requireLogin) {
        $user = require_login();
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <title><?= h($title) ?> </title>
        <link rel="stylesheet" href="./css/style.css" />
    </head>

    <body>
        <?php if ($requireLogin): ?>
            <header>
                <div class="inner">
                    <a href="/index.php" class="logo">Epiphany</a>
                    <nav>
                        <a href="/profile.php">Profile</a>
                        <a href="/logout.php" class="logout">Logout</a>
                    </nav>
                </div>
            </header>
        <?php endif; ?>
        <main>
        <?php return $user;
    }

    function end_page(): void
    {
        ?>
        </main>
    </body>

    </html>
<?php } ?>