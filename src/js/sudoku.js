(function() {
    var grid = document.getElementById('sudokuGrid');
    if (!grid) return; // this page has no sudoku section

    var csrf = APP_DATA.csrf;
    var palette = document.getElementById('sudokuPalette');
    var statusEl = document.getElementById('sudokuStatus');
    var strikesEl = document.getElementById('sudokuStrikes');
    var timerEl = document.getElementById('sudokuTimer');

    // puzzle/solution come from the Dosuku API via PHP, handed off through
    // the JSON island in index.php (see APP_DATA in app.js)
    var puzzle = APP_DATA.puzzle;
    var solution = APP_DATA.solution;

    var startTime = null;
    var timerInterval = null;
    var sudokuStarted = false;
    var selectedCell = null;
    var strikes = 0;
    var loadingNewPuzzle = false;

    function setStatus(msg, isError) {
        statusEl.textContent = msg || '';
        statusEl.classList.toggle('sudoku-status--error', !!isError);
        statusEl.classList.toggle('sudoku-status--ok', !isError && !!msg);
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
            setStatus('Nope, that number is wrong.', true);

            if (strikes >= 3) {
                setStatus('3 strikes - puzzle reset!', true);
                setTimeout(function() {
                    resetSudoku();
                    setStatus('3 strikes - puzzle reset! Try again.', true);
                }, 700);
            }
            return;
        }

        selectedCell.textContent = n;
        setStatus('', false);
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
    function resetSudoku() {
        clearInterval(timerInterval);
        sudokuStarted = false;
        startTime = null;
        strikes = 0;
        strikesEl.textContent = '0';
        timerEl.textContent = '0.0s';
        setStatus('', false);
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
                setStatus('New puzzle loaded', false);
            })
            .catch(function() {
                setStatus('Could not load a new puzzle - hit Reset to try again.', true);
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
            setStatus('Fill in every cell first.', true);
            return;
        }
        if (!allCorrect) {
            setStatus('Not quite - check for mistakes.', true);
            return;
        }

        clearInterval(timerInterval);
        var timeSec = (Date.now() - startTime) / 1000;
        var score = Math.max(1, Math.round(5000 / timeSec));
        setStatus('Score: ' + score + ' pts - loading a new puzzle...', false);

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

    var checkBtn = document.getElementById('checkSudokuBtn');
    var refreshBtn = document.getElementById('refreshSudokuBtn');
    if (checkBtn) checkBtn.addEventListener('click', window.checkSudoku);
    if (refreshBtn) refreshBtn.addEventListener('click', window.loadNewPuzzle);
})();
