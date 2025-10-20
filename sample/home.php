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
      --star-size: 28px; --star-off: #3c4159; --star-glow: rgba(255, 213, 74, .45);
    }
    body {
      background: radial-gradient(1200px 600px at 15% 0%, #18182a 0%, var(--bg1) 60%) fixed,
                  linear-gradient(180deg, var(--bg1), var(--bg2)) fixed;
      color: var(--text);
      min-height: 100vh;
      padding-bottom: 24px;
    }
    .container-fluid { display: flex; padding: 0; }
    .sidebar {
      width: var(--sidebar-width);
      background-color: var(--bg2);
      position: fixed;
      height: 100vh;
      padding-top: 60px;
      overflow-y: auto;
      border-right: 1px solid rgba(124,77,255,0.25);
    }
    .sidebar-nav a, .sidebar-nav button {
      display: block; width: 100%; text-align: left;
      color: var(--muted); text-decoration: none; padding: 12px 16px; margin-bottom: 4px;
      border-radius: 8px; transition: background-color .2s, color .2s;
      background: transparent; border: none;
    }
    .sidebar-nav a:hover, .sidebar-nav button:hover { background-color: var(--chip); color: var(--text); }
    .sidebar-nav a.active {
      background-color: var(--chip); color: var(--text);
      border-left: 3px solid var(--accent-2); font-weight: 600;
    }
    .submenu { margin: 4px 0 8px 8px; padding-left: 8px; border-left: 2px solid rgba(124,77,255,0.2); }
    .submenu a { padding: 10px 12px; margin-bottom: 4px; }
    .submenu a.active { border-left: 3px solid var(--accent-2); }

    .main-content { margin-left: var(--sidebar-width); flex: 1; }
    .navbar {
      background: rgba(16,16,26,0.9); backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(124,77,255,0.25); z-index: 1030; padding-left: 35px;
    }
    .brand-text {
      font-weight: 800; letter-spacing: .5px; background: linear-gradient(90deg, var(--accent), var(--accent-2));
      -webkit-background-clip: text; background-clip: text; color: transparent; font-size: 1.5rem;
    }
    .hero { background: linear-gradient(180deg, rgba(124,77,255,.15), transparent); border-bottom: 1px solid rgba(124,77,255,0.15); }
    .search-wrap { background:#12121b; border:1px solid rgba(124,77,255,0.25); border-radius:14px; padding:16px; }
    .form-control, .btn { border-radius:10px; }
    .btn-dark { background: linear-gradient(135deg,#2a2a3a,#1b1b29); border:1px solid rgba(124,77,255,0.35); }
    .btn-dark:hover { border-color: var(--accent); box-shadow: 0 0 0 .2rem rgba(124,77,255,0.25); }
    .game-card {
      background: var(--card); border:1px solid rgba(124,77,255,0.18); border-radius:16px; overflow:hidden;
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .game-card:hover {
      transform: translateY(-4px);
      border-color: rgba(0,255,198,0.35);
      box-shadow: 0 10px 24px rgba(0,0,0,.35), 0 0 0 1px rgba(0,255,198,0.2) inset;
    }
    .game-img { aspect-ratio:16/9; object-fit:cover; background:#0b0b12; }
    .badge-chip { background: var(--chip); border:1px solid rgba(124,77,255,0.35); color: var(--muted); font-weight:600; }
    .rating-badge { background: linear-gradient(180deg,#1f1f2f,#181826); border:1px solid rgba(0,255,198,0.45); color: var(--accent-2); font-weight:700; }
    .alert { border-radius:12px; border:1px solid rgba(124,77,255,0.35); background:#141420; color:var(--text); }

    /* Hide inactive sections */
    .content-display { display: none; }
    .content-display.active-section { display: block; }

    .detail-actions { display:flex; gap:.5rem; flex-wrap:wrap; margin:.5rem 0 1rem 0; }
    .detail-actions .btn { border-radius:10px; min-width:110px; font-weight:600; }

    /* ===== TRUE half-star fill with glow ===== */
    .stars { display:inline-flex; position:relative; line-height:1; cursor:pointer; gap: 2px; }
    .stars button {
      width: var(--star-size); height: var(--star-size);
      background: none; border: 0; padding: 0; margin: 0; position: relative;
    }
    .star-ico.base {
      width:100%; height:100%; display:block; color: var(--star-off);
      filter: drop-shadow(0 0 0 rgba(0,0,0,0));
      transition: filter .08s ease;
    }
    /* Yellow fill sits ON TOP, clipped to a star shape (no small star icon) */
    .stars .fill {
      position:absolute; inset:0;
      background: linear-gradient(90deg, #ffd54a, #ffeb99);
      width:0%;
      box-shadow: 0 0 10px var(--star-glow), 0 0 16px var(--star-glow);
      border-radius: 2px;
      transition: width .06s linear;
      /* clip to star shape */
      mask: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" d="M12 17.27L18.18 21 16.54 13.97 22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>') no-repeat center/contain;
      -webkit-mask: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" d="M12 17.27L18.18 21 16.54 13.97 22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>') no-repeat center/contain;
    }
    .stars:hover .star-ico.base { filter: drop-shadow(0 0 4px var(--star-glow)); }
  </style>
</head>
<body>

<nav class="navbar navbar-dark sticky-top">
  <div class="container">
    <a id="brandLink" class="navbar-brand d-flex align-items-center gap-2" href="home.php?section=home">
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
    <ul class="list-unstyled sidebar-nav" id="sidebarNav">
      <li><a href="home.php?section=home" data-target="home-content" class="active"><i class="bi bi-house-door me-2"></i>Home</a></li>
      <li>
        <button class="d-flex align-items-center justify-content-between" type="button"
                data-bs-toggle="collapse" data-bs-target="#myGamesMenu" aria-expanded="false" aria-controls="myGamesMenu">
          <span><i class="bi bi-controller me-2"></i>My Games</span>
          <i class="bi bi-caret-down-fill"></i>
        </button>
        <div class="collapse submenu" id="myGamesMenu">
          <a href="#" data-target="liked-content"><i class="bi bi-heart-fill me-2"></i>Liked Games</a>
          <a href="#" data-target="wishlist-content"><i class="bi bi-bookmark-heart me-2"></i>Wishlist</a>
          <a href="#" data-target="played-content"><i class="bi bi-check2-circle me-2"></i>Played Games</a>
        </div>
      </li>
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

  <!-- Liked -->
  <section id="liked-content" class="content-display">
    <div class="container my-4">
      <h2>Liked Games</h2>
      <div id="likedStatus" class="alert d-none">Loading…</div>
      <div id="likedList" class="row g-4">
        <div class="col-12"><div class="alert">No liked games yet.</div></div>
      </div>
    </div>
  </section>

  <!-- Wishlist -->
  <section id="wishlist-content" class="content-display">
    <div class="container my-4">
      <h2>Wishlist</h2>
      <div id="wishlistStatus" class="alert d-none">Loading…</div>
      <div id="wishlistList" class="row g-4">
        <div class="col-12"><div class="alert">No wishlist items yet.</div></div>
      </div>
    </div>
  </section>

  <!-- Played -->
  <section id="played-content" class="content-display">
    <div class="container my-4">
      <h2>Played Games</h2>
      <div id="playedStatus" class="alert d-none">Loading…</div>
      <div id="playedList" class="row g-4">
        <div class="col-12"><div class="alert">No played games yet.</div></div>
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

<footer class="text-center py-3 mt-4">
  <div class="container">
    <small>&copy; 2025 GameHub • All Rights Reserved</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* ===========================
   Search + Recent feed
   =========================== */
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
  let isFetching = false;

  function setStatus(text, type) {
    status.className = 'alert';
    status.classList.add(type ? `alert-${type}` : 'alert-info');
    status.innerHTML = text;
    status.classList.remove('d-none');
  }
  function clearStatus(){ status.classList.add('d-none'); }

  async function requestJSONOnce(bodyParams){
    const url = 'games.php?_=' + Date.now();
    const body = new URLSearchParams(bodyParams).toString();
    const resp = await fetch(url, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body });
    const raw = await resp.text();
    try { return { ok: resp.ok, json: JSON.parse(raw), raw }; }
    catch { return { ok: resp.ok, json: null, raw }; }
  }
  async function requestJSONWithRetry(bodyParams){
    let r = await requestJSONOnce(bodyParams);
    const needsRetry = (!r.json) ||
                       (r.json && r.json.success === false && (r.json.hint === 'rpc_timeout' || /timeout/i.test(r.json.message||'')));
    if (needsRetry) {
      await new Promise(res => setTimeout(res, 700));
      r = await requestJSONOnce(bodyParams);
    }
    return r;
  }

  function triggerScopeAndFetch(resetToFirst = true){
    if (isFetching) return;
    const q = qInput.value.trim();
    currentScope = (q === '') ? 'recent' : 'search';
    if (resetToFirst) page = 1;
    fetchGames(currentScope, { append: false });
  }

  function prefRating(g) {
    if (g.user_rating != null && g.user_rating !== '') return Number(g.user_rating);
    if (g.userRating != null && g.userRating !== '') return Number(g.userRating);
    if (g.rating != null && g.rating !== '') return Number(g.rating);
    return null;
  }
  const toName = (x)=> (typeof x === 'string' ? x : (x?.name ?? ''));

  async function fetchGames(scope, { append = false } = {}){
    if (isFetching) return;
    if (scope) currentScope = scope;
    isFetching = true;

    if (!append) {
      setStatus('Loading games…', null);
      results.innerHTML = '';
    }
    loadMoreBtn.classList.add('d-none');

    const q = qInput.value.trim();
    const params = { page: String(page), pageSize: String(pageSize) };
    if (currentScope === 'search' && q) { params.scope = 'search'; params.query = q; }
    else { params.scope = 'recent'; params.query = ''; }

    try {
      const { ok, json, raw } = await requestJSONWithRetry(params);
      if (!ok || !json) { console.error('games.php non-JSON/HTTP error:\n', raw); setStatus('Failed to load games (temporary server hiccup).', 'danger'); return; }
      if (json.success === false) { setStatus(json.message || 'Failed to load games', 'danger'); return; }

      clearStatus();
      totalPages = Number(json.totalPages || 1);
      renderGames(json.items || [], { append });

      const canLoadMore = (currentScope === 'recent') && (page < totalPages);
      loadMoreBtn.classList.toggle('d-none', !canLoadMore);
    } catch (err) {
      console.error(err); setStatus('Network error loading games', 'danger');
    } finally {
      isFetching = false;
    }
  }

  function renderGames(items, { append = false } = {}){
    if (!items.length && !append) {
      results.innerHTML = '<div class="col-12"><div class="alert">No games found.</div></div>';
      return;
    }

    const cards = items.map(g => {
      const id   = g.id ?? g.rawg_id ?? '';
      const img  = g.background_image || g.image || '';
      const name = g.name || 'Untitled';
      const rating = prefRating(g);
      const released = g.released ? new Date(g.released).toLocaleDateString() : 'Unknown';
      const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      const genres = (g.genres || []).slice(0,3).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');

      return `
        <div class="col-12 col-sm-6 col-lg-4" data-card-id="${id}">
          <div class="game-card h-100">
            ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
            <div class="p-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-0">${name}</h5>
                <span class="card-rating">
                  ${rating != null ? `<span class="badge rating-badge ms-2">${Number(rating).toFixed(1)}</span>` : ''}
                </span>
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
      const temp = document.createElement('div'); temp.innerHTML = cards;
      [...temp.children].forEach(c => results.appendChild(c));
    } else {
      results.innerHTML = cards;
    }

    results.querySelectorAll('[data-action="details"]').forEach(el=>{
      el.onclick = (e)=>{ e.preventDefault(); const id = parseInt(el.dataset.id || '0', 10); if (!id) return; openDetails(id); };
    });
  }

  // ===== Details modal + Like/Wishlist/Played + Stars =====
  window.openDetails = function(id){
    const detailsModalEl = document.getElementById('gameDetailsModal');
    const detailsTitleEl = document.getElementById('gdmTitle');
    const detailsBodyEl  = document.getElementById('gdmBody');
    const detailsModal = detailsModalEl ? new bootstrap.Modal(detailsModalEl) : null;
    if (!detailsModal) return;

    detailsTitleEl.textContent = 'Game Details';
    detailsBodyEl.innerHTML = 'Loading…';
    detailsModal.show();

    const body = new URLSearchParams(); body.append('id', String(id));
    fetch('./game.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString() })
      .then(async (r)=> { const text = await r.text(); try { return { ok: r.ok, json: JSON.parse(text), text }; } catch { return { ok: r.ok, json: null, text }; } })
      .then(({ok, json, text})=>{
        if (!ok || !json) { console.error('game.php error:', text); detailsBodyEl.innerHTML = `<div class="alert alert-danger">Failed to load details.</div>`; return; }
        if (json.success === false) { detailsBodyEl.innerHTML = `<div class="alert alert-danger">${json.message || 'Failed to load details.'}</div>`; return; }

        const g = (typeof json.item === 'string') ? JSON.parse(json.item) : (json.item || {});
        if (!g || !g.rawg_id) { detailsBodyEl.innerHTML = `<div class="alert alert-warning">No details found for this game.</div>`; return; }

        const pill = (arr)=> (arr && arr.length) ? `<div class="mb-2">${arr.map(x=>`<span class="badge badge-chip me-1 mb-1">${(typeof x==='string')?x:(x?.name||x)}</span>`).join('')}</div>` : '';
        const hero = g.background_image_additional || g.background_image || '';

        const avgUser = (g.user_rating != null) ? Number(g.user_rating) :
                        (g.userRating != null) ? Number(g.userRating) : null;

        const infoRows = [
          ['Released', g.released || 'Unknown'],
          ['Rating (avg)', (avgUser!=null ? avgUser.toFixed(1) : '—')],
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

        detailsTitleEl.textContent = g.name || 'Game Details';
        detailsBodyEl.innerHTML = `
          ${hero ? `<img src="${hero}" class="w-100 mb-3 rounded-3" style="border:1px solid rgba(124,77,255,.25)" alt="">` : ''}

          ${pill(g.platforms)}
          ${pill(g.genres)}
          ${pill(g.tags)}
          ${pill(g.stores)}
          ${pill(g.developers)}
          ${pill(g.publishers)}

          <div class="detail-actions" id="detailActions">
            <button class="btn btn-sm btn-outline-light" data-act="like" data-id="${g.rawg_id}">❤️ Like</button>
            <button class="btn btn-sm btn-outline-light" data-act="wishlist" data-id="${g.rawg_id}">📝 Wishlist</button>
            <button class="btn btn-sm btn-outline-light" data-act="played" data-id="${g.rawg_id}">✅ Played</button>
          </div>

          <div class="row g-3">
            <div class="col-lg-8">
              <h6 class="mb-2">About</h6>
              <div class="mb-3" style="line-height:1.6">${descHtml}</div>
              ${shots ? `<h6 class="mb-2">Screenshots</h6><div class="row">${shots}</div>` : ''}
            </div>
            <div class="col-lg-4">
              <h6 class="mb-2">Info</h6>
              ${infoRows}

              <div class="mt-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <strong>Your rating</strong>
                  <span id="ratingNum">${(g.user_rating_for_user!=null)?Number(g.user_rating_for_user).toFixed(1):'—'}</span>
                </div>
                <div id="stars" class="stars" aria-label="Rate this game">
                  ${[1,2,3,4,5].map(i => `
                    <button type="button" data-star="${i}" aria-label="${i} star">
                      <svg class="star-ico base" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 17.27L18.18 21 16.54 13.97 22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                      </svg>
                      <div class="fill"></div>
                    </button>
                  `).join('')}
                </div>
                <div class="text-secondary small mt-1">Click to save • half-stars supported</div>
              </div>
            </div>
          </div>
        `;

        // === Action buttons ===
        const actionsWrap = document.getElementById('detailActions');
        if (actionsWrap) {
          async function postToggle(url) {
            const payload = new URLSearchParams();
            payload.append('action', 'toggle');
            payload.append('rawg_id', String(g.rawg_id || ''));
            payload.append('liked', '1');
            payload.append('name', g.name || '');
            payload.append('image', g.background_image || g.background_image_additional || '');
            payload.append('released', g.released || '');
            const effective = (g.user_rating!=null?g.user_rating:g.rating);
            payload.append('rating', (effective!=null ? String(effective) : ''));

            const r = await fetch(url + '?_=' + Date.now(), {
              method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body: payload.toString()
            });
            const raw = await r.text();
            let j = null; try { j = JSON.parse(raw); } catch {}
            if (!r.ok || !j || j.success === false) throw new Error(raw || 'toggle failed');
          }

          const likeBtn = actionsWrap.querySelector('button[data-act="like"]');
          if (likeBtn) {
            let busy = false;
            likeBtn.addEventListener('click', async ()=> {
              if (busy) return; busy = true;
              likeBtn.classList.toggle('btn-outline-light'); likeBtn.classList.toggle('btn-light');
              try {
                await postToggle('likes.php');
                if (document.getElementById('liked-content').classList.contains('active-section') && typeof window.loadLikes === 'function') {
                  window.loadLikes();
                }
              } catch (e) {
                likeBtn.classList.toggle('btn-outline-light'); likeBtn.classList.toggle('btn-light');
                alert('Failed to update Like.');
              } finally { busy = false; }
            });
          }

          const wishBtn = actionsWrap.querySelector('button[data-act="wishlist"]');
          if (wishBtn) {
            let busy = false;
            wishBtn.addEventListener('click', async ()=> {
              if (busy) return; busy = true;
              wishBtn.classList.toggle('btn-outline-light'); wishBtn.classList.toggle('btn-light');
              try {
                await postToggle('wishlist.php');
                if (document.getElementById('wishlist-content').classList.contains('active-section') && typeof window.loadWishlist === 'function') {
                  window.loadWishlist();
                }
              } catch (e) {
                wishBtn.classList.toggle('btn-outline-light'); wishBtn.classList.toggle('btn-light');
                alert('Failed to update Wishlist.');
              } finally { busy = false; }
            });
          }

          const playedBtn = actionsWrap.querySelector('button[data-act="played"]');
          if (playedBtn) {
            let busy = false;
            playedBtn.addEventListener('click', async ()=> {
              if (busy) return; busy = true;
              playedBtn.classList.toggle('btn-outline-light'); playedBtn.classList.toggle('btn-light');
              try {
                await postToggle('played.php');
                if (document.getElementById('played-content').classList.contains('active-section') && typeof window.loadPlayed === 'function') {
                  window.loadPlayed();
                }
              } catch (e) {
                playedBtn.classList.toggle('btn-outline-light'); playedBtn.classList.toggle('btn-light');
                alert('Failed to update Played.');
              } finally { busy = false; }
            });
          }
        }

        /* ===== Stars (true half fill, glow, save via rating.php) ===== */
        (function initStars(){
          const starsWrap = document.getElementById('stars');
          const ratingNum = document.getElementById('ratingNum');
          const starBtns  = [...starsWrap.querySelectorAll('button[data-star]')];

          // existing user-specific value if present
          let current = (g.user_rating_for_user!=null) ? Number(g.user_rating_for_user) : 0;

          function paintValue(v) {
            starBtns.forEach(btn => {
              const i = Number(btn.getAttribute('data-star')); // 1..5
              let fill = Math.max(0, Math.min(1, v - (i - 1))); // 0..1
              // snap visually to halves
              if (fill > 0 && fill < 1) fill = (fill >= 0.75 ? 1 : (fill >= 0.25 ? 0.5 : 0));
              const pct = (fill * 100).toFixed(0) + '%';
              btn.querySelector('.fill').style.width = pct; // 0%, 50%, 100%
            });
          }

          function computeValueFromMouse(ev) {
            const btn = ev.target.closest('button[data-star]');
            if (!btn) return current || 0.5;
            const rect = btn.getBoundingClientRect();
            const x = ev.clientX - rect.left;
            const starIdx = Number(btn.getAttribute('data-star')); // 1..5
            return Math.max(0.5, Math.min(5, starIdx - (x < rect.width/2 ? 0.5 : 0)));
          }

          paintValue(current);
          if (ratingNum) ratingNum.textContent = current ? current.toFixed(1) : '—';

          starsWrap.addEventListener('mousemove', (ev)=>{
            const hoverVal = computeValueFromMouse(ev);
            paintValue(hoverVal);
          });
          starsWrap.addEventListener('mouseleave', ()=>{
            paintValue(current);
          });
          starBtns.forEach(b=>{
            b.addEventListener('click', async (ev)=>{
              const chosen = computeValueFromMouse(ev);
              await saveRating(g.rawg_id, chosen);
            });
          });

          async function saveRating(rawgId, value) {
            try {
              const payload = new URLSearchParams();
              payload.append('rawg_id', String(rawgId));
              payload.append('value', String(value));
              const r = await fetch('rating.php?_=' + Date.now(), {
                method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString()
              });
              const raw = await r.text();
              let j=null; try { j = JSON.parse(raw); } catch {}
              if (!r.ok || !j || j.success === false) throw new Error(j?.message || raw || 'Save failed');

              current = j.user_value ? Number(j.user_value) : value;
              paintValue(current);
              if (ratingNum) ratingNum.textContent = current.toFixed(1);

              // Update “Rating (avg)” row (if avg returned)
              if (j.avg != null) {
                const rows = document.querySelectorAll('#gdmBody .col-lg-4 .d-flex');
                rows.forEach(row=>{
                  const label = row.firstChild?.textContent?.trim();
                  if (label === 'Rating (avg)') row.lastChild.innerHTML = Number(j.avg).toFixed(1);
                });
              }

              // Update any visible card badge (prefer avg if provided)
              const card = document.querySelector(`[data-card-id="${rawgId}"] .card-rating`);
              if (card) {
                const val = (j.avg != null ? Number(j.avg) : current);
                card.innerHTML = `<span class="badge rating-badge ms-2">${val.toFixed(1)}</span>`;
              }
            } catch (e) {
              console.error('rating save error', e);
              alert('Failed to save rating.');
            }
          }
        })();
      })
      .catch(err=>{ console.error('fetch game.php failed:', err); detailsBodyEl.innerHTML = `<div class="alert alert-danger">Network error.</div>`; });
  }

  // Events
  btn.addEventListener('click', e => { e.preventDefault(); triggerScopeAndFetch(true); });
  qInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); triggerScopeAndFetch(true); } });
  loadMoreBtn.addEventListener('click', () => { if (page < totalPages) { page += 1; fetchGames(currentScope, { append: true }); } });

  // Expose for section router
  window.fetchGames = () => triggerScopeAndFetch(false);
})();
</script>

<script>
/* ===== Liked list loader/render ===== */
(function(){
  const likedList   = document.getElementById('likedList');
  const likedStatus = document.getElementById('likedStatus');

  function setLikedStatus(text, type) {
    likedStatus.className = 'alert';
    likedStatus.classList.add(type ? `alert-${type}` : 'alert-info');
    likedStatus.innerHTML = text;
    likedStatus.classList.remove('d-none');
  }
  function clearLikedStatus(){ likedStatus.classList.add('d-none'); }

  function prefRating(g) {
    if (g.user_rating != null && g.user_rating !== '') return Number(g.user_rating);
    if (g.userRating != null && g.userRating !== '') return Number(g.userRating);
    if (g.rating != null && g.rating !== '') return Number(g.rating);
    return null;
  }

  async function loadLikes() {
    if (!likedList) return;
    setLikedStatus('Loading your liked games…', null);
    likedList.innerHTML = '';

    try {
      const r = await fetch('likes.php?action=list&_=' + Date.now(), { method: 'GET' });
      const raw = await r.text();
      let j = null; try { j = JSON.parse(raw); } catch { j = null; }
      if (!r.ok || !j) { console.error('likes list non-JSON/HTTP error:', raw); setLikedStatus('Failed to load liked games.', 'danger'); return; }
      if (j.success === false) { setLikedStatus(j.message || 'Failed to load liked games.', 'danger'); return; }

      clearLikedStatus();
      renderLiked(j.items || []);
      window.scrollTo({ top: 0, behavior: 'instant' });
    } catch (e) {
      console.error('likes list network error', e);
      setLikedStatus('Network error loading liked games.', 'danger');
    }
  }

  function renderLiked(items) {
    if (!items.length) {
      likedList.innerHTML = '<div class="col-12"><div class="alert">No liked games yet.</div></div>';
      return;
    }
    const toName = (x)=> (typeof x === 'string' ? x : (x?.name ?? ''));
    likedList.innerHTML = items.map(g => {
      const id   = g.rawg_id ?? g.id ?? '';
      const img  = g.background_image || g.image || '';
      const name = g.name || 'Untitled';
      const rating = prefRating(g);
      const released = g.released ? new Date(g.released).toLocaleDateString() : 'Unknown';
      const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      const genres = (g.genres || []).slice(0,3).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');

      return `
        <div class="col-12 col-sm-6 col-lg-4" data-card-id="${id}">
          <div class="game-card h-100">
            ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
            <div class="p-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-0">${name}</h5>
                <span class="card-rating">
                  ${rating != null ? `<span class="badge rating-badge ms-2">${Number(rating).toFixed(1)}</span>` : ''}
                </span>
              </div>
              <div class="text-secondary small mb-2">Released: ${released}</div>
              ${platforms ? `<div class="mb-2">${platforms}</div>` : ''}
              ${genres ? `<div class="mb-2">${genres}</div>` : ''}
              <a href="#" class="btn btn-sm btn-light" data-action="details" data-id="${id}">Details</a>
            </div>
          </div>
        </div>`;
    }).join('');

    likedList.querySelectorAll('[data-action="details"]').forEach(el=>{
      el.onclick = (e)=>{ e.preventDefault(); const id = parseInt(el.dataset.id || '0', 10); if (!id) return; window.openDetails(id); };
    });
  }

  window.loadLikes = loadLikes;
})();
</script>

<script>
/* ===== Wishlist list loader/render ===== */
(function(){
  const listEl   = document.getElementById('wishlistList');
  const statusEl = document.getElementById('wishlistStatus');

  function setStatus(text, type) {
    statusEl.className = 'alert';
    statusEl.classList.add(type ? `alert-${type}` : 'alert-info');
    statusEl.innerHTML = text;
    statusEl.classList.remove('d-none');
  }
  function clearStatus(){ statusEl.classList.add('d-none'); }

  function prefRating(g) {
    if (g.user_rating != null && g.user_rating !== '') return Number(g.user_rating);
    if (g.userRating != null && g.userRating !== '') return Number(g.userRating);
    if (g.rating != null && g.rating !== '') return Number(g.rating);
    return null;
  }

  async function loadWishlist() {
    if (!listEl) return;
    setStatus('Loading your wishlist…', null);
    listEl.innerHTML = '';
    try {
      const r = await fetch('wishlist.php?action=list&_=' + Date.now(), { method: 'GET' });
      const raw = await r.text();
      let j = null; try { j = JSON.parse(raw); } catch { j = null; }
      if (!r.ok || !j) { console.error('wishlist list non-JSON/HTTP error:', raw); setStatus('Failed to load wishlist.', 'danger'); return; }
      if (j.success === false) { setStatus(j.message || 'Failed to load wishlist.', 'danger'); return; }

      clearStatus();
      render(j.items || []);
      window.scrollTo({ top: 0, behavior: 'instant' });
    } catch (e) {
      console.error('wishlist list network error', e);
      setStatus('Network error loading wishlist.', 'danger');
    }
  }

  function render(items) {
    if (!items.length) {
      listEl.innerHTML = '<div class="col-12"><div class="alert">No wishlist items yet.</div></div>';
      return;
    }
    const toName = x => (typeof x==='string'?x:(x?.name||''));
    listEl.innerHTML = items.map(g => {
      const id   = g.rawg_id ?? g.id ?? '';
      const img  = g.background_image || g.image || '';
      const name = g.name || 'Untitled';
      const rating = prefRating(g);
      const released = g.released ? new Date(g.released).toLocaleDateString() : 'Unknown';
      const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      const genres = (g.genres || []).slice(0,3).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      return `
        <div class="col-12 col-sm-6 col-lg-4" data-card-id="${id}">
          <div class="game-card h-100">
            ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
            <div class="p-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-0">${name}</h5>
                <span class="card-rating">
                  ${rating != null ? `<span class="badge rating-badge ms-2">${Number(rating).toFixed(1)}</span>` : ''}
                </span>
              </div>
              <div class="text-secondary small mb-2">Released: ${released}</div>
              ${platforms ? `<div class="mb-2">${platforms}</div>` : ''}
              ${genres ? `<div class="mb-2">${genres}</div>` : ''}
              <a href="#" class="btn btn-sm btn-light" data-action="details" data-id="${id}">Details</a>
            </div>
          </div>
        </div>`;
    }).join('');

    listEl.querySelectorAll('[data-action="details"]').forEach(el=>{
      el.onclick = (e)=>{ e.preventDefault(); const id = parseInt(el.dataset.id || '0', 10); if (!id) return; window.openDetails(id); };
    });
  }

  window.loadWishlist = loadWishlist;
})();
</script>

<script>
/* ===== Played list loader/render ===== */
(function(){
  const listEl   = document.getElementById('playedList');
  const statusEl = document.getElementById('playedStatus');

  function setStatus(text, type) {
    statusEl.className = 'alert';
    statusEl.classList.add(type ? `alert-${type}` : 'alert-info');
    statusEl.innerHTML = text;
    statusEl.classList.remove('d-none');
  }
  function clearStatus(){ statusEl.classList.add('d-none'); }

  function prefRating(g) {
    if (g.user_rating != null && g.user_rating !== '') return Number(g.user_rating);
    if (g.userRating != null && g.userRating !== '') return Number(g.userRating);
    if (g.rating != null && g.rating !== '') return Number(g.rating);
    return null;
  }

  async function loadPlayed() {
    if (!listEl) return;
    setStatus('Loading your played games…', null);
    listEl.innerHTML = '';
    try {
      const r = await fetch('played.php?action=list&_=' + Date.now(), { method: 'GET' });
      const raw = await r.text();
      let j = null; try { j = JSON.parse(raw); } catch { j = null; }
      if (!r.ok || !j) { console.error('played list non-JSON/HTTP error:', raw); setStatus('Failed to load played games.', 'danger'); return; }
      if (j.success === false) { setStatus(j.message || 'Failed to load played games.', 'danger'); return; }

      clearStatus();
      render(j.items || []);
      window.scrollTo({ top: 0, behavior: 'instant' });
    } catch (e) {
      console.error('played list network error', e);
      setStatus('Network error loading played games.', 'danger');
    }
  }

  function render(items) {
    if (!items.length) {
      listEl.innerHTML = '<div class="col-12"><div class="alert">No played games yet.</div></div>';
      return;
    }
    const toName = x => (typeof x==='string'?x:(x?.name||''));
    listEl.innerHTML = items.map(g => {
      const id   = g.rawg_id ?? g.id ?? '';
      const img  = g.background_image || g.image || '';
      const name = g.name || 'Untitled';
      const rating = prefRating(g);
      const released = g.released ? new Date(g.released).toLocaleDateString() : 'Unknown';
      const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      const genres = (g.genres || []).slice(0,3).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
      return `
        <div class="col-12 col-sm-6 col-lg-4" data-card-id="${id}">
          <div class="game-card h-100">
            ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
            <div class="p-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-0">${name}</h5>
                <span class="card-rating">
                  ${rating != null ? `<span class="badge rating-badge ms-2">${Number(rating).toFixed(1)}</span>` : ''}
                </span>
              </div>
              <div class="text-secondary small mb-2">Released: ${released}</div>
              ${platforms ? `<div class="mb-2">${platforms}</div>` : ''}
              ${genres ? `<div class="mb-2">${genres}</div>` : ''}
              <a href="#" class="btn btn-sm btn-light" data-action="details" data-id="${id}">Details</a>
            </div>
          </div>
        </div>`;
    }).join('');

    listEl.querySelectorAll('[data-action="details"]').forEach(el=>{
      el.onclick = (e)=>{ e.preventDefault(); const id = parseInt(el.dataset.id || '0', 10); if (!id) return; window.openDetails(id); };
    });
  }

  window.loadPlayed = loadPlayed;
})();
</script>

<script>
/* Sidebar routing + single-init */
document.addEventListener('DOMContentLoaded', () => {
  const nav = document.getElementById('sidebarNav');
  const sections = document.querySelectorAll('.content-display');
  const homeSectionId = 'home-content';

  function showSection(targetId) {
    sections.forEach(sec => sec.classList.remove('active-section'));
    const targetSection = document.getElementById(targetId);
    if (targetSection) targetSection.classList.add('active-section');

    nav.querySelectorAll('a').forEach(link => link.classList.remove('active'));
    const activeLink = nav.querySelector(`a[data-target="${targetId}"]`);
    if (activeLink) activeLink.classList.add('active');

    const myGamesMenu = document.getElementById('myGamesMenu');
    const isMyGamesChild = ['liked-content','wishlist-content','played-content'].includes(targetId);
    if (myGamesMenu) {
      const bsCollapse = bootstrap.Collapse.getOrCreateInstance(myGamesMenu, {toggle:false});
      if (isMyGamesChild) bsCollapse.show(); else bsCollapse.hide();
    }

    localStorage.setItem('activeSection', targetId);
    window.scrollTo({ top: 0, behavior: 'instant' });

    if (targetId === homeSectionId && typeof window.fetchGames === 'function') window.fetchGames();
    if (targetId === 'liked-content' && typeof window.loadLikes === 'function') window.loadLikes();
    if (targetId === 'wishlist-content' && typeof window.loadWishlist === 'function') window.loadWishlist();
    if (targetId === 'played-content' && typeof window.loadPlayed === 'function') window.loadPlayed();
  }

  function sectionFromQuery() {
    const m = location.search.match(/[?&]section=([^&]+)/i);
    if (!m) return null;
    const s = decodeURIComponent(m[1] || '').toLowerCase();
    if (s === 'home') return homeSectionId;
    if (s === 'liked') return 'liked-content';
    if (s === 'wishlist') return 'wishlist-content';
    if (s === 'played') return 'played-content';
    if (s === 'forum') return 'forum-content';
    if (s === 'reco' || s === 'recommendations') return 'recommendations-content';
    if (s === 'notifications') return 'notifications-content';
    return null;
  }

  // Sidebar links
  nav.querySelectorAll('a[data-target]').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const targetId = link.dataset.target;
      if (targetId) showSection(targetId);
      if (link.href && /section=home/i.test(link.href)) {
        history.replaceState(null, '', 'home.php?section=home');
      }
    });
  });

  // Initial section
  const urlSection = sectionFromQuery();
  const saved = localStorage.getItem('activeSection');
  if (urlSection && document.getElementById(urlSection)) showSection(urlSection);
  else if (saved && document.getElementById(saved)) showSection(saved);
  else showSection(homeSectionId);
});
</script>

<script>
/* forum comments (local only) */
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
