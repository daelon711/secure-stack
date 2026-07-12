<?php
require_once __DIR__ . '/config.php';

if (($_GET['format'] ?? '') === 'json') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = require_login();

    $boards = [];
    foreach (['maze', 'sudoku'] as $g) {
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

    header('Content-Type: application/json');
    echo json_encode([
        'boards'          => $boards,
        'current_user_id' => (int)$user['id'],
    ]);
    exit;
}

// The leaderboard normally renders inline in index.php (#leaderboard section).
// This stub just preserves the old URL/bookmark so it doesn't 404.
header('Location: /index.php#leaderboard');
exit;
