// ---- shared app data, injected by index.php as data-* attributes ----
// (data attributes are never <script> elements, so CSP's script-src
// directive has nothing to evaluate here at all - unlike a
// <script type="application/json"> block, which IS still subject to
// CSP's inline-script check even though it's never executed as JS)
var appDataEl = document.getElementById('app-data');
var APP_DATA = {
    csrf: appDataEl.dataset.csrf,
    puzzle: JSON.parse(appDataEl.dataset.puzzle),
    solution: JSON.parse(appDataEl.dataset.solution)
};

// ---- chat: scroll to bottom ----
document.addEventListener('DOMContentLoaded', function() {
    var c = document.getElementById('chatMessages');
    if (c) c.scrollTop = c.scrollHeight;
});

// ---- leaderboard: tab toggle (kept for when both boards are shown) ----
function showBoard(game) {
    var mazeBoard = document.querySelector('.board-maze');
    var sudokuBoard = document.querySelector('.board-sudoku');
    var tabMaze = document.getElementById('tabMaze');
    var tabSudoku = document.getElementById('tabSudoku');
    if (mazeBoard) mazeBoard.style.display = game === 'maze' ? '' : 'none';
    if (sudokuBoard) sudokuBoard.style.display = game === 'sudoku' ? '' : 'none';
    if (tabMaze) tabMaze.classList.toggle('is-active', game === 'maze');
    if (tabSudoku) tabSudoku.classList.toggle('is-active', game === 'sudoku');
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

// ---- leaderboard tab buttons, if present on the page ----
document.addEventListener('DOMContentLoaded', function() {
    var tabMaze = document.getElementById('tabMaze');
    var tabSudoku = document.getElementById('tabSudoku');
    if (tabMaze) tabMaze.addEventListener('click', function(e) {
        e.preventDefault();
        showBoard('maze');
    });
    if (tabSudoku) tabSudoku.addEventListener('click', function(e) {
        e.preventDefault();
        showBoard('sudoku');
    });
});
