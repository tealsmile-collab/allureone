<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$pageTitle = 'SOP';
$activeNav = 'sop';
require __DIR__ . '/includes/layout_start.php';
?>
<style>
.sop {
  display: grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  gap: 1rem;
  min-height: calc(100vh - 7.5rem);
  user-select: none;
  -webkit-user-select: none;
}
.sop-index, .sop-view {
  background: #fff;
  border: 1px solid #d7e1ec;
  border-radius: 12px;
  min-width: 0;
}
.sop-index {
  padding: 0.75rem 0.4rem;
  overflow: auto;
  max-height: calc(100vh - 7.5rem);
}
.sop-index h2 {
  margin: 0.2rem 0.7rem 0.6rem;
  font-size: 1rem;
}
.sop-chapter, .sop-section {
  display: block;
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  color: #12263a;
  cursor: pointer;
  border-radius: 8px;
}
.sop-chapter {
  padding: 0.45rem 0.7rem;
  font-weight: 700;
}
.sop-section {
  padding: 0.28rem 0.7rem 0.28rem 1.3rem;
  font-size: 0.88rem;
  color: #344054;
}
.sop-chapter.is-active, .sop-section.is-active, .sop-chapter:hover, .sop-section:hover {
  background: #f4f8fc;
}
.sop-chapter:disabled, .sop-section:disabled {
  color: #98a2b3;
  cursor: default;
}
.sop-view {
  display: flex;
  flex-direction: column;
  position: relative;
}
.sop-stage {
  position: relative;
  flex: 1;
  min-height: 420px;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 1rem;
  background: #eef3f8;
  overflow: auto;
}
.sop-sheet-wrap {
  flex: 0 0 auto;
  width: 100%;
}
.sop-stage.is-zoomed { justify-content: flex-start; }
.sop-stage img {
  width: 100%;
  max-width: none;
  height: auto;
  background: #fff;
  box-shadow: 0 8px 24px rgba(16, 24, 40, 0.12);
  pointer-events: none;
}
.sop-zoom {
  position: absolute;
  right: 0.65rem;
  z-index: 3;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.sop-zoom--top { top: 0.65rem; }
.sop-zoom--bottom { bottom: 4.25rem; }
.sop-zoom button {
  width: 36px;
  height: 36px;
  padding: 0;
  border: 1px solid #c5d0dc;
  border-radius: 8px;
  background: #fff;
  color: #12263a;
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(16, 24, 40, 0.12);
}
.sop-zoom button:disabled {
  color: #98a2b3;
  cursor: default;
}
.sop-stage.is-shield img { filter: blur(16px); }
.sop-nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 0.75rem;
  border-top: 1px solid #e6edf5;
}
.sop-count { min-width: 5rem; text-align: center; font-weight: 600; }
.sop-loader {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.72);
  z-index: 2;
}
.sop-loader[hidden] { display: none !important; }
.sop-loader__spin {
  width: 36px;
  height: 36px;
  border: 3px solid #d7e1ec;
  border-top-color: #244a73;
  border-radius: 50%;
  animation: sop-spin 0.7s linear infinite;
}
@keyframes sop-spin { to { transform: rotate(360deg); } }
.sop-note { margin: 1rem; color: #475467; }
@media (max-width: 800px) {
  .sop { grid-template-columns: 1fr; }
  .sop-index { max-height: 240px; }
}
</style>

<div class="sop" id="sopApp">
    <aside class="sop-index">
        <h2>SOP Index</h2>
        <div id="sopIndex"></div>
    </aside>
    <section class="sop-view">
        <div class="sop-stage" id="sopStage">
            <div class="sop-loader" id="sopLoader" hidden>
                <div class="sop-loader__spin" aria-label="Loading"></div>
            </div>
            <div class="sop-zoom sop-zoom--top">
                <button type="button" data-zoom="in" aria-label="Zoom in">+</button>
                <button type="button" data-zoom="out" aria-label="Zoom out">−</button>
            </div>
            <div class="sop-zoom sop-zoom--bottom">
                <button type="button" data-zoom="in" aria-label="Zoom in">+</button>
                <button type="button" data-zoom="out" aria-label="Zoom out">−</button>
            </div>
            <div class="sop-sheet-wrap" id="sopWrap">
                <img id="sopSheet" alt="" draggable="false" hidden>
            </div>
            <p class="sop-note" id="sopNote" hidden></p>
        </div>
        <div class="sop-nav">
            <button type="button" class="btn btn--ghost" id="sopPrev">Previous</button>
            <span class="sop-count" id="sopCount"></span>
            <button type="button" class="btn btn--primary" id="sopNext">Next</button>
        </div>
    </section>
</div>

<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>;
  var indexEl = document.getElementById('sopIndex');
  var sheet = document.getElementById('sopSheet');
  var wrap = document.getElementById('sopWrap');
  var note = document.getElementById('sopNote');
  var loader = document.getElementById('sopLoader');
  var stage = document.getElementById('sopStage');
  var prevBtn = document.getElementById('sopPrev');
  var nextBtn = document.getElementById('sopNext');
  var countEl = document.getElementById('sopCount');
  var state = { items: [], id: '', page: 1, pages: 0, busy: 0, zoom: 100 };
  function applyZoom() {
    wrap.style.width = state.zoom + '%';
    stage.classList.toggle('is-zoomed', state.zoom > 100);
    stage.querySelectorAll('[data-zoom="in"]').forEach(function (btn) { btn.disabled = state.zoom >= 200; });
    stage.querySelectorAll('[data-zoom="out"]').forEach(function (btn) { btn.disabled = state.zoom <= 50; });
  }
  stage.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-zoom]') : null;
    if (!btn || btn.disabled) return;
    var next = state.zoom + (btn.getAttribute('data-zoom') === 'in' ? 25 : -25);
    state.zoom = Math.max(50, Math.min(200, next));
    applyZoom();
  });
  applyZoom();

  function blockEvent(ev) { ev.preventDefault(); }
  ['copy', 'cut', 'paste', 'contextmenu', 'dragstart', 'selectstart'].forEach(function (name) {
    document.getElementById('sopApp').addEventListener(name, blockEvent);
  });
  document.addEventListener('keydown', function (ev) {
    var key = (ev.key || '').toLowerCase();
    if ((ev.ctrlKey || ev.metaKey) && ['c', 'x', 'v', 's', 'p', 'u', 'a'].indexOf(key) !== -1) {
      ev.preventDefault();
    }
    if (key === 'printscreen') {
      ev.preventDefault();
      shield(true);
    }
  });
  function shield(on) { stage.classList.toggle('is-shield', on); }
  document.addEventListener('visibilitychange', function () { shield(document.hidden); });
  window.addEventListener('blur', function () { shield(true); });
  window.addEventListener('focus', function () { if (!document.hidden) shield(false); });

  function setBusy(on) {
    state.busy += on ? 1 : -1;
    if (state.busy < 0) state.busy = 0;
    loader.hidden = state.busy === 0;
  }
  function post(body) {
    setBusy(true);
    body._csrf = csrf;
    return fetch('configdatapage.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.ok) throw new Error((data && data.error) || 'Could not load this page.');
        return data;
      });
    }).finally(function () { setBusy(false); });
  }
  function paintNav() {
    countEl.textContent = state.pages > 0 ? (state.page + ' / ' + state.pages) : '';
    prevBtn.disabled = state.pages < 1 || state.page <= 1;
    nextBtn.disabled = state.pages < 1 || state.page >= state.pages;
  }
  function markActive() {
    indexEl.querySelectorAll('[data-id]').forEach(function (btn) {
      var same = btn.getAttribute('data-id') === state.id;
      var page = parseInt(btn.getAttribute('data-page') || '1', 10);
      var isSection = btn.classList.contains('sop-section');
      btn.classList.toggle('is-active', same && (!isSection || page === state.page));
    });
  }
  function showNote(text) {
    sheet.removeAttribute('src');
    sheet.hidden = true;
    note.hidden = false;
    note.textContent = text;
    state.pages = 0;
    paintNav();
  }
  function openPage(id, page) {
    var item = state.items.filter(function (row) { return row.id === id; })[0];
    if (!item || item.pages < 1) {
      state.id = id;
      showNote('This chapter is not available yet.');
      markActive();
      return;
    }
    var n = Math.min(Math.max(page || 1, 1), item.pages);
    post({ k: id, n: n }).then(function (data) {
      state.id = id;
      state.page = data.n;
      state.pages = data.m;
      note.hidden = true;
      sheet.hidden = false;
      sheet.src = 'data:image/png;base64,' + data.d;
      paintNav();
      markActive();
    }).catch(function (err) {
      showNote(err.message || 'Could not load this page.');
    });
  }
  function renderIndex(items) {
    indexEl.innerHTML = '';
    items.forEach(function (item) {
      var chapter = document.createElement('button');
      chapter.type = 'button';
      chapter.className = 'sop-chapter';
      chapter.textContent = item.title;
      chapter.setAttribute('data-id', item.id);
      chapter.setAttribute('data-page', '1');
      if (item.pages < 1) chapter.disabled = false;
      chapter.addEventListener('click', function () { openPage(item.id, 1); });
      indexEl.appendChild(chapter);
      (item.sections || []).forEach(function (section) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'sop-section';
        btn.textContent = section.n + ' ' + section.title;
        btn.setAttribute('data-id', item.id);
        btn.setAttribute('data-page', String(section.page || 1));
        btn.disabled = item.pages < 1;
        btn.addEventListener('click', function () { openPage(item.id, section.page || 1); });
        indexEl.appendChild(btn);
      });
    });
  }

  prevBtn.addEventListener('click', function () { if (state.page > 1) openPage(state.id, state.page - 1); });
  nextBtn.addEventListener('click', function () { if (state.page < state.pages) openPage(state.id, state.page + 1); });

  post({ k: 'list' }).then(function (data) {
    state.items = data.items || [];
    renderIndex(state.items);
    var first = state.items.filter(function (row) { return row.pages > 0; })[0];
    if (first) openPage(first.id, 1);
    else showNote('No SOP chapters are available.');
  }).catch(function (err) {
    showNote(err.message || 'Could not load the index.');
  });
})();
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
