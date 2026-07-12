<?php
// Score-submission endpoint only. The sudoku grid and JS now live
// inline in index.php (#games section). This file just persists results.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php#games');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$user = require_login();

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$score   = (int)($_POST['score'] ?? 0);
$timeSec = (float)($_POST['time_sec'] ?? 0);

if ($score > 0 && $timeSec > 0) {
    $stmt = db()->prepare('INSERT INTO game_scores (user_id, game, score, time_sec) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user['id'], 'sudoku', $score, $timeSec]);
    log_event(
        'GAME_SCORE',
        $user['id'],
        $user['username'],
        json_encode(['game' => 'sudoku', 'score' => $score, 'time' => $timeSec])
    );
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
exit;
