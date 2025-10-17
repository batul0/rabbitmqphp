#!/usr/bin/php
<?php
declare(strict_types=1);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

/* =======================
  MySQL settings
  ======================= */
const DB_HOST = '100.76.74.77';
const DB_NAME = 'loginDB';
const DB_USER = 'loginapp';
const DB_PASS = 'loginappPass123';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

/** PDO helper */
function getPDO(): PDO {
  $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_notes = 0",
  ];
  return new PDO($dsn, DB_USER, DB_PASS, $options);
}

/** ===== Auth helpers (unchanged) ===== */
function doLogin(string $username, string $password): array {
  if ($username === '' || $password === '') {
    return ['success' => false, 'message' => 'Username and password required'];
  }
  try {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'message' => 'Invalid credentials'];

    $hash = $row['password'] ?? '';
    if ($hash === '' || !password_verify($password, $hash)) {
      return ['success' => false, 'message' => 'Invalid credentials'];
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
      $newHash = password_hash($password, PASSWORD_DEFAULT);
      $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
      $upd->execute([$newHash, $row['id']]);
    }

    $key = bin2hex(random_bytes(32));
    $exp = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
    $ins = $pdo->prepare('INSERT INTO sessions (user_id, session_key, expires_at) VALUES (?,?,?)');
    $ins->execute([$row['id'], $key, $exp]);

    return ['success'=>true,'message'=>'Login successful','username'=>$row['username'],'session_key'=>$key,'expires_at'=>$exp];
  } catch (Throwable $e) {
    error_log('[doLogin] DB error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  }
}

function doRegister(string $username, string $password): array {
  $username = trim($username);
  $password = (string)$password;
  if ($username === '' || strlen($password) < 4) {
    return ['success' => false, 'message' => 'Invalid input'];
  }
  try {
    $pdo = getPDO();
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
    $chk->execute([$username]);
    if ($chk->fetchColumn()) return ['success'=>false,'message'=>'Username already exists'];

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins  = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    $ins->execute([$username, $hash]);

    return ['success' => true, 'message' => 'Registration successful'];
  } catch (PDOException $e) {
    if ($e->getCode() === '23000') return ['success'=>false,'message'=>'Username already exists'];
    error_log('[doRegister] DB error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  } catch (Throwable $e) {
    error_log('[doRegister] error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  }
}

function doValidate(string $sessionId): array {
  $sessionId = trim($sessionId);
  if ($sessionId === '') return ['success' => false, 'message' => 'No session'];
  try {
    $pdo = getPDO();
    $q = $pdo->prepare(
      'SELECT u.id, u.username
       FROM sessions s JOIN users u ON u.id = s.user_id
       WHERE s.session_key = ? AND s.expires_at > NOW() LIMIT 1'
    );
    $q->execute([$sessionId]);
    $row = $q->fetch();
    if (!$row) return ['success' => false, 'message' => 'Invalid/expired session'];
    return ['success' => true, 'username' => $row['username']];
  } catch (Throwable $e) {
    error_log('[doValidate] DB error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  }
}

function doLogout(string $sessionId): array {
  $sessionId = trim($sessionId);
  if ($sessionId === '') return ['success' => false, 'message' => 'No session'];
  try {
    $pdo = getPDO();
    $del = $pdo->prepare('DELETE FROM sessions WHERE session_key = ?');
    $del->execute([$sessionId]);
    return ['success' => true, 'message' => 'Logged out'];
  } catch (Throwable $e) {
    error_log('[doLogout] DB error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  }
}

/** ===== Games helpers ===== */
function upsertGame(PDO $pdo, array $g): void {
  $stmt = $pdo->prepare(
    'INSERT INTO games (rawg_id, name, released, rating, background_image, platforms, genres)
     VALUES (:rid, :name, :rel, :rating, :img, :plats, :genres)
     ON DUPLICATE KEY UPDATE
       name=VALUES(name), released=VALUES(released), rating=VALUES(rating),
       background_image=VALUES(background_image), platforms=VALUES(platforms), genres=VALUES(genres)'
  );
  $plats  = json_encode($g['platforms'] ?? []);
  $genres = json_encode($g['genres'] ?? []);
  $stmt->execute([
    ':rid'   => $g['rawg_id'],
    ':name'  => $g['name'] ?? '',
    ':rel'   => $g['released'] ?? null,
    ':rating'=> isset($g['rating']) ? $g['rating'] : null,
    ':img'   => $g['background_image'] ?? null,
    ':plats' => $plats,
    ':genres'=> $genres,
  ]);
}

function getGamesPageByDates(PDO $pdo, int $page, int $pageSize, string $from, string $to): array {
  $page     = max(1, $page);
  $pageSize = max(1, min(50, $pageSize));
  $offset   = ($page - 1) * $pageSize;

  $cnt = $pdo->prepare('SELECT COUNT(*) FROM games WHERE released IS NOT NULL AND released BETWEEN ? AND ?');
  $cnt->execute([$from, $to]);
  $total = (int)$cnt->fetchColumn();

  $stmt = $pdo->prepare(
    'SELECT * FROM games
     WHERE released IS NOT NULL AND released BETWEEN ? AND ?
     ORDER BY released DESC, name ASC
     LIMIT ? OFFSET ?'
  );
  $stmt->bindValue(1, $from, PDO::PARAM_STR);
  $stmt->bindValue(2, $to,   PDO::PARAM_STR);
  $stmt->bindValue(3, $pageSize, PDO::PARAM_INT);
  $stmt->bindValue(4, $offset,   PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll();
  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'rawg_id'          => (int)$r['rawg_id'],
      'name'             => $r['name'],
      'released'         => $r['released'],
      'rating'           => is_null($r['rating']) ? null : (float)$r['rating'],
      'background_image' => $r['background_image'],
      'platforms'        => json_decode($r['platforms'] ?? '[]', true) ?: [],
      'genres'           => json_decode($r['genres'] ?? '[]', true) ?: [],
    ];
  }
  $totalPages = max(1, (int)ceil($total / $pageSize));
  return [$items, $total, $totalPages];
}

function getGamesPage(PDO $pdo, int $page, int $pageSize, string $query): array {
  $page     = max(1, $page);
  $pageSize = max(1, min(50, $pageSize));
  $offset   = ($page - 1) * $pageSize;

  if ($query !== '') {
    $like = '%' . $query . '%';
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM games WHERE name LIKE ?');
    $cnt->execute([$like]);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare('SELECT * FROM games WHERE name LIKE ? ORDER BY name LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,   PDO::PARAM_INT);
    $stmt->execute();
  } else {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM games ORDER BY name LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset,   PDO::PARAM_INT);
    $stmt->execute();
  }

  $rows = $stmt->fetchAll();
  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'rawg_id'          => (int)$r['rawg_id'],
      'name'             => $r['name'],
      'released'         => $r['released'],
      'rating'           => is_null($r['rating']) ? null : (float)$r['rating'],
      'background_image' => $r['background_image'],
      'platforms'        => json_decode($r['platforms'] ?? '[]', true) ?: [],
      'genres'           => json_decode($r['genres'] ?? '[]', true) ?: [],
    ];
  }
  $totalPages = max(1, (int)ceil($total / $pageSize));
  return [$items, $total, $totalPages];
}

/** 🔍 Search flow: DB-first; if empty -> DMZ search -> upsert -> return DB page */
function doGamesSearch(int $page, int $pageSize, string $query): array {
  $pdo = getPDO();
  // 1) Try DB
  list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
  if ($total > 0) {
    return [
      'success'=>true, 'items'=>$items,
      'page'=>$page, 'pageSize'=>$pageSize, 'total'=>$total, 'totalPages'=>$totalPages,
      'source'=>'db'
    ];
  }

  // 2) DB is empty for this query → ask DMZ
  $dmz = new rabbitMQClient('testRabbitMQ.ini', 'dmzServer');
  $dmzRes = $dmz->send_request([
    'type'           => 'fetch_games',
    'page'           => $page,
    'pageSize'       => $pageSize,
    'query'          => $query,
    'search_precise' => false,
    'ordering'       => '-rating'
  ]);

  if (is_array($dmzRes) && !empty($dmzRes['success'])) {
    foreach (($dmzRes['items'] ?? []) as $g) {
      if (!empty($g['rawg_id'])) upsertGame($pdo, $g);
    }
    // 3) Return DB page again after upsert
    list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
    return [
      'success'=>true, 'items'=>$items,
      'page'=>$page, 'pageSize'=>$pageSize, 'total'=>$total, 'totalPages'=>$totalPages,
      'source'=>'dmz->db'
    ];
  }

  // 4) DMZ failed and DB had nothing
  return ['success'=>true,'items'=>[],'page'=>1,'pageSize'=>$pageSize,'total'=>0,'totalPages'=>1,'source'=>'none'];
}

/** Main list handler with RECENT window + search */
function doGamesList(int $page, int $pageSize, string $query, string $scope = 'recent'): array {
  try {
    $pdo = getPDO();
    $page     = max(1, $page);
    $pageSize = max(1, min(50, $pageSize));

    // Window: first day of last month → last day of this month
    $start = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
    $end   = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

    // 🔎 New scope: 'search' (DB-first, DMZ on miss)
    if ($scope === 'search' && $query !== '') {
      return doGamesSearch($page, $pageSize, $query);
    }

    // RECENT (default)
    if ($scope === 'recent' && $query === '') {
      // DB first
      list($items, $total, $totalPages) = getGamesPageByDates($pdo, $page, $pageSize, $start, $end);
      if (count($items) === 0) {
        // Ask DMZ to fill the window
        $dmz = new rabbitMQClient('testRabbitMQ.ini', 'dmzServer');
        $dmzRes = $dmz->send_request([
          'type'     => 'fetch_games',
          'page'     => $page,
          'pageSize' => $pageSize,
          'query'    => '',
          'dates'    => $start . ',' . $end,
          'ordering' => '-released'
        ]);
        if (is_array($dmzRes) && !empty($dmzRes['success'])) {
          foreach (($dmzRes['items'] ?? []) as $g) {
            if (!empty($g['rawg_id'])) upsertGame($pdo, $g);
          }
          list($items, $total, $totalPages) = getGamesPageByDates($pdo, $page, $pageSize, $start, $end);
          return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages,'source'=>'dmz->db','window'=>['from'=>$start,'to'=>$end]];
        }
      }
      // DB had data (or DMZ failed but DB has something)
      return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages,'source'=>'db','window'=>['from'=>$start,'to'=>$end]];
    }

    // Fallback: plain DB page
    list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages,'source'=>'db'];
  } catch (Throwable $e) {
    error_log('[doGamesList] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

/** ===== Rabbit dispatcher ===== */
function requestProcessor(array $request) {
  echo "Received request:\n";
  var_dump($request);

  if (!isset($request['type'])) return ['success' => false, 'message' => 'ERROR: unsupported message type'];

  switch ($request['type']) {
    case 'login':
      return doLogin((string)($request['username'] ?? ''), (string)($request['password'] ?? ''));
    case 'register':
      return doRegister((string)($request['username'] ?? ''), (string)($request['password'] ?? ''));
    case 'validate_session':
      return doValidate((string)($request['sessionId'] ?? ''));
    case 'logout':
      return doLogout((string)($request['sessionId'] ?? ''));
    case 'games_list':
      $page   = (int)($request['page'] ?? 1);
      $ps     = (int)($request['pageSize'] ?? 9);
      $query  = trim((string)($request['query'] ?? ''));
      $scope  = trim((string)($request['scope'] ?? 'recent'));
      return doGamesList($page, $ps, $query, $scope);
    default:
      return ['success' => false, 'message' => 'ERROR: unknown type'];
  }
}

$server = new rabbitMQServer("testRabbitMQ.ini", "loginServer");
echo "testRabbitMQServer BEGIN\n";
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END\n";
?>