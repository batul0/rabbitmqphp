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

/** ===== Auth helpers ===== */
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
      'id'               => (int)$r['rawg_id'],
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
      'id'               => (int)$r['rawg_id'],
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

/** Ensure DB has enough rows for requested recent page; backfill via DMZ */
function backfillRecentWindow(PDO $pdo, int $needUpToPage, int $pageSize, string $from, string $to): void {
  $MAX_ROUNDS = 6; $round = 0;

  $cnt = $pdo->prepare('SELECT COUNT(*) FROM games WHERE released IS NOT NULL AND released BETWEEN ? AND ?');
  $cnt->execute([$from, $to]);
  $total = (int)$cnt->fetchColumn();
  $target = $needUpToPage * $pageSize;

  while ($total < $target && $round < $MAX_ROUNDS) {
    $round++;
    $dmzPage = (int)floor($total / $pageSize) + 1;

    $dmz = new rabbitMQClient('testRabbitMQ.ini', 'dmzServer');
    $dmzRes = $dmz->send_request([
      'type'     => 'fetch_games',
      'page'     => $dmzPage,
      'pageSize' => $pageSize,
      'query'    => '',
      'dates'    => $from . ',' . $to,
      'ordering' => '-released'
    ]);

    if (!is_array($dmzRes) || empty($dmzRes['success'])) {
      error_log('[backfillRecentWindow] DMZ fetch failed on round ' . $round);
      break;
    }

    $fetched = 0;
    foreach (($dmzRes['items'] ?? []) as $g) {
      if (!empty($g['rawg_id'])) { upsertGame($pdo, $g); $fetched++; }
    }

    $cnt->execute([$from, $to]);
    $total = (int)$cnt->fetchColumn();
    if ($fetched === 0) break;
  }
}

/** 🔎 SEARCH: Always DMZ-first by relevance; cache results */
function doGamesSearch(int $page, int $pageSize, string $query): array {
  $query = trim($query);
  if ($query === '') {
    return ['success' => true, 'items' => [], 'page' => 1, 'pageSize' => $pageSize, 'total' => 0, 'totalPages' => 1, 'source' => 'none'];
  }

  try {
    $dmz = new rabbitMQClient('testRabbitMQ.ini', 'dmzServer');
    $dmzRes = $dmz->send_request([
      'type'           => 'fetch_games',
      'page'           => max(1, $page),
      'pageSize'       => max(1, min(40, $pageSize)),
      'query'          => $query,
      'search_precise' => false
    ]);

    if (!is_array($dmzRes) || empty($dmzRes['success'])) {
      return ['success'=>false,'message'=>'DMZ search failed'];
    }

    // Cache to DB (best-effort)
    try {
      $pdo = getPDO();
      foreach (($dmzRes['items'] ?? []) as $g) {
        if (!empty($g['rawg_id'])) upsertGame($pdo, $g);
      }
    } catch (Throwable $e) {
      error_log('[doGamesSearch] upsert cache warning: ' . $e->getMessage());
    }

    return [
      'success'    => true,
      'items'      => $dmzRes['items'] ?? [],
      'page'       => $dmzRes['page'] ?? $page,
      'pageSize'   => $dmzRes['pageSize'] ?? $pageSize,
      'total'      => $dmzRes['total'] ?? null,
      'totalPages' => $dmzRes['totalPages'] ?? ($dmzRes['next'] ? ($page+1) : $page),
      'source'     => 'dmz'
    ];

  } catch (Throwable $e) {
    error_log('[doGamesSearch] error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  }
}

/** Main list handler with RECENT window + search + DB→DMZ backfill */
function doGamesList(int $page, int $pageSize, string $query, string $scope = 'recent'): array {
  try {
    $pdo = getPDO();
    $page     = max(1, $page);
    $pageSize = max(1, min(50, $pageSize));

    $start = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
    $end   = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

    if ($scope === 'search' && $query !== '') {
      return doGamesSearch($page, $pageSize, $query);
    }

    if ($scope === 'recent' && $query === '') {
      backfillRecentWindow($pdo, $page, $pageSize, $start, $end);
      list($items, $total, $totalPages) = getGamesPageByDates($pdo, $page, $pageSize, $start, $end);

      if ($total < ($page * $pageSize)) {
        backfillRecentWindow($pdo, $page, $pageSize, $start, $end);
        list($items, $total, $totalPages) = getGamesPageByDates($pdo, $page, $pageSize, $start, $end);
      }

      return [
        'success'    => true,
        'items'      => $items,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'total'      => $total,
        'totalPages' => $totalPages,
        'source'     => 'db',
        'window'     => ['from'=>$start,'to'=>$end]
      ];
    }

    // fallback
    list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages,'source'=>'db'];

  } catch (Throwable $e) {
    error_log('[doGamesList] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

/** ===== Game details JSON cache ===== */
function ensureDetailsTable(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS game_details (
      rawg_id INT PRIMARY KEY,
      details_json LONGTEXT NOT NULL,
      updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function getCachedDetails(PDO $pdo, int $id, int $maxAgeMinutes = 10080): ?array {
  ensureDetailsTable($pdo);
  $stmt = $pdo->prepare("SELECT details_json, updated_at FROM game_details WHERE rawg_id = ? LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) return null;

  $updated = new DateTime($row['updated_at']);
  $age = (new DateTime())->getTimestamp() - $updated->getTimestamp();
  if ($age > $maxAgeMinutes * 60) return null;

  $json = json_decode($row['details_json'], true);
  return is_array($json) ? $json : null;
}

function putCachedDetails(PDO $pdo, int $id, array $payload): void {
  ensureDetailsTable($pdo);
  $stmt = $pdo->prepare("
    INSERT INTO game_details (rawg_id, details_json, updated_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE details_json = VALUES(details_json), updated_at = NOW()
  ");
  $stmt->execute([$id, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

function doGameDetails(int $id): array {
  if ($id <= 0) return ['success'=>false,'message'=>'Invalid game id'];
  try {
    $pdo = getPDO();

    $cached = getCachedDetails($pdo, $id);
    if (is_array($cached)) {
      return ['success'=>true, 'item'=>$cached, 'source'=>'cache'];
    }

    $dmz = new rabbitMQClient('testRabbitMQ.ini', 'dmzServer');
    $dmzRes = $dmz->send_request(['type'=>'fetch_game_details','id'=>$id]);

    if (!is_array($dmzRes) || empty($dmzRes['success'])) {
      return ['success'=>false,'message'=>'Details fetch failed'];
    }

    $item = $dmzRes['item'] ?? [];
    putCachedDetails($pdo, $id, $item);

    if (!empty($item['rawg_id'])) {
      upsertGame($pdo, [
        'id'               => (int)$r['rawg_id'],
        'rawg_id'          => $item['rawg_id'],
        'name'             => $item['name'] ?? '',
        'released'         => $item['released'] ?? null,
        'rating'           => $item['rating'] ?? null,
        'background_image' => $item['background_image'] ?? null,
        'platforms'        => $item['platforms'] ?? [],
        'genres'           => $item['genres'] ?? [],
      ]);
    }

    return ['success'=>true, 'item'=>$item, 'source'=>'dmz'];
  } catch (Throwable $e) {
    error_log('[doGameDetails] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

/** ===== Rabbit dispatcher ===== */
function requestProcessor(array $request) {
  echo "Received request:\n";
  var_dump($request);

  if (!isset($request['type'])) return ['success' => false, 'message' => 'ERROR: unsupported message type'];

  switch ($request['type']) {
    case 'login':              return doLogin((string)($request['username'] ?? ''), (string)($request['password'] ?? ''));
    case 'register':           return doRegister((string)($request['username'] ?? ''), (string)($request['password'] ?? ''));
    case 'validate_session':   return doValidate((string)($request['sessionId'] ?? ''));
    case 'logout':             return doLogout((string)($request['sessionId'] ?? ''));
    case 'games_list': {
      $page   = (int)($request['page'] ?? 1);
      $ps     = (int)($request['pageSize'] ?? 9);
      $query  = trim((string)($request['query'] ?? ''));
      $scope  = trim((string)($request['scope'] ?? 'recent'));
      return doGamesList($page, $ps, $query, $scope);
    }
    case 'game_details': {
      $id = (int)($request['id'] ?? 0);
      error_log('[DB listener] game_details for id=' . $id);
      return doGameDetails($id);
    }
    default:
      return ['success' => false, 'message' => 'ERROR: unknown type'];
  }
}

$server = new rabbitMQServer("testRabbitMQ.ini", "loginServer");
echo "testRabbitMQServer BEGIN\n";
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END\n";
?>