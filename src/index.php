<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$error = '';

// ---- POST handling (notes CRUD only - games/chat post to their own endpoints) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user = require_login();

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                $error = 'Note cannot be empty.';
            } elseif (strlen($title) > 255) {
                $error = 'Note is too long (max 255 chars).';
            } else {
                $stmt = db()->prepare('INSERT INTO notes (user_id, title) VALUES (?, ?)');
                $stmt->execute([$user['id'], $title]);
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['note_id'] ?? 0);
            $stmt = db()->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $user['id']]);
        } else {
            $error = 'Unknown action.';
            log_honeypot($error, 'trap', ['action' => $action]);
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
        'empty'          => 'Message cannot be empty.',
        'too_long'       => 'Message is too long (max 500 characters).',
        'invalid_request' => 'Invalid request.',
        default          => 'Something went wrong.',
    };
}

// ---- Notes ----
$stmt = db()->prepare('SELECT * FROM notes WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$notes = $stmt->fetchAll();

$quotes = [
    'Small steps still move you forward.',
    'Future you is watching. Make them proud.',
    'One thing at a time.',
    'Progress, not perfection.',
];
$dailyQuote = $quotes[date('z') % count($quotes)];

// ---- Personal best per game (for the games section) ----
$stmt = db()->prepare('SELECT MAX(score) as best, MIN(time_sec) as fastest FROM game_scores WHERE user_id = ? AND game = ?');
$stmt->execute([$user['id'], 'maze']);
$mazePersonal = $stmt->fetch();

$stmt = db()->prepare('SELECT MAX(score) as best, MIN(time_sec) as fastest, COUNT(*) as completed FROM game_scores WHERE user_id = ? AND game = ?');
$stmt->execute([$user['id'], 'sudoku']);
$sudokuPersonal = $stmt->fetch();

// Sudoku puzzle: pulled from the free Dosuku API ( config.php)
// falling back to a hardcoded puzzle if the API is unreachable
$sudokuGame = get_sudoku_api();
$puzzle     = $sudokuGame['puzzle'];
$solution   = $sudokuGame['solution'];
$difficulty = $sudokuGame['difficulty'];

// ---- Leaderboard (maze only - Scores card shows maze scores) ----
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
                        <button onclick="resetGame()" class="btn btn-outline btn-sm">New Maze</button>
                    </div>
                </div>
            </section>

            <!-- ============ SCORES / LEADERBOARD (maze only) ============ -->
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
                <h1 class="page-title">Today I realized ... </h1>

                <div class="card">
                    <form method="POST" action="/index.php" class="note-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>" />
                        <input type="hidden" name="action" value="add" />
                        <input type="text" name="title" placeholder="Write a note of the day..." maxlength="255" required />
                        <button type="submit" class="btn btn-primary">+ Add</button>
                    </form>

                    <?php if (empty($notes)): ?>
                        <div class="note-empty">No notes yet.</div>
                    <?php else: ?>
                        <ul class="note-list">
                            <?php foreach ($notes as $note): ?>
                                <li class="note-item">
                                    <span class="note-text"><?= h($note['title']) ?></span>

                                    <form method="POST" action="/index.php" class="display-contents">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>" />
                                        <input type="hidden" name="action" value="delete" />
                                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>" />
                                        <button type="submit" class="note-delete" title="Delete">🗑</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
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
                        <button onclick="checkSudoku()" class="btn btn-primary btn-sm">Check</button>
                        <button onclick="loadNewPuzzle()" class="btn btn-outline btn-sm ml-08">Refresh</button>
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

<script>
    // ---- chat: scroll to bottom ----
    var c = document.getElementById('chatMessages');
    if (c) c.scrollTop = c.scrollHeight;

    // ---- leaderboard: tab toggle ----
    function showBoard(game) {
        document.querySelector('.board-maze').style.display = game === 'maze' ? '' : 'none';
        document.querySelector('.board-sudoku').style.display = game === 'sudoku' ? '' : 'none';
        document.getElementById('tabMaze').style.fontWeight = game === 'maze' ? '700' : '400';
        document.getElementById('tabSudoku').style.fontWeight = game === 'sudoku' ? '700' : '400';
    }

    // ---- leaderboard: live refresh (auto-poll + refresh right after a score is submitted) ----
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.innerText = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderBoard(game, entries, currentUserId) {
        var container = document.querySelector('.board-' + game);
        if (!container) return;
        if (!entries || !entries.length) {
            container.innerHTML = '<div class="empty-state">No scores yet! Be the first to play.</div>';
            return;
        }
        var html = '<ol class="leaderboard-list">';
        entries.forEach(function(entry, i) {
            var isYou = parseInt(entry.user_id, 10) === currentUserId;
            html += '<li class="leaderboard-item' + (isYou ? ' leaderboard-item--you' : '') + '">';
            html += '<span class="leaderboard-rank">' + (i + 1) + '</span>';
            html += '<span class="leaderboard-name">' + escapeHtml(entry.username) + (isYou ? ' <span class="you-tag">(you)</span>' : '') + '</span>';
            html += '<span class="leaderboard-score">' + parseInt(entry.score, 10) + ' pts</span>';
            html += '<span class="leaderboard-time">' + parseFloat(entry.time_sec).toFixed(1) + 's</span>';
            html += '</li>';
        });
        html += '</ol>';
        container.innerHTML = html;
    }

    function refreshLeaderboard() {
        fetch('/leaderboard.php?format=json')
            .then(function(r) {
                return r.json();
            })
            .then(function(data) {
                renderBoard('maze', data.boards.maze, data.current_user_id);
                renderBoard('sudoku', data.boards.sudoku, data.current_user_id);
            })
            .catch(function() {
                /* silent - stale board is fine, next poll will fix it */
            });
    }

    // auto-refresh every 8s, same idea as the old project
    setInterval(refreshLeaderboard, 8000);
</script>

<!-- ============ MAZE GAME LOGIC ============ -->
<script>
    (function() {
        var canvas = document.getElementById('mazeCanvas');
        var ctx = canvas.getContext('2d');
        var CELL = 36,
            COLS = 10,
            ROWS = 10;
        canvas.width = COLS * CELL;
        canvas.height = ROWS * CELL;


        var playerImg = new Image();
        var goalImg = new Image();
        var imagesLoaded = 0;
        playerImg.src = '/images/octopus.png';  
        goalImg.src = '/images/octopuswoman.png';     
        playerImg.onload = goalImg.onload = function() {
        imagesLoaded++;
        if (imagesLoaded === 2) resetGame(); 
    };

        var maze, player, goal, moves, startTime, timerInterval, gameActive, gameStarted;
        var csrf = '<?= h(csrf_token()) ?>';

        function initMaze() {
            maze = [];
            for (var r = 0; r < ROWS; r++) {
                maze[r] = [];
                for (var c = 0; c < COLS; c++) {
                    maze[r][c] = {
                        top: true,
                        right: true,
                        bottom: true,
                        left: true,
                        visited: false
                    };
                }
            }
            var stack = [{
                r: 0,
                c: 0
            }];
            maze[0][0].visited = true;

            while (stack.length > 0) {
                var cur = stack[stack.length - 1];
                var neighbors = [];
                var dirs = [{
                        dr: -1,
                        dc: 0,
                        wall: 'top',
                        opp: 'bottom'
                    },
                    {
                        dr: 1,
                        dc: 0,
                        wall: 'bottom',
                        opp: 'top'
                    },
                    {
                        dr: 0,
                        dc: -1,
                        wall: 'left',
                        opp: 'right'
                    },
                    {
                        dr: 0,
                        dc: 1,
                        wall: 'right',
                        opp: 'left'
                    }
                ];
                for (var i = 0; i < dirs.length; i++) {
                    var nr = cur.r + dirs[i].dr,
                        nc = cur.c + dirs[i].dc;
                    if (nr >= 0 && nr < ROWS && nc >= 0 && nc < COLS && !maze[nr][nc].visited) {
                        neighbors.push({
                            r: nr,
                            c: nc,
                            wall: dirs[i].wall,
                            opp: dirs[i].opp
                        });
                    }
                }
                if (neighbors.length > 0) {
                    var next = neighbors[Math.floor(Math.random() * neighbors.length)];
                    maze[cur.r][cur.c][next.wall] = false;
                    maze[next.r][next.c][next.opp] = false;
                    maze[next.r][next.c].visited = true;
                    stack.push({
                        r: next.r,
                        c: next.c
                    });
                } else {
                    stack.pop();
                }
            }
        }

        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.strokeStyle = '#88b8ce';
            ctx.lineWidth = 2;
            for (var r = 0; r < ROWS; r++) {
                for (var c = 0; c < COLS; c++) {
                    var x = c * CELL,
                        y = r * CELL;
                    var cell = maze[r][c];
                    if (cell.top) {
                        ctx.beginPath();
                        ctx.moveTo(x, y);
                        ctx.lineTo(x + CELL, y);
                        ctx.stroke();
                    }
                    if (cell.right) {
                        ctx.beginPath();
                        ctx.moveTo(x + CELL, y);
                        ctx.lineTo(x + CELL, y + CELL);
                        ctx.stroke();
                    }
                    if (cell.bottom) {
                        ctx.beginPath();
                        ctx.moveTo(x, y + CELL);
                        ctx.lineTo(x + CELL, y + CELL);
                        ctx.stroke();
                    }
                    if (cell.left) {
                        ctx.beginPath();
                        ctx.moveTo(x, y);
                        ctx.lineTo(x, y + CELL);
                        ctx.stroke();
                    }
                }
            }
                ctx.drawImage(goalImg, goal.c * CELL , goal.r * CELL , CELL , CELL );
                ctx.drawImage(playerImg, player.c * CELL , player.r * CELL , CELL, CELL );



        }

        function move(dr, dc) {
            if (!gameActive) return;
            if (!gameStarted) {
                gameStarted = true;
                startTime = Date.now();
                timerInterval = setInterval(updateTimer, 100);
            }

            var cell = maze[player.r][player.c];
            var nr = player.r + dr,
                nc = player.c + dc;

            if (dr === -1 && cell.top) return;
            if (dr === 1 && cell.bottom) return;
            if (dc === -1 && cell.left) return;
            if (dc === 1 && cell.right) return;
            if (nr < 0 || nr >= ROWS || nc < 0 || nc >= COLS) return;

            player.r = nr;
            player.c = nc;
            moves++;
            document.getElementById('moves').textContent = moves;
            draw();

            if (player.r === goal.r && player.c === goal.c) {
                gameActive = false;
                clearInterval(timerInterval);
                var timeSec = (Date.now() - startTime) / 1000;
                var score = Math.max(1, Math.round(10000 / (moves + timeSec)));
                document.getElementById('gameStatus').textContent = 'Score: ' + score + ' pts ';
                document.getElementById('gameStatus').style.color = '#4a7c59';
                submitScore(score, timeSec);
            }
        }

        function updateTimer() {
            if (startTime) {
                document.getElementById('timer').textContent = ((Date.now() - startTime) / 1000).toFixed(1) + 's';
            }
        }

        function submitScore(score, timeSec) {
            var form = new FormData();
            form.append('csrf_token', csrf);
            form.append('score', score);
            form.append('time_sec', timeSec.toFixed(2));
            fetch('/maze.php', {
                method: 'POST',
                body: form
            }).then(function() {
                refreshLeaderboard();
            });
        }

        window.resetGame = function() {
            clearInterval(timerInterval);
            initMaze();
            player = {
                r: 0,
                c: 0
            };
            goal = {
                r: ROWS - 1,
                c: COLS - 1
            };
            moves = 0;
            startTime = null;
            gameActive = true;
            gameStarted = false;
            document.getElementById('moves').textContent = '0';
            document.getElementById('timer').textContent = '0.0s';
            document.getElementById('gameStatus').textContent = 'Press any arrow key to start';
            document.getElementById('gameStatus').style.color = '';
            draw();
        };

        document.addEventListener('keydown', function(e) {
            switch (e.key) {
                case 'ArrowUp':
                
                    e.preventDefault();
                    move(-1, 0);
                    break;
                case 'ArrowDown':
                
                    e.preventDefault();
                    move(1, 0);
                    break;
                case 'ArrowLeft':
                
                    e.preventDefault();
                    move(0, -1);
                    break;
                case 'ArrowRight':
                
                    e.preventDefault();
                    move(0, 1);
                    break;
            }
        });

    })();
</script>

<!-- ============ SUDOKU GAME LOGIC ============ -->
<script>
    (function() {
        var csrf = '<?= h(csrf_token()) ?>';
        var grid = document.getElementById('sudokuGrid');
        var palette = document.getElementById('sudokuPalette');
        var statusEl = document.getElementById('sudokuStatus');
        var strikesEl = document.getElementById('sudokuStrikes');
        var timerEl = document.getElementById('sudokuTimer');

        // puzzle/solution come from the Dosuku API via PHP (config.php -> get_sudoku_api())
        var puzzle = <?= json_encode($puzzle) ?>;
        var solution = <?= json_encode($solution) ?>;

        var startTime = null;
        var timerInterval = null;
        var sudokuStarted = false;
        var selectedCell = null;
        var strikes = 0;
        var loadingNewPuzzle = false;

        function setStatus(msg, color) {
            statusEl.textContent = msg || '';
            statusEl.style.color = color || '';
        }

        function startSudokuTimer() {
            if (sudokuStarted) return;
            sudokuStarted = true;
            startTime = Date.now();
            timerInterval = setInterval(function() {
                timerEl.textContent = ((Date.now() - startTime) / 1000).toFixed(1) + 's';
            }, 100);
        }

        function selectCell(cell) {
            if (selectedCell) selectedCell.classList.remove('selected');
            selectedCell = cell;
            selectedCell.classList.add('selected');
        }

        // ---- build the 9x9 tap-to-select grid from a puzzle array ----
        function buildGrid(puzzleGrid) {
            grid.innerHTML = '';
            selectedCell = null;
            for (var r = 0; r < 9; r++) {
                for (var c = 0; c < 9; c++) {
                    var val = puzzleGrid[r][c];
                    var cell = document.createElement('div');
                    cell.className = 'sudoku-cell';
                    cell.dataset.r = r;
                    cell.dataset.c = c;

                    // thicker 3x3 box borders are handled in style.css via :nth-child

                    if (val !== 0) {
                        cell.textContent = val;
                        cell.classList.add('given');
                    } else {
                        cell.addEventListener('click', function() {
                            selectCell(this);
                        });
                    }
                    grid.appendChild(cell);
                }
            }
        }
        buildGrid(puzzle);

        // ---- number palette (built once - just swaps the grid underneath it) ----
        function handleNumberTap(n) {
            if (!selectedCell || loadingNewPuzzle) return;
            startSudokuTimer();

            var r = +selectedCell.dataset.r,
                c = +selectedCell.dataset.c;

            if (solution[r][c] !== n) {
                strikes++;
                strikesEl.textContent = strikes;
                selectedCell.textContent = '';
                setStatus('Nope, that number is wrong.', '#a13a1e');

                if (strikes >= 3) {
                    setStatus('3 strikes - puzzle reset!', '#a13a1e');
                    setTimeout(function() {
                        resetSudoku();
                        setStatus('3 strikes - puzzle reset! Try again.', '#a13a1e');
                    }, 700);
                }
                return;
            }

            selectedCell.textContent = n;
            setStatus('', '');
        }

        for (var n = 1; n <= 9; n++) {
            (function(n) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'sudoku-num-btn';
                btn.textContent = n;
                btn.addEventListener('click', function() {
                    handleNumberTap(n);
                });
                palette.appendChild(btn);
            })(n);
        }

        var eraseBtn = document.createElement('button');
        eraseBtn.type = 'button';
        eraseBtn.className = 'sudoku-num-btn sudoku-erase-btn';
        eraseBtn.textContent = '×';
        eraseBtn.title = 'Clear cell';
        eraseBtn.addEventListener('click', function() {
            if (!selectedCell) return;
            selectedCell.textContent = '';
        });
        palette.appendChild(eraseBtn);

        // ---- reset the CURRENT puzzle (same clues, blank entries) ----
        // used by the Reset button and automatically after 3 strikes
        function resetSudoku() {
            clearInterval(timerInterval);
            sudokuStarted = false;
            startTime = null;
            strikes = 0;
            strikesEl.textContent = '0';
            timerEl.textContent = '0.0s';
            setStatus('', '');
            grid.querySelectorAll('.sudoku-cell:not(.given)').forEach(function(cell) {
                cell.textContent = '';
                cell.classList.remove('selected');
            });
            selectedCell = null;
        }
        window.resetSudoku = resetSudoku;

        // ---- fetch a brand new puzzle from the API (used after a win) ----
        function loadNewPuzzle() {
            loadingNewPuzzle = true;
            var difficultyEl = document.getElementById('sudokuDifficulty');
            fetch('/sudoku_api.php')
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    puzzle = data.puzzle;
                    solution = data.solution;
                    difficultyEl.textContent = data.difficulty;

                    buildGrid(puzzle);
                    resetSudoku();
                    setStatus('New puzzle loaded', '#f1c166');
                })
                .catch(function() {
                    setStatus('Could not load a new puzzle - hit Reset to try again.', '#a13a1e');
                })
                .then(function() {
                    loadingNewPuzzle = false;
                });
        }
        window.loadNewPuzzle = loadNewPuzzle;

        // ---- Check button: board must be completely filled, then verified ----
        window.checkSudoku = function() {
            var cells = grid.querySelectorAll('.sudoku-cell');
            var complete = true;
            var allCorrect = true;
            cells.forEach(function(cell) {
                var r = +cell.dataset.r,
                    c = +cell.dataset.c;
                var val = parseInt(cell.textContent) || 0;
                if (val === 0) complete = false;
                else if (val !== solution[r][c]) allCorrect = false;
            });

            if (!complete) {
                setStatus('Fill in every cell first.', '#a13a1e');
                return;
            }
            if (!allCorrect) {
                setStatus('Not quite - check for mistakes.', '#a13a1e');
                return;
            }

            clearInterval(timerInterval);
            var timeSec = (Date.now() - startTime) / 1000;
            var score = Math.max(1, Math.round(5000 / timeSec));
            setStatus('Score: ' + score + ' pts - loading a new puzzle...', '#4a7c59');

            var form = new FormData();
            form.append('csrf_token', csrf);
            form.append('score', score);
            form.append('time_sec', timeSec.toFixed(2));
            fetch('/sudoku.php', {
                method: 'POST',
                body: form
            }).then(function() {
                refreshLeaderboard();
            });

            loadNewPuzzle();
        };
    })();
</script>
<?php end_page(); ?>