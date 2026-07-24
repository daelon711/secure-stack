<?php
// JSON endpoint only - returns a fresh puzzle for the client to load
// after a win, without reloading the whole page.
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_login(); // must be logged in, but no CSRF needed - this is a read-only GET

$game = get_sudoku_api();

header('Content-Type: application/json');
echo json_encode([
    'puzzle'   => $game['puzzle'],
    'solution' => $game['solution'],
    'difficulty' => $game['difficulty'],

]);
exit;
