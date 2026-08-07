(function() {
    var canvas = document.getElementById('mazeCanvas');
    if (!canvas) return; // this page has no maze section
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
    var csrf = APP_DATA.csrf;

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
        ctx.drawImage(goalImg, goal.c * CELL, goal.r * CELL, CELL, CELL);
        ctx.drawImage(playerImg, player.c * CELL, player.r * CELL, CELL, CELL);
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
            var statusEl = document.getElementById('gameStatus');
            statusEl.textContent = 'Score: ' + score + ' pts ';
            statusEl.classList.add('game-status--win');
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
        var statusEl = document.getElementById('gameStatus');
        statusEl.textContent = 'Press any arrow key to start';
        statusEl.classList.remove('game-status--win');
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

    var newMazeBtn = document.getElementById('newMazeBtn');
    if (newMazeBtn) newMazeBtn.addEventListener('click', resetGame);
})();
