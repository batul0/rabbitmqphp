<?php
// --- Auth guard ---
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') { header('Location: index.html'); exit; }

try {
  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
  $res = $client->send_request(['type' => 'validate_session', 'sessionId' => $sid]);
  if (!is_array($res) || empty($res['success'])) {
    setcookie('sid','',time()-3600,'/');
    header('Location: index.html'); exit;
  }
  $username = htmlspecialchars($res['username'] ?? 'Player', ENT_QUOTES, 'UTF-8');
} catch (Throwable $e) {
  header('Location: index.html'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Home • GameHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --bg1: #0f0f14; --bg2: #15151f; --card: #1c1c29; --text: #e8e8ff; --muted: #a9a9c1;
      --accent: #7c4dff; --accent-2: #00ffc6; --chip: #2b2b3b; --sidebar-width: 250px;
    }
    body {
      background: radial-gradient(1200px 600px at 15% 0%, #18182a 0%, var(--bg1) 60%) fixed,
                  linear-gradient(180deg, var(--bg1), var(--bg2)) fixed;
      color: var(--text); min-height: 100vh; padding-bottom: 80px;
    }
    .container-fluid { display: flex; padding: 0; }
    .sidebar {
      width: var(--sidebar-width); background-color: var(--bg2); position: fixed; height: 100vh;
      padding-top: 60px; overflow-y: auto; border-right: 1px solid rgba(124,77,255,0.25);
    }
    .sidebar-nav a {
      display: block; color: var(--muted); text-decoration: none; padding: 12px 16px; margin-bottom: 4px;
      border-radius: 8px; transition: background-color .2s, color .2s;
    }
    .sidebar-nav a:hover { background-color: var(--chip); color: var(--text); }
    .sidebar-nav a.active { background-color: var(--chip); color: var(--text);
      border-left: 3px solid var(--accent-2); font-weight: 600; }
    .main-content { margin-left: var(--sidebar-width); flex: 1; }
    .navbar {
      background: rgba(16,16,26,0.9); backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(124,77,255,0.25); z-index: 1030; padding-left: 35px;
    }
    .brand-text {
      font-weight: 800; letter-spacing: .5px; background: linear-gradient(90deg, var(--accent), var(--accent-2));
      -webkit-background-clip: text; background-clip: text; color: transparent; font-size: 1.5rem;
    }
    .hero { background: linear-gradient(180deg, rgba(124,77,255,.15), transparent);
      border-bottom: 1px solid rgba(124,77,255,0.15); }
    .search-wrap { background:#12121b; border:1px solid rgba(124,77,255,0.25); border-radius:14px; padding:16px; }
    .form-control, .btn { border-radius:10px; }
    .btn-dark { background: linear-gradient(135deg,#2a2a3a,#1b1b29); border:1px solid rgba(124,77,255,0.35); }
    .btn-dark:hover { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(124,77,255,0.25); }
    .game-card { background: var(--card); border:1px solid rgba(124,77,255,0.18); border-radius:16px; overflow:hidden;
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
    .game-card:hover { transform: translateY(-4px); border-color: rgba(0,255,198,0.35);
      box-shadow: 0 10px 24px rgba(0,0,0,.35), 0 0 0 1px rgba(0,255,198,0.2) inset; }
    .game-img { aspect-ratio:16/9; object-fit:cover; background:#0b0b12; }
    .badge-chip { background: var(--chip); border:1px solid rgba(124,77,255,0.35); color: var(--muted); font-weight:600; }
    .rating-badge { background: linear-gradient(180deg,#1f1f2f,#181826); border:1px solid rgba(0,255,198,0.45);
      color: var(--accent-2); font-weight:700; }
    .alert { border-radius:12px; border:1px solid rgba(124,77,255,0.35); background:#141420; color:var(--text); }
    footer { background:#0f0f16; border-top:1px solid rgba(124,77,255,0.2); color:var(--muted); }

    .content-display { opacity:0; transform:scale(0.98); visibility:hidden; transition: opacity .3s, transform .3s, visibility .3s; }
    .content-display.active-section { opacity:1; transform:scale(1); visibility:visible; }

    /* forum styles */
    .comment-section{ background: var(--card); border:1px solid rgba(124,77,255,0.18); border-radius:16px; overflow:hidden;
      margin:20px auto; max-width:650px; box-shadow:0 4px 10px rgba(0,0,0,0.25); }
    .comment-header{ cursor:pointer; background-color: var(--chip); padding:15px; border-bottom:1px solid rgba(124,77,255,0.25);
      display:flex; align-items:center; justify-content:space-between; font-weight:600; }
    .comment-header:hover{ background-color:#2b2b4c; }
    .comment-header h3{ margin:0; color:var(--text); font-size:1.25rem; }
    #forum-comment-container{ padding:15px; max-height:0; overflow:hidden; transition:max-height .3s, padding .3s; }
    #forum-comment-container.is-expanded{ max-height:600px; }
    #forum-comment-list { padding:0; margin:0; }
    .forum-comment{ background: var(--bg2); border-radius:10px; padding:10px 14px; margin-bottom:12px;
      border-left:3px solid var(--accent-2); list-style:none; word-wrap: break-word; }
    .forum-comment .meta{ display:flex; gap:8px; align-items:baseline; margin-bottom:4px; }
    .forum-comment .meta strong{ color: var(--accent-2); font-size:.95rem; }
    .forum-comment .meta small{ color: var(--muted); font-size:.8rem; }
    #forum-comment-form{ margin-top:20px; display:flex; gap:10px; }
    #forum-comment-form textarea{ flex-grow:1; background-color: var(--bg2); border:1px solid rgba(124,77,255,0.35);
      border-radius:10px; padding:10px; color:var(--text); resize:vertical; }
    #forum-comment-form button{ background: linear-gradient(90deg, var(--accent), var(--accent-2)); border:none; color:#0e0e14;
      font-weight:700; border-radius:10px; padding:10px 15px; cursor:pointer; transition: opacity .2s; }
    #forum-comment-form button:hover{ opacity:.9; }
  </style>
</head>
<body>

<nav class="navbar navbar-dark sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="home.php">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
        <path d="M4 12l4-8 4 8-4 8-4-8Zm8 0 4-8 4 8-4 8-4-8Z" stroke="url(#g)" stroke-width="1.5"/>
        <defs><linearGradient id="g" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#7c4dff"/><stop offset="1" stop-color="#00ffc6"/></linearGradient></defs>
      </svg>
      <span class="brand-text">GameHub</span>
    </a>
    <div class="d-flex align-items-center gap-3">
      <span class="text-secondary small d-none d-md-inline">Signed in as</span>
      <span class="fw-bold"><?php echo $username; ?></span>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Logout</a>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <aside class="sidebar">
    <ul class="list-unstyled sidebar-nav">
      <li><a href="#" data-target="home-content" class="active"><i class="bi bi-house-door me-2"></i>Home</a></li>
      <li><a href="#" data-target="reviews-content"><i class="bi bi-star me-2"></i>Reviews</a></li>
      <li><a href="#" data-target="mygames-content"><i class="bi bi-controller me-2"></i>My Games</a></li>
    </ul>
    <div class="p-3 mt-4 text-secondary text-uppercase small fw-bold">Features</div>
    <ul class="list-unstyled sidebar-nav">
      <li><a href="#" data-target="wishlist-content"><i class="bi bi-heart me-2"></i>Wishlist</a></li>
      <li><a href="#" data-target="forum-content"><i class="bi bi-chat-dots me-2"></i>Forum</a></li>
      <li><a href="#" data-target="recommendations-content"><i class="bi bi-lightbulb me-2"></i>Recommendations</a></li>
      <li><a href="#" data-target="notifications-content"><i class="bi bi-bell me-2"></i>Notifications</a></li>
    </ul>
  </aside>
</div>

<main class="main-content">
  <!-- Home -->
  <section id="home-content" class="content-display active-section">
    <section class="hero py-5 text-center">
      <div class="container">
        <h1 class="display-5 fw-bold mb-2">Find your next adventure</h1>
        <p class="lead text-secondary mb-0">Search the library and explore what everyone’s playing.</p>
      </div>
    </section>

    <div class="container my-4">
      <div class="search-wrap mb-4">
        <div class="row g-2">
          <div class="col-12 col-lg-9">
            <input id="q" type="text" class="form-control form-control-lg" placeholder="Search games (e.g., Elden Ring, Hades, GTA V)">
          </div>
          <div class="col-12 col-lg-3 d-grid">
            <button id="searchBtn" class="btn btn-dark btn-lg">Search</button>
          </div>
        </div>
      </div>
      <div id="status" class="alert d-none">Loading…</div>
      <div id="results" class="row g-4"></div>
      <div class="mt-4 text-center">
        <button id="loadMoreBtn" class="btn btn-dark btn-lg d-none">Load more</button>
      </div>
    </div>
  </section>

  <!-- Reviews -->
  <section id="reviews-content" class="content-display">
    <div class="container my-4">
      <h2>Reviews</h2>
      <p class="lead text-secondary">Your latest reviews here.</p>
    </div>
  </section>

  <!-- My Games -->
  <section id="mygames-content" class="content-display">
    <div class="container my-4">
      <h2>My Games</h2>
      <p class="lead text-secondary">Your played or liked games will appear here.</p>
    </div>
  </section>

  <!-- Wishlist -->
  <section id="wishlist-content" class="content-display">
    <div class="container my-4">
      <h2>Wishlist</h2>
      <p class="lead text-secondary">Games you want to play.</p>
    </div>
  </section>

  <!-- Forum -->
  <section id="forum-content" class="content-display">
    <div class="container my-4">
      <h2 class="mb-4">Message Forum</h2>

      <div class="comment-section">
        <div class="comment-header" id="forum-comment-header">
          <h3 class="mb-0">Message Board</h3>
          <span class="text-secondary small">click to toggle</span>
        </div>
        <div id="forum-comment-container">
          <ul id="forum-comment-list"></ul>
          <form id="forum-comment-form">
            <textarea id="forum-comment-text" placeholder="Write a message..."></textarea>
            <button type="submit">Post</button>
          </form>
        </div>
      </div>
    </div>
  </section>

  <!-- Recommendations -->
  <section id="recommendations-content" class="content-display">
    <div class="container my-4">
      <h2>Recommendations</h2>
      <p class="lead text-secondary">Personalized game suggestions.</p>
    </div>
  </section>

  <!-- Notifications -->
  <section id="notifications-content" class="content-display">
    <div class="container my-4">
      <h2>Notifications</h2>
      <p class="lead text-secondary">All your recent activity and alerts.</p>
    </div>
  </section>
</main>

<!-- Game Details Modal -->
<div class="modal fade" id="gameDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="background:#12121b;color:#e8e8ff;border:1px solid rgba(124,77,255,.25)">
      <div class="modal-header">
        <h5 class="modal-title" id="gdmTitle">Game Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="gdmBody">Loading…</div>
      </div>
    </div>
  </div>
</div>

<footer class="text-center py-3 mt-4 fixed-bottom">
  <div class="container">
    <small>&copy; 2025 GameHub • All Rights Reserved</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Search + Recent feed with "Load more" pagination and Details modal
(function(){
  const results = document.getElementById('results');
  const status  = document.getElementById('status');
  const qInput  = document.getElementById('q');
  const btn     = document.getElementById('searchBtn');
  const loadMoreBtn = document.getElementById('loadMoreBtn');

  let page = 1;
  const pageSize = 9;
  let totalPages = 1;
  let currentScope = 'recent';

  function setStatus(text, type) {
    status.className = 'alert';
    status.classList.add(type ? `alert-${type}` : 'alert-info');
    status.innerHTML = text;
    status.classList.remove('d-none');
  }
  function clearStatus(){ status.classList.add('d-none'); }

  function triggerScopeAndFetch(resetToFirst = true){
    const q = qInput.value.trim();
    currentScope = (q === '') ? 'recent' : 'search';
    if (resetToFirst) page = 1;
    fetchGames(currentScope, { append: false });
  }

  function fetchGames(scope, { append = false } = {}){
    if (scope) currentScope = scope;

    if (!append) {
      setStatus('Loading games…', null);
      results.innerHTML = '';
    }
    loadMoreBtn.classList.add('d-none');

    const q = qInput.value.trim();
    const body = new URLSearchParams();
    body.append('page', String(page));
    body.append('pageSize', String(pageSize));
    if (currentScope === 'search' && q) {
      body.append('scope','search');
      body.append('query', q);
    } else {
      body.append('scope','recent');
    }

    fetch('games.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
      if (!data || data.success === false) {
        setStatus(data?.message || 'Failed to load games', 'danger');
        return;
      }
      clearStatus();

      totalPages = Number(data.totalPages || 1);
      renderGames(data.items || [], { append });

      const canLoadMore = (currentScope === 'recent') && (page < totalPages);
      loadMoreBtn.classList.toggle('d-none', !canLoadMore);
    })
    .catch(err => {
      console.error(err);
      setStatus('Network error loading games', 'danger');
    });
  }

  function renderGames(items, { append = false } = {}){
    if (!items.length && !append) {
      results.innerHTML = '<div class="col-12"><div class="alert">No games found.</div></div>';
      return;
    }
    const toName = (x)=> (typeof x === 'string' ? x : (x?.name ?? ''));
    const cards = items.map(g => {
      const id   = g.id ?? g.rawg_id ?? '';
      const img  = g.background_image || g.image || '';
      const name = g.name || 'Untitled';
      const rating = (g.rating != null) ? Number(g.rating).toFixed(1) : null;
      const released = g.released ? new Date(g.released).toLocaleDateString() : 'Unknown';
      const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      const genres = (g.genres || []).slice(0,3).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');

      return `
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="game-card h-100">
            ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
            <div class="p-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-0">${name}</h5>
                ${rating !== null ? `<span class="badge rating-badge ms-2">${rating}</span>` : ''}
              </div>
              <div class="text-secondary small mb-2">Released: ${released}</div>
              ${platforms ? `<div class="mb-2">${platforms}</div>` : ''}
              ${genres ? `<div class="mb-2">${genres}</div>` : ''}
              <a href="#" class="btn btn-sm btn-light" data-action="details" data-id="${id}">Details</a>
            </div>
          </div>
        </div>`;
    }).join('');

    if (append) {
      const temp = document.createElement('div');
      temp.innerHTML = cards;
      [...temp.children].forEach(c => results.appendChild(c));
    } else {
      results.innerHTML = cards;
    }

    // bind Details buttons
    results.querySelectorAll('[data-action="details"]').forEach(el=>{
      el.onclick = (e)=>{
        e.preventDefault();
        const id = parseInt(el.dataset.id || '0', 10);
        if (!id) return;
        openDetails(id);
      };
    });
  }

  // --- FIXED: explicit ./game.php URL + robust error surfacing ---
  window.openDetails = function(id){
    const detailsModalEl = document.getElementById('gameDetailsModal');
    const detailsTitleEl = document.getElementById('gdmTitle');
    const detailsBodyEl  = document.getElementById('gdmBody');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
    if (!detailsModal) return;

    detailsTitleEl.textContent = 'Game Details';
    detailsBodyEl.innerHTML = 'Loading…';
    detailsModal.show();

    const body = new URLSearchParams();
    body.append('id', String(id));

    fetch('./game.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    })
    .then(async (r) => {
      const text = await r.text();
      try { return { ok: r.ok, json: JSON.parse(text), text }; }
      catch { return { ok: r.ok, json: null, text }; }
    })
    .then(({ok, json, text})=>{
      if (!ok) {
        console.error('game.php HTTP error:', text);
        detailsBodyEl.innerHTML = `<div class="alert alert-danger">Endpoint error.<br><pre style="white-space:pre-wrap">${text}</pre></div>`;
        return;
      }
      if (!json || json.success === false) {
        const msg = json?.message || 'Unknown error';
        console.error('game.php app error:', msg, 'raw:', text);
        detailsBodyEl.innerHTML = `<div class="alert alert-danger">Failed to load details: ${msg}</div>`;
        return;
      }
      const g = (typeof json.item === 'string') ? JSON.parse(json.item) : (json.item || {});
      if (!g || !g.rawg_id) {
        detailsBodyEl.innerHTML = `<div class="alert alert-warning">No details found for this game.</div>`;
        return;
      }

      const pill = (arr)=> (arr && arr.length)
        ? `<div class="mb-2">${arr.map(x=>`<span class="badge badge-chip me-1 mb-1">${x}</span>`).join('')}</div>`
        : '';

      const hero = g.background_image_additional || g.background_image || '';
      const infoRows = [
        ['Released', g.released || 'Unknown'],
        ['Rating', (g.rating!=null ? Number(g.rating).toFixed(1) : '—')],
        ['Metacritic', (g.metacritic!=null ? g.metacritic : '—')],
        ['Playtime', (g.playtime!=null ? g.playtime+'h' : '—')],
        ['ESRB', g.esrb_rating || '—'],
        ['Website', g.website ? `<a href="${g.website}" target="_blank" rel="noopener">Visit</a>` : '—'],
        ['Reddit', g.reddit_url ? `<a href="${g.reddit_url}" target="_blank" rel="noopener">Community</a>` : '—'],
      ].map(([k,v])=>`<div class="d-flex justify-content-between border-bottom border-1 border-opacity-25 py-2"><div class="text-secondary">${k}</div><div>${v}</div></div>`).join('');

      const shots = (g.screenshots||[]).slice(0,6).map(u=>`
        <div class="col-6 col-md-4 mb-3">
          <img src="${u}" class="w-100 rounded-3" style="border:1px solid rgba(124,77,255,.25)" alt="">
        </div>`).join('');

      const desc = (g.description || g.description_raw || '').trim();
      const descHtml = g.description ? g.description : (desc ? `<p>${desc.replace(/\n/g,'<br>')}</p>` : '<p>No description available.</p>');

      document.getElementById('gdmTitle').textContent = g.name || 'Game Details';
      document.getElementById('gdmBody').innerHTML = `
        ${hero ? `<img src="${hero}" class="w-100 mb-3 rounded-3" style="border:1px solid rgba(124,77,255,.25)" alt="">` : ''}

        ${pill(g.platforms)}
        ${pill(g.genres)}
        ${pill(g.tags)}
        ${pill(g.stores)}
        ${pill(g.developers)}
        ${pill(g.publishers)}

        <div class="row g-3">
          <div class="col-lg-8">
            <h6 class="mb-2">About</h6>
            <div class="mb-3" style="line-height:1.6">${descHtml}</div>
            ${shots ? `<h6 class="mb-2">Screenshots</h6><div class="row">${shots}</div>` : ''}
          </div>
          <div class="col-lg-4">
            <h6 class="mb-2">Info</h6>
            ${infoRows}
          </div>
        </div>
      `;
    })
    .catch(err=>{
      console.error('fetch game.php failed:', err);
      document.getElementById('gdmBody').innerHTML = `<div class="alert alert-danger">Network error.</div>`;
    });
  }

  // Events
  btn.addEventListener('click', e => { e.preventDefault(); triggerScopeAndFetch(true); });
  qInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); triggerScopeAndFetch(true); } });
  loadMoreBtn.addEventListener('click', () => {
    if (page < totalPages) { page += 1; fetchGames(currentScope, { append: true }); }
  });

  // Initial load
  triggerScopeAndFetch(true);

  // Expose for sidebar when returning to Home tab
  window.fetchGames = () => triggerScopeAndFetch(false);
})();
</script>

<script>
// sidebar functionality
document.addEventListener('DOMContentLoaded', () => {
  const links = document.querySelectorAll('.sidebar-nav a');
  const sections = document.querySelectorAll('.content-display');
  const homeSectionId = 'home-content';
  const fetchGames = window.fetchGames;

  function showSection(targetId) {
    sections.forEach(sec => sec.classList.remove('active-section'));
    links.forEach(link => link.classList.remove('active'));

    const targetSection = document.getElementById(targetId);
    const activeLink = document.querySelector(`.sidebar-nav a[data-target="${targetId}"]`);

    if (targetSection) targetSection.classList.add('active-section');
    if (activeLink) activeLink.classList.add('active');

    localStorage.setItem('activeSection', targetId);
    if (targetId === homeSectionId && typeof fetchGames === 'function') fetchGames();
  }

  links.forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const targetId = link.dataset.target;
      if (targetId) showSection(targetId);
    });
  });

  const saved = localStorage.getItem('activeSection');
  if (saved && document.getElementById(saved)) showSection(saved);
  else showSection(homeSectionId);
});
</script>

<script>
// forum comments (local only)
document.addEventListener('DOMContentLoaded', () => {
  const header = document.getElementById('forum-comment-header');
  const container = document.getElementById('forum-comment-container');
  const form = document.getElementById('forum-comment-form');
  const list = document.getElementById('forum-comment-list');
  const text = document.getElementById('forum-comment-text');
  const currentUser = "<?php echo $username; ?>";
  const LS_KEY = `forumComments_${currentUser}`;

  function escapeHtml(s) { return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function loadComments() { try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); } catch { return []; } }
  function saveComments(arr) { localStorage.setItem(LS_KEY, JSON.stringify(arr)); }

  let comments = loadComments();
  function render() {
    if (!list) return;
    list.innerHTML = comments.map(c => `
      <li class="forum-comment">
        <div class="meta"><strong>${escapeHtml(c.user)}</strong><small>${escapeHtml(c.time)}</small></div>
        <div>${escapeHtml(c.text)}</div>
      </li>`).join('');
  }

  header?.addEventListener('click', () => { container.classList.toggle('is-expanded'); });
  form?.addEventListener('submit', e => {
    e.preventDefault();
    const val = (text?.value || '').trim(); if (!val) return;
    const now = new Date();
    const ts = now.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
    comments.push({ user: currentUser, time: ts, text: val });
    saveComments(comments); render(); text.value = '';
  });
  render();
});
</script>
</body>
</html>
