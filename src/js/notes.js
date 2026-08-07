/* =============================================================
   notes.js  —  envelope card notes UI, AJAX add/delete
   Place at:  /src/js/notes.js
   ============================================================= */
(function () {

  /* ── bootstrap from PHP data island ───────────────────────── */
  var island = document.getElementById('notes-data');
  if (!island) return;
  var META = {
    csrf:   island.dataset.csrf,
    notes:  JSON.parse(island.dataset.notes),
    streak: parseInt(island.dataset.streak, 10)
  };

  /* ── DOM refs ─────────────────────────────────────────────── */
  var section     = document.getElementById('notes');
  if (!section) return;
  var streakBadge = section.querySelector('.streak-badge');
  var noteForm    = section.querySelector('.note-form');
  var titleInput  = noteForm.querySelector('input[name="title"]');
  var addBtn      = noteForm.querySelector('button[type="submit"]');
  var listWrap    = section.querySelector('.note-list-wrap');

  /* ── modal overlay (built once, appended to body) ─────────── */
  var overlay = document.createElement('div');
  overlay.className = 'note-overlay';
  overlay.innerHTML =
    '<div class="note-modal">' +
      '<button class="note-modal-close" aria-label="Close">&#x2715;</button>' +
      /* landscape image shown on desktop, portrait on mobile via CSS */
      '<img class="note-modal-img note-modal-img--landscape" src="/images/envelope.jpg" alt="" draggable="false" />' +
      '<img class="note-modal-img note-modal-img--portrait"  src="/images/envelope.jpg" alt="" draggable="false" />' +
      '<div class="note-modal-inner">' +
        '<span class="note-modal-date-left"></span>' +
        '<span class="note-modal-date-right"></span>' +
        '<p class="note-modal-text"></p>' +
      '</div>' +
    '</div>';
  document.body.appendChild(overlay);

  var modalDateL = overlay.querySelector('.note-modal-date-left');
  var modalDateR = overlay.querySelector('.note-modal-date-right');
  var modalText  = overlay.querySelector('.note-modal-text');

  /* open */
  function openModal(note) {
    var d = new Date(note.created_at);
    modalDateL.textContent = fmtDate(d);
    modalDateR.textContent = fmtTime(d);
    modalText.textContent  = note.title;
    overlay.classList.add('note-overlay--open');
    document.body.classList.add('note-modal-open');
  }

  /* close on backdrop click or × button */
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay || e.target.classList.contains('note-modal-close')) {
      overlay.classList.remove('note-overlay--open');
      document.body.classList.remove('note-modal-open');
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      overlay.classList.remove('note-overlay--open');
      document.body.classList.remove('note-modal-open');
    }
  });

  /* ── helpers ──────────────────────────────────────────────── */
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function fmtDate(d) {
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
  }

  function fmtTime(d) {
    var h = d.getHours(), m = d.getMinutes();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + pad(m) + ' ' + ampm;
  }

  function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s)));
    return d.innerHTML;
  }

  function updateStreak(n) {
    if (streakBadge)
      streakBadge.textContent = 'journaling streak: ' + n + ' day' + (n === 1 ? '' : 's');
  }

  /* ── build a single envelope card ────────────────────────── */
  function makeCard(note) {
    var li = document.createElement('li');
    li.className = 'note-env-item';
    li.dataset.id = note.id;

    /* card wrapper */
    var card = document.createElement('div');
    card.className = 'note-env-card';

    /* ornate border image — envelope.png while on the grid */
    var img = document.createElement('img');
    img.className = 'note-env-img';
    img.src = '/images/envelope.png';
    img.alt = '';
    img.draggable = false;

    /* content overlay */
    var content = document.createElement('div');
    content.className = 'note-env-content';

    var dateSpan = document.createElement('span');
    dateSpan.className = 'note-env-date';
    dateSpan.textContent = fmtDate(new Date(note.created_at));

    var delBtn = document.createElement('button');
    delBtn.className = 'note-env-delete';
    delBtn.title = 'Delete note';
    delBtn.textContent = '🗑';
    delBtn.setAttribute('aria-label', 'Delete note');

    content.appendChild(dateSpan);
    content.appendChild(delBtn);

    card.appendChild(img);
    card.appendChild(content);
    li.appendChild(card);

    /* click card → open modal (switched image is envelope.jpg) */
    card.addEventListener('click', function (e) {
      if (e.target === delBtn) return;
      openModal(note);
    });

    /* delete */
    delBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      li.style.opacity = '0.35';
      li.style.pointerEvents = 'none';
      doPost({ csrf_token: META.csrf, action: 'delete', note_id: note.id });
    });

    return li;
  }

  /* ── render full list (newest first) ─────────────────────── */
  function renderList(notes) {
    listWrap.innerHTML = '';
    if (!notes || notes.length === 0) {
      listWrap.innerHTML = '<div class="note-empty">No notes yet.</div>';
      return;
    }
    /* sort descending */
    var sorted = notes.slice().sort(function (a, b) {
      return new Date(b.created_at) - new Date(a.created_at);
    });
    var ul = document.createElement('ul');
    ul.className = 'note-env-list';
    sorted.forEach(function (n) { ul.appendChild(makeCard(n)); });
    listWrap.appendChild(ul);
  }

  /* ── AJAX helper — POST with X-Requested-With header ─────── */
  function doPost(fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });

    return fetch('/index.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) {
          console.warn('Notes error:', data.error);
          return;
        }
        META.notes  = data.notes;
        META.streak = data.streak;
        renderList(META.notes);
        updateStreak(META.streak);
      })
      .catch(function (err) {
        console.warn('Notes fetch failed:', err);
      });
  }

  /* ── intercept add form ───────────────────────────────────── */
  noteForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var title = titleInput.value.trim();
    if (!title) return;
    addBtn.disabled = true;
    doPost({ csrf_token: META.csrf, action: 'add', title: title })
      .finally(function () {
        addBtn.disabled = false;
        titleInput.value = '';
        titleInput.focus();
      });
  });

  /* ── initial render from PHP-injected data ────────────────── */
  renderList(META.notes);
  updateStreak(META.streak);

})();
