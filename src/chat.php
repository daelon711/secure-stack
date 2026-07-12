<?php

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php#chat');
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$user = require_login();

$err = '';

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $err = 'invalid_request';
} else {
    $content = trim($_POST['content'] ?? '');

    if ($content === '') {
        $err = 'empty';
    } elseif (mb_strlen($content) > 500) {
        $err = 'too_long';
    } else {
        // intentional - content stored raw
        $stmt = db()->prepare('INSERT INTO messages (user_id, content) VALUES (?, ?)');
        $stmt->execute([$user['id'], $content]);
        log_event(
            'CHAT_MESSAGE',
            $user['id'],
            $user['username'],
            json_encode(['length' => mb_strlen($content)])
        );
    }
}

header('Location: /index.php' . ($err ? '?chat_err=' . urlencode($err) : '') . '#chat');
exit;
