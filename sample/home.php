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
  <!-- (head + styles same as before) -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Home • GameHub</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --bg1: #0f0f14;
      --bg2: #15151f;
      --card: #1c1c29;
      --text: #e8e8ff;
      --muted: #a9a9c1;
      --accent: #7c4dff; /* neon purple */
      --accent-2: #00ffc6; /* neon teal */
      --chip: #2b2b3b;
    }
    body {
      background: radial-gradient(1200px 600px at 15% 0%, #18182a 0%, var(--bg1) 60%) fixed,
                  linear-gradient(180deg, var(--bg1), var(--bg2)) fixed;
      color: var(--text);
      min-height: 100vh;
      padding-bottom: 24px;
    }
    .navbar {
      background: rgba(16,16,26,0.9);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(124,77,255,0.25);
    }
    .brand-text {
      font-weight: 800; letter-spacing: .5px;
      background: linear-gradient(90deg, var(--accent), var(--accent-2));
      -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .hero {
      background: linear-gradient(180deg, rgba(124,77,255,.15), rgba(0,0,0,0));
      border-bottom: 1px solid rgba(124,77,255,0.15);
    }
    .search-wrap {
      background: #12121b; border: 1px solid rgba(124,77,255,0.25);
      border-radius: 14px; padding: 16px;
    }
    .form-control, .btn {
      border-radius: 10px;
    }
    .btn-dark {
      background: linear-gradient(135deg, #2a2a3a, #1b1b29);
      border: 1px solid rgba(124,77,255,0.35);
    }
    .btn-dark:hover {
      border-color: var(--accent);
      box-shadow: 0 0 0 0.2rem rgba(124,77,255,0.25);
    }
    .game-card {
      background: var(--card);
      border: 1px solid rgba(124,77,255,0.18);
      border-radius: 16px;
      overflow: hidden;
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .game-card:hover {
      transform: translateY(-4px);
      border-color: rgba(0,255,198,0.35);
      box-shadow: 0 10px 24px rgba(0,0,0,.35), 0 0 0 1px rgba(0,255,198,0.2) inset;
    }
    .game-img {
      aspect-ratio: 16/9;
      object-fit: cover;
      background: #0b0b12;
    }
    .badge-chip {
      background: var(--chip);
      border: 1px solid rgba(124,77,255,0.35);
      color: var(--muted);
      font-weight: 600;
    }
    .rating-badge {
      background: linear-gradient(180deg, #1f1f2f, #181826);
      border: 1px solid rgba(0,255,198,0.45);
      color: var(--accent-2);
      font-weight: 700;
    }
    .alert {
      border-radius: 12px; border: 1px solid rgba(124,77,255,0.35);
      background: #141420; color: var(--text);
    }
    .sentinel {
      height: 1px;
    }
    .footer-fade {
      color: var(--muted);
      opacity: .85;
    }
  </style>
</head>
<body>
  <!-- (navbar + hero unchanged) -->
  <div class="container my-4">
    <div class="search-wrap mb-4">
      <div class="row g-2">
        <div class="col-12 col-lg-9">
          <input id="q" type="text" class="form-control form-control-lg" placeholder="Search games (e.g., Elden Ring)">
        </div>
        <div class="col-12 col-lg-3 d-grid">
          <button id="searchBtn" class="btn btn-dark btn-lg">Search</button>
        </div>
      </div>
    </div>

    <div id="status" class="alert d-none">Loading…</div>
    <div id="results" class="row g-4"></div>

    <nav class="mt-4">
      <ul id="pager" class="pagination justify-content-center"></ul>
    </nav>
  </div>

  <!-- (footer unchanged) -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  (function(){
    const results = document.getElementById('results');
    const pager   = document.getElementById('pager');
    const status  = document.getElementById('status');
    const qInput  = document.getElementById('q');
    const btn     = document.getElementById('searchBtn');

    let page = 1;
    const pageSize = 9;
    let currentScope = 'recent'; // default feed

    function setStatus(text, type) {
      status.className = 'alert';
      status.classList.add(type ? `alert-${type}` : 'alert-info');
      status.innerHTML = text;
      status.classList.remove('d-none');
    }
    function clearStatus(){ status.classList.add('d-none'); }

    function fetchGames(scope) {
      currentScope = scope || currentScope;

      setStatus('Loading games…');
      results.innerHTML = '';
      pager.innerHTML = '';

      const q = qInput.value.trim();
      const body = new URLSearchParams();
      body.append('page', String(page));
      body.append('pageSize', String(pageSize));

      if (currentScope === 'search' && q) {
        body.append('scope', 'search');
        body.append('query', q);
      } else {
        body.append('scope', 'recent');
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
        renderGames(data.items || []);
        renderPager(data.page || 1, data.totalPages || 1);
      })
      .catch(err => {
        console.error(err);
        setStatus('Network error loading games', 'danger');
      });
    }

    function renderGames(items) {
      if (!items.length) {
        results.innerHTML = '<div class="col-12"><div class="alert">No games found.</div></div>';
        return;
      }
      const cards = items.map(g => {
        const img = g.background_image || g.image || '';
        const name = g.name || 'Untitled';
        const rating = (g.rating != null) ? Number(g.rating).toFixed(1) : null;
        const released = g.released ? new Date(g.released).toLocaleDateString() : 'TBA';
        const platforms = (g.platforms || []).slice(0,4).map(p => `<span class="badge badge-chip me-1 mb-1">${p}</span>`).join('');
        const genres = (g.genres || []).slice(0,3).map(p => `<span class="badge badge-chip me-1 mb-1">${p}</span>`).join('');
        return `
          <div class="col-12 col-sm-6 col-lg-4">
            <div class="game-card h-100">
              ${img ? `<img src="${img}" class="game-img w-100" alt="${name}">` : ''}
              <div class="p-3">
                <div class="d-flex justify-content-between align-items-start mb-1">
                  <h5 class="mb-0">${name}</h5>
                  ${rating !== null ? `<span class="badge rating-badge ms-2">${rating}</span>` : ''}
                </div>
                <div class="text-secondary small mb-2">Release: ${released}</div>
                <div class="mb-2">${platforms}</div>
                <div class="mb-2">${genres}</div>
                <a href="#" class="btn btn-sm btn-outline-light disabled">Details</a>
              </div>
            </div>
          </div>
        `;
      }).join('');
      results.innerHTML = cards;
    }

    function renderPager(current, total) {
      if (total <= 1) { pager.innerHTML = ''; return; }
      let html = '';
      const item = (p, label = p, disabled = false, active = false) => `
        <li class="page-item ${disabled ? 'disabled' : ''} ${active ? 'active' : ''}">
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
      pager.querySelectorAll('a.page-link').forEach(a => {
        a.addEventListener('click', (e) => {
          e.preventDefault();
          const p = parseInt(a.dataset.page, 10);
          if (!isNaN(p) && p !== page) { page = p; fetchGames(currentScope); }
        });
      });
    }

    // Search click
    btn.addEventListener('click', () => {
      page = 1;
      const q = qInput.value.trim();
      if (q === '') fetchGames('recent'); else fetchGames('search');
    });

    // Initial load
    fetchGames('recent');
  })();
  </script>
</body>
</html>