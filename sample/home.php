<?php
// --- Auth guard (same as before) ---
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Home • GameHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --bg1:#0f0f14; --bg2:#15151f; --card:#1c1c29; --text:#e8e8ff; --muted:#a9a9c1;
      --accent:#7c4dff; --accent-2:#00ffc6; --chip:#2b2b3b;
    }
    html,body{height:100%}
    body{
      background:
        radial-gradient(1200px 600px at 15% 0%, #18182a 0%, var(--bg1) 60%) fixed,
        linear-gradient(180deg, var(--bg1), var(--bg2)) fixed;
      color:var(--text);
      min-height:100vh; padding-bottom:24px;
    }
    .navbar{
      background:rgba(16,16,26,.9);
      backdrop-filter:blur(8px);
      border-bottom:1px solid rgba(124,77,255,.25);
    }
    .brand-text{
      font-weight:800; letter-spacing:.5px;
      background:linear-gradient(90deg, var(--accent), var(--accent-2));
      -webkit-background-clip:text; background-clip:text; color:transparent;
    }
    .hero{
      background:linear-gradient(180deg, rgba(124,77,255,.15), rgba(0,0,0,0));
      border-bottom:1px solid rgba(124,77,255,.15);
    }
    /* Sidebar */
    .sidebar{
      background:#12121b;
      border:1px solid rgba(124,77,255,.25);
      border-radius:14px;
      padding:16px;
      position:sticky; top:88px;
    }
    .sidebar .section-title{
      font-size:.9rem; color:var(--muted); letter-spacing:.08em; text-transform:uppercase;
      margin-bottom:.5rem;
    }
    .side-link{
      display:flex; align-items:center; gap:.5rem;
      padding:.6rem .75rem; border-radius:10px; text-decoration:none;
      color:var(--text); border:1px solid rgba(124,77,255,.18);
      background:linear-gradient(180deg,#1a1a27,#141420);
      transition: border-color .15s, box-shadow .15s, background .15s;
      margin-bottom:.5rem;
    }
    .side-link:hover{ border-color:rgba(0,255,198,.35); box-shadow:0 0 0 .1rem rgba(0,255,198,.15) inset; }
    .side-link.active{
      border-color:rgba(0,255,198,.45);
      background:linear-gradient(180deg,#1f1f2f,#181826);
      box-shadow:0 0 0 .15rem rgba(0,255,198,.12) inset;
    }

    .search-wrap{
      background:#12121b; border:1px solid rgba(124,77,255,.25);
      border-radius:14px; padding:16px;
    }
    .form-control,.btn{border-radius:10px}
    .btn-dark{
      background:linear-gradient(135deg,#2a2a3a,#1b1b29);
      border:1px solid rgba(124,77,255,.35);
    }
    .btn-dark:hover{ border-color:var(--accent); box-shadow:0 0 0 .2rem rgba(124,77,255,.25); }

    .game-card{
      background:var(--card);
      border:1px solid rgba(124,77,255,.18);
      border-radius:16px; overflow:hidden;
      transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
      display:flex; flex-direction:column;
    }
    .game-card:hover{
      transform:translateY(-4px);
      border-color:rgba(0,255,198,.35);
      box-shadow:0 10px 24px rgba(0,0,0,.35), 0 0 0 1px rgba(0,255,198,.2) inset;
    }
    .game-img{aspect-ratio:16/9; object-fit:cover; background:#0b0b12}
    .badge-chip{ background:var(--chip); border:1px solid rgba(124,77,255,.35); color:var(--muted); font-weight:600; }
    .rating-badge{ background:linear-gradient(180deg,#1f1f2f,#181826); border:1px solid rgba(0,255,198,.45); color:var(--accent-2); font-weight:700; }
    .alert{ border-radius:12px; border:1px solid rgba(124,77,255,.35); background:#141420; color:var(--text); }
    .footer-fade{color:var(--muted); opacity:.85}
    .card-actions .btn{min-width:88px}

    /* Offcanvas */
    .offcanvas{ background:#12121b; color:var(--text); }
    .offcanvas .btn-close{ filter:invert(1) grayscale(1); }
  </style>
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
      <button class="btn btn-outline-light me-2 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">☰</button>
      <a class="navbar-brand brand-text" href="#">GameHub</a>
      <div class="ms-auto text-secondary small">Signed in as <strong><?php echo $username; ?></strong></div>
    </div>
  </nav>

  <!-- Hero -->
  <header class="hero py-4">
    <div class="container">
      <div class="row align-items-center g-3">
        <div class="col-lg-8">
          <h1 class="h3 mb-1">Discover & track your next game</h1>
          <p class="mb-0 text-secondary">Use the sidebar to browse Library sections, or search by name.</p>
        </div>
      </div>
    </div>
  </header>

  <!-- Mobile Offcanvas Sidebar -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header">
      <h5 id="mobileSidebarLabel" class="mb-0">Menu</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
      <nav id="sidebarMobileNav">
        <div class="section-title">Browse</div>
        <a href="#" class="side-link active" data-view="recent">🏠 Home / Recent</a>
        <a href="#" class="side-link" data-view="search">🔎 Search</a>
        <div class="section-title mt-3">Library</div>
        <a href="#" class="side-link" data-view="liked">❤️ Liked</a>
        <a href="#" class="side-link" data-view="played">✅ Played</a>
        <a href="#" class="side-link" data-view="wishlist">📝 Wishlist</a>
        <a href="#" class="side-link" data-view="forums">💬 Forums</a>
        <a href="#" class="side-link" data-view="recommendations">✨ Recommendations</a>
      </nav>
    </div>
  </div>

  <main class="container my-4">
    <div class="row g-4">
      <!-- Desktop Sidebar -->
      <div class="col-lg-3 d-none d-lg-block">
        <aside class="sidebar">
          <nav id="sidebarNav">
            <div class="section-title">Browse</div>
            <a href="#" class="side-link active" data-view="recent">🏠 Home / Recent</a>
            <a href="#" class="side-link" data-view="search">🔎 Search</a>
            <div class="section-title mt-3">Library</div>
            <a href="#" class="side-link" data-view="liked">❤️ Liked</a>
            <a href="#" class="side-link" data-view="played">✅ Played</a>
            <a href="#" class="side-link" data-view="wishlist">📝 Wishlist</a>
            <a href="#" class="side-link" data-view="forums">💬 Forums</a>
            <a href="#" class="side-link" data-view="recommendations">✨ Recommendations</a>
          </nav>
        </aside>
      </div>

      <!-- Main Content Area -->
      <div class="col-lg-9">
        <!-- Search Toolbar (visible on "recent" and "search" views) -->
        <div id="searchToolbar" class="search-wrap mb-3">
          <div class="row g-2">
            <div class="col-12 col-xl-8">
              <input id="q" type="text" class="form-control form-control-lg" placeholder="Search games (e.g., Elden Ring)">
            </div>
            <div class="col-6 col-xl-2 d-grid">
              <button id="searchBtn" class="btn btn-dark btn-lg">Search</button>
            </div>
            <div class="col-6 col-xl-2 d-grid">
              <button id="clearBtn" class="btn btn-outline-light btn-lg">Clear</button>
            </div>
          </div>
        </div>

        <!-- RECENT/SEARCH VIEW -->
        <section id="view-recent">
          <div id="status" class="alert d-none" role="alert">Loading…</div>
          <div id="results" class="row g-4"></div>
          <nav class="mt-4">
            <ul id="pager" class="pagination justify-content-center"></ul>
          </nav>
        </section>

        <!-- LIKED VIEW -->
        <section id="view-liked" class="d-none">
          <h2 class="h5 mb-3">❤️ Liked</h2>
          <div id="likedResults" class="row g-4"></div>
        </section>

        <!-- PLAYED VIEW -->
        <section id="view-played" class="d-none">
          <h2 class="h5 mb-3">✅ Played</h2>
          <div id="playedResults" class="row g-4"></div>
        </section>

        <!-- WISHLIST VIEW -->
        <section id="view-wishlist" class="d-none">
          <h2 class="h5 mb-3">📝 Wishlist</h2>
          <div id="wishlistResults" class="row g-4"></div>
          <div class="alert mt-2">No wishlist items yet.</div>
        </section>

        <!-- FORUMS VIEW -->
        <section id="view-forums" class="d-none">
          <h2 class="h5 mb-3">💬 Forums</h2>
          <div class="alert">Forums coming soon.</div>
        </section>

        <!-- RECOMMENDATIONS VIEW -->
        <section id="view-recommendations" class="d-none">
          <h2 class="h5 mb-3">✨ Recommendations</h2>
          <div id="recoResults" class="row g-4"></div>
          <div class="alert mt-2">Recommendations coming soon.</div>
        </section>
      </div>
    </div>
  </main>

  <footer class="container mt-5">
    <p class="footer-fade small mb-0">© <?php echo date('Y'); ?> GameHub</p>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  (function(){
    // Elements
    const results        = document.getElementById('results');
    const pager          = document.getElementById('pager');
    const statusEl       = document.getElementById('status');
    const qInput         = document.getElementById('q');
    const searchBtn      = document.getElementById('searchBtn');
    const clearBtn       = document.getElementById('clearBtn');
    const searchToolbar  = document.getElementById('searchToolbar');

    // Views
    const VIEWS = {
      recent: document.getElementById('view-recent'),
      liked: document.getElementById('view-liked'),
      played: document.getElementById('view-played'),
      wishlist: document.getElementById('view-wishlist'),
      forums: document.getElementById('view-forums'),
      recommendations: document.getElementById('view-recommendations'),
      search: document.getElementById('view-recent') // search uses the same section as recent
    };

    const likedResults   = document.getElementById('likedResults');
    const playedResults  = document.getElementById('playedResults');
    const wishlistResults= document.getElementById('wishlistResults');
    const recoResults    = document.getElementById('recoResults');

    // Sidebars
    const sideDesktop = document.getElementById('sidebarNav');
    const sideMobile  = document.getElementById('sidebarMobileNav');
    const mobileCanvas= document.getElementById('mobileSidebar');

    // State
    let page = 1;
    const pageSize = 9;
    let currentScope = 'recent'; // for fetch logic
    let currentView  = 'recent'; // for view toggling

    // ----- Helpers: Status -----
    function setStatus(text, type){
      statusEl.className = 'alert';
      statusEl.classList.add(type ? ('alert-' + type) : 'alert-info');
      statusEl.innerHTML = text;
      statusEl.classList.remove('d-none');
    }
    function clearStatus(){ statusEl.classList.add('d-none'); }

    // ----- Helpers: Show/Hide Views -----
    function setActiveSideLinks(view){
      const mark = (root)=>{
        root?.querySelectorAll('.side-link').forEach(a=>{
          a.classList.toggle('active', a.dataset.view === view);
        });
      };
      mark(sideDesktop);
      mark(sideMobile);
    }

    function showView(view){
      currentView = view;
      // Hide all
      Object.entries(VIEWS).forEach(([k, el])=>{
        if (!el) return;
        if (k === view) el.classList.remove('d-none');
        else el.classList.add('d-none');
      });

      // Search toolbar only for recent/search
      const toolbarVisible = (view === 'recent' || view === 'search');
      searchToolbar.classList.toggle('d-none', !toolbarVisible);

      setActiveSideLinks(view);

      // Load content per-view without changing backend logic
      if (view === 'recent'){
        page = 1;
        fetchGames('recent');
      } else if (view === 'search'){
        triggerSearchScope(); // respects empty vs filled query
      } else if (view === 'liked'){
        renderLibrary('liked');
      } else if (view === 'played'){
        renderLibrary('played');
      } else if (view === 'wishlist'){
        renderLibrary('wishlist'); // placeholder
      } else if (view === 'recommendations'){
        renderLibrary('recommendations'); // placeholder
      } // forums is static placeholder
    }

    // ----- Local Library Store (no backend change) -----
    const LS_KEYS = {
      liked: 'gh_liked_games',
      played: 'gh_played_games',
      wishlist: 'gh_wishlist_games'
    };

    function loadStore(key){
      try { return JSON.parse(localStorage.getItem(LS_KEYS[key]) || '[]'); }
      catch { return []; }
    }
    function saveStore(key, arr){
      try { localStorage.setItem(LS_KEYS[key], JSON.stringify(arr)); } catch {}
    }
    function inStore(arr, id){ return arr.findIndex(x => String(x.id) === String(id)) !== -1; }

    function pickGameFields(g){
      // keep it light for library rendering
      const toName = (x)=> (typeof x === 'string' ? x : (x?.name ?? ''));
      return {
        id: g.id ?? '',
        name: g.name || 'Untitled',
        image: g.background_image || g.image || '',
        rating: (g.rating != null) ? Number(g.rating).toFixed(1) : null,
        released: g.released || null,
        platforms: (g.platforms || []).slice(0,4).map(toName),
        genres: (g.genres || []).slice(0,3).map(toName)
      };
    }

    function toggleLibraryEntry(kind, game, turnOn){
      const arr = loadStore(kind);
      const idx = arr.findIndex(x => String(x.id) === String(game.id));
      if (turnOn){
        if (idx === -1) { arr.push(pickGameFields(game)); saveStore(kind, arr); }
      } else {
        if (idx !== -1) { arr.splice(idx,1); saveStore(kind, arr); }
      }
      // If user is viewing that library right now, re-render it
      if (currentView === kind) renderLibrary(kind);
    }

    function renderLibrary(kind){
      const data = loadStore(kind);
      const container = (kind === 'liked') ? likedResults :
                        (kind === 'played') ? playedResults :
                        (kind === 'wishlist') ? wishlistResults :
                        (kind === 'recommendations') ? recoResults : null;
      if (!container) return;
      if (!data.length){
        container.innerHTML = `<div class="col-12"><div class="alert">No ${kind} items yet.</div></div>`;
        return;
      }
      const cards = data.map(g=>{
        const released = g.released ? new Date(g.released).toLocaleDateString() : 'TBA';
        const platforms = (g.platforms || []).map(p=>`<span class="badge badge-chip me-1 mb-1">${p}</span>`).join('');
        const genres    = (g.genres || []).map(p=>`<span class="badge badge-chip me-1 mb-1">${p}</span>`).join('');
        return `
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="game-card h-100">
            ${g.image ? `<img src="${g.image}" class="game-img w-100" alt="${g.name}">` : ''}
            <div class="p-3 d-flex flex-column h-100">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-0">${g.name}</h5>
                ${g.rating ? `<span class="badge rating-badge ms-2">${g.rating}</span>` : ''}
              </div>
              <div class="text-secondary small mb-2">Release: ${released}</div>
              ${platforms ? `<div class="mb-2">${platforms}</div>` : ''}
              ${genres ? `<div class="mb-3">${genres}</div>` : ''}
            </div>
          </div>
        </div>`;
      }).join('');
      container.innerHTML = cards;
    }

    // ----- Fetch logic (unchanged scopes: recent/search) -----
    function fetchGames(scope){
      if (scope) currentScope = scope;

      setStatus('Loading games…');
      results.innerHTML = '';
      pager.innerHTML = '';

      const q = qInput.value.trim();
      const body = new URLSearchParams();
      body.append('page', String(page));
      body.append('pageSize', String(pageSize));

      if (currentScope === 'search' && q){
        body.append('scope','search');
        body.append('query', q);
      } else {
        body.append('scope','recent');
      }

      fetch('games.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: body.toString()
      })
      .then(r => r.json())
      .then(data => {
        if (!data || data.success === false){
          setStatus(data?.message || 'Failed to load games', 'danger');
          return;
        }
        clearStatus();
        renderGames(data.items || []);
        renderPager(data.page || 1, data.totalPages || 1);
      })
      .catch(err => {
        console.error(err);
        setStatus('Network error loading games', 'danger');
      });
    }

    // Only render buttons when display === true
    function shouldShow(flag){ return flag === true; }

    function renderGames(items){
      if (!items.length){
        results.innerHTML = '<div class="col-12"><div class="alert">No games found.</div></div>';
        return;
      }
      const toName = (x)=> (typeof x === 'string' ? x : (x?.name ?? ''));
      const cards = items.map(g=>{
        const img = g.background_image || g.image || '';
        const name = g.name || 'Untitled';
        const rating = (g.rating != null) ? Number(g.rating).toFixed(1) : null;
        const released = g.released ? new Date(g.released).toLocaleDateString() : 'TBA';
        const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${toName(p)}</span>`).join('');
        const genres    = (g.genres || []).slice(0,3).map(ge => `<span class="badge badge-chip me-1 mb-1">${toName(ge)}</span>`).join('');

        const likeVisible    = shouldShow(g?.buttons?.like?.display);
        const playedVisible  = shouldShow(g?.buttons?.played?.display);
        const detailsVisible = shouldShow(g?.buttons?.details?.display);

        const likeBtn   = likeVisible   ? `<button class="btn btn-sm btn-outline-light" data-action="like" data-id="${g.id ?? ''}">Like</button>` : '';
        const playedBtn = playedVisible ? `<button class="btn btn-sm btn-outline-light" data-action="played" data-id="${g.id ?? ''}">Played</button>` : '';
        const detailsBtn= detailsVisible? `<a class="btn btn-sm btn-light" data-action="details" data-id="${g.id ?? ''}" href="#">Details</a>` : '';

        // Custom data attribute to reconstruct for library
        const gdata = encodeURIComponent(JSON.stringify({
          id: g.id ?? '',
          name, image: img, rating, released: g.released || null,
          platforms: (g.platforms || []).map(toName),
          genres: (g.genres || []).map(toName)
        }));

        return `
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="game-card h-100" data-game="${gdata}">
              ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
              <div class="p-3 d-flex flex-column h-100">
                <div class="d-flex justify-content-between align-items-start mb-1">
                  <h5 class="mb-0">${name}</h5>
                  ${rating !== null ? `<span class="badge rating-badge ms-2">${rating}</span>` : ''}
                </div>
                <div class="text-secondary small mb-2">Release: ${released}</div>
                ${platforms ? `<div class="mb-2">${platforms}</div>` : ''}
                ${genres ? `<div class="mb-3">${genres}</div>` : ''}
                <div class="mt-auto d-flex gap-2 flex-wrap card-actions">
                  ${likeBtn}
                  ${playedBtn}
                  ${detailsBtn || `<a class="btn btn-sm btn-outline-light disabled" tabindex="-1" aria-disabled="true">Details</a>`}
                </div>
              </div>
            </div>
          </div>
        `;
      }).join('');
      results.innerHTML = cards;

      // UI handlers
      results.querySelectorAll('[data-action]').forEach(el=>{
        el.addEventListener('click', (e)=>{
          const action = el.dataset.action;
          const card = el.closest('.game-card');
          const raw = card?.getAttribute('data-game');
          let g = null;
          try { g = raw ? JSON.parse(decodeURIComponent(raw)) : null; } catch {}
          if (action === 'details'){
            e.preventDefault();
            setStatus('Details view is not implemented on this page.', 'info');
            setTimeout(clearStatus, 1200);
            return;
          }
          if ((action === 'like' || action === 'played') && g){
            const makeOn = !el.classList.contains('btn-light'); // toggling to ON if currently outline
            el.classList.toggle('btn-light', makeOn);
            el.classList.toggle('btn-outline-light', !makeOn);
            toggleLibraryEntry(action, g, makeOn);
          }
          el.blur();
        });
      });
    }

    function renderPager(current, total){
      if (total <= 1){ pager.innerHTML = ''; return; }
      let html = '';
      const item = (p, label=p, disabled=false, active=false)=>`
        <li class="page-item ${disabled?'disabled':''} ${active?'active':''}">
          <a class="page-link" href="#" data-page="${p}">${label}</a>
        </li>`;
      html += item(current - 1, '&laquo;', current <= 1, false);

      const windowSize = 5;
      let start = Math.max(1, current - Math.floor(windowSize/2));
      let end   = Math.min(total, start + windowSize - 1);
      if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1);

      for (let p = start; p <= end; p++) html += item(p, p, false, p === current);
      html += item(current + 1, '&raquo;', current >= total, false);

      pager.innerHTML = html;
      pager.querySelectorAll('a.page-link').forEach(a=>{
        a.addEventListener('click', (e)=>{
          e.preventDefault();
          const p = parseInt(a.dataset.page, 10);
          if (!isNaN(p) && p !== page){ page = p; fetchGames(currentScope); }
        });
      });
    }

    // ----- Search triggers -----
    function triggerSearchScope(){
      page = 1;
      const q = qInput.value.trim();
      currentScope = (q === '') ? 'recent' : 'search';
      fetchGames(currentScope);
    }
    searchBtn.addEventListener('click', triggerSearchScope);
    qInput.addEventListener('keydown', (e)=>{ if (e.key === 'Enter'){ e.preventDefault(); triggerSearchScope(); }});
    clearBtn.addEventListener('click', ()=>{ qInput.value=''; page=1; showView('recent'); });

    // ----- Sidebar interactions -----
    function handleSideClick(root){
      root?.addEventListener('click', (e)=>{
        const link = e.target.closest('.side-link'); if (!link) return;
        e.preventDefault();
        const view = link.dataset.view;
        if (!view) return;
        if (view === 'recent'){ qInput.value=''; }
        showView(view);
        // close mobile offcanvas if open
        if (root === sideMobile){
          const off = bootstrap.Offcanvas.getOrCreateInstance(mobileCanvas);
          off.hide();
        }
      });
    }
    handleSideClick(sideDesktop);
    handleSideClick(sideMobile);

    // Init
    setActiveSideLinks('recent');
    showView('recent');
  })();
  </script>
</body>
</html>
