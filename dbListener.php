#!/usr/bin/php
<?php
declare(strict_types=1);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

const DB_HOST = '100.76.74.77';
const DB_NAME = 'loginDB';
const DB_USER = 'loginapp';
const DB_PASS = 'loginappPass123';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
function resolveUserIdFromSession(string $sessionId): ?int {
  $sessionId = trim($sessionId);
  if ($sessionId === '') return null;
  try {
    $pdo = getPDO();
    $q = $pdo->prepare(
      'SELECT u.id
         FROM sessions s
         JOIN users u ON u.id = s.user_id
        WHERE s.session_key = ? AND s.expires_at > NOW()
        LIMIT 1'
    );
    $q->execute([$sessionId]);
    $row = $q->fetch();
    return $row ? (int)$row['id'] : null;
  } catch (Throwable $e) {
    error_log('[resolveUserIdFromSession] error: ' . $e->getMessage());
    return null;
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


function ensureGamesHasUserRating(PDO $pdo): void {
  try {
    $pdo->exec("ALTER TABLE games ADD COLUMN user_rating DECIMAL(3,1) NULL DEFAULT NULL");
  } catch (Throwable $e) {
  }
}

function upsertGame(PDO $pdo, array $g): void {
  $stmt = $pdo->prepare(
    'INSERT INTO games (rawg_id, name, released, rating, background_image, platforms, genres)
     VALUES (:rid, :name, :rel, :rating, :img, :plats, :genres)
     ON DUPLICATE KEY UPDATE
       name=VALUES(name), released=VALUES(released), rating=VALUES(rating),
       background_image=VALUES(background_image), platforms=VALUES(platforms), genres=VALUES(genres)'
  );

  $platNames = [];
  if (!empty($g['platforms']) && is_array($g['platforms'])) {
    foreach ($g['platforms'] as $p) {
      if (is_array($p) && !empty($p['platform']['name'])) $platNames[] = $p['platform']['name'];
      else if (is_array($p) && !empty($p['name'])) $platNames[] = $p['name'];
      else if (is_string($p)) $platNames[] = $p;
    }
  }
  $genreNames = [];
  if (!empty($g['genres']) && is_array($g['genres'])) {
    foreach ($g['genres'] as $gn) {
      if (is_array($gn) && !empty($gn['name'])) $genreNames[] = $gn['name'];
      else if (is_string($gn)) $genreNames[] = $gn;
    }
  }

  $stmt->execute([
    ':rid'   => $g['rawg_id'] ?? null,
    ':name'  => $g['name'] ?? '',
    ':rel'   => $g['released'] ?? null,
    ':rating'=> isset($g['rating']) ? $g['rating'] : null,
    ':img'   => $g['background_image'] ?? null,
    ':plats' => json_encode($platNames, JSON_UNESCAPED_UNICODE),
    ':genres'=> json_encode($genreNames, JSON_UNESCAPED_UNICODE),
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
      'user_rating'      => isset($r['user_rating']) ? (float)$r['user_rating'] : null,
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
      'user_rating'      => isset($r['user_rating']) ? (float)$r['user_rating'] : null,
      'background_image' => $r['background_image'],
      'platforms'        => json_decode($r['platforms'] ?? '[]', true) ?: [],
      'genres'           => json_decode($r['genres'] ?? '[]', true) ?: [],
    ];
  }
  $totalPages = max(1, (int)ceil($total / $pageSize));
  return [$items, $total, $totalPages];
}


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

    $items = $dmzRes['items'] ?? [];

  
    try {
      $pdo = getPDO();
      ensureGamesHasUserRating($pdo);
      foreach ($items as $g) {
        if (!empty($g['rawg_id'])) upsertGame($pdo, $g);
      }
      $ids = array_values(array_unique(array_map(fn($g)=> (int)($g['rawg_id'] ?? 0), $items)));
      if ($ids) {
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $q   = $pdo->prepare("SELECT rawg_id, user_rating FROM games WHERE rawg_id IN ($in)");
        $q->execute($ids);
        $map = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
          $map[(int)$r['rawg_id']] = is_null($r['user_rating']) ? null : (float)$r['user_rating'];
        }
        foreach ($items as &$g) {
          $rid = (int)($g['rawg_id'] ?? 0);
          if ($rid && array_key_exists($rid, $map) && $map[$rid] !== null) {
            $g['user_rating'] = $map[$rid];
          }
        }
        unset($g);
      }
    } catch (Throwable $e) {
      error_log('[doGamesSearch] upsert/enrich warn: ' . $e->getMessage());
    }

    return [
      'success'    => true,
      'items'      => $items,
      'page'       => $dmzRes['page'] ?? $page,
      'pageSize'   => $dmzRes['pageSize'] ?? $pageSize,
      'total'      => $dmzRes['total'] ?? null,
      'totalPages' => $dmzRes['totalPages'] ?? ($dmzRes['next'] ? ($page+1) : $page),
      'source'     => 'dmz+db'
    ];
  } catch (Throwable $e) {
    error_log('[doGamesSearch] error: ' . $e->getMessage());
    return ['success' => false, 'message' => 'Server error'];
  }
}

function doGamesList(int $page, int $pageSize, string $query, string $scope = 'recent'): array {
  try {
    $pdo = getPDO();
    ensureGamesHasUserRating($pdo);

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

    list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages,'source'=>'db'];

  } catch (Throwable $e) {
    error_log('[doGamesList] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

/* ===== Details cache ===== */
function ensureDetailsTable(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS game_details (
      rawg_id BIGINT PRIMARY KEY,
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
      $platforms = [];
      if (!empty($item['platforms']) && is_array($item['platforms'])) {
        foreach ($item['platforms'] as $p) {
          if (!empty($p['platform']['name'])) $platforms[] = $p['platform']['name'];
        }
      }
      $genres = [];
      if (!empty($item['genres']) && is_array($item['genres'])) {
        foreach ($item['genres'] as $gn) {
          if (!empty($gn['name'])) $genres[] = $gn['name'];
        }
      }

      upsertGame($pdo, [
        'rawg_id'          => $item['rawg_id'],
        'name'             => $item['name'] ?? '',
        'released'         => $item['released'] ?? null,
        'rating'           => $item['rating'] ?? null,
        'background_image' => $item['background_image'] ?? null,
        'platforms'        => $platforms,
        'genres'           => $genres,
      ]);
    }

    return ['success'=>true, 'item'=>$item, 'source'=>'dmz'];
  } catch (Throwable $e) {
    error_log('[doGameDetails] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}


function likeToggle(string $sessionId, int $rawgId, bool $on): array {
  if ($rawgId <= 0) return ['success'=>false,'message'=>'Invalid rawg id'];
  $userId = resolveUserIdFromSession($sessionId);
  if (!$userId) return ['success'=>false,'message'=>'Unauthorized'];

  try {
    $pdo = getPDO();
    if ($on) {
      $stmt = $pdo->prepare('INSERT IGNORE INTO liked_games (user_id, rawg_id) VALUES (?, ?)');
      $stmt->execute([$userId, $rawgId]);
      return ['success'=>true,'message'=>'Liked'];
    } else {
      $stmt = $pdo->prepare('DELETE FROM liked_games WHERE user_id = ? AND rawg_id = ?');
      $stmt->execute([$userId, $rawgId]);
      return ['success'=>true,'message'=>'Unliked'];
    }
  } catch (Throwable $e) {
    error_log('[likeToggle] error: ' . $e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}
function wishlistToggle(string $sessionId, int $rawgId, bool $on): array {
  if ($rawgId <= 0) return ['success'=>false,'message'=>'Invalid rawg id'];
  $userId = resolveUserIdFromSession($sessionId);
  if (!$userId) return ['success'=>false,'message'=>'Unauthorized'];

  try {
    $pdo = getPDO();
    if ($on) {
      $stmt = $pdo->prepare('INSERT IGNORE INTO wishlist_games (user_id, rawg_id) VALUES (?, ?)');
      $stmt->execute([$userId, $rawgId]);
      return ['success'=>true,'message'=>'Added to wishlist'];
    } else {
      $stmt = $pdo->prepare('DELETE FROM wishlist_games WHERE user_id = ? AND rawg_id = ?');
      $stmt->execute([$userId, $rawgId]);
      return ['success'=>true,'message'=>'Removed from wishlist'];
    }
  } catch (Throwable $e) {
    error_log('[wishlistToggle] error: ' . $e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}
function playedToggle(string $sessionId, int $rawgId, bool $on): array {
  if ($rawgId <= 0) return ['success'=>false,'message'=>'Invalid rawg id'];
  $userId = resolveUserIdFromSession($sessionId);
  if (!$userId) return ['success'=>false,'message'=>'Unauthorized'];

  try {
    $pdo = getPDO();
    if ($on) {
      $stmt = $pdo->prepare('INSERT IGNORE INTO played_games (user_id, rawg_id) VALUES (?, ?)');
      $stmt->execute([$userId, $rawgId]);
      return ['success'=>true,'message'=>'Marked as played'];
    } else {
      $stmt = $pdo->prepare('DELETE FROM played_games WHERE user_id = ? AND rawg_id = ?');
      $stmt->execute([$userId, $rawgId]);
      return ['success'=>true,'message'=>'Removed from played'];
    }
  } catch (Throwable $e) {
    error_log('[playedToggle] error: ' . $e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}


function likeList(string $sessionId, int $page = 1, int $pageSize = 24): array {
  $userId = resolveUserIdFromSession($sessionId);
  if (!$userId) return ['success'=>false,'message'=>'Unauthorized'];
  $page = max(1,$page);
  $pageSize = max(1,min(100,$pageSize));
  $offset = ($page-1)*$pageSize;

  try {
    $pdo = getPDO();

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM liked_games WHERE user_id = ?');
    $cnt->execute([$userId]);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare(
      'SELECT lg.rawg_id,
              g.name,
              g.released,
              g.rating,
              g.user_rating,
              g.background_image,
              g.platforms,
              g.genres
         FROM liked_games lg
         LEFT JOIN games g ON g.rawg_id = lg.rawg_id
        WHERE lg.user_id = ?
        ORDER BY lg.created_at DESC
        LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,   PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'id'               => (int)$r['rawg_id'],
        'rawg_id'          => (int)$r['rawg_id'],
        'name'             => $r['name'] ?? null,
        'released'         => $r['released'] ?? null,
        'rating'           => isset($r['rating']) ? (float)$r['rating'] : null,
        'user_rating'      => isset($r['user_rating']) ? (float)$r['user_rating'] : null,
        'background_image' => $r['background_image'] ?? null,
        'platforms'        => $r['platforms'] ? (json_decode($r['platforms'], true) ?: []) : [],
        'genres'           => $r['genres'] ? (json_decode($r['genres'], true) ?: []) : [],
      ];
    }

    $totalPages = max(1, (int)ceil($total / $pageSize));
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages];

  } catch (Throwable $e) {
    error_log('[likeList] error: ' . $e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

function wishlistList(string $sessionId, int $page = 1, int $pageSize = 24): array {
  $userId = resolveUserIdFromSession($sessionId);
  if (!$userId) return ['success'=>false,'message'=>'Unauthorized'];

  $page = max(1,$page);
  $pageSize = max(1,min(100,$pageSize));
  $offset = ($page-1)*$pageSize;

  try {
    $pdo = getPDO();

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM wishlist_games WHERE user_id = ?');
    $cnt->execute([$userId]);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare(
      'SELECT wg.rawg_id,
              g.name,
              g.released,
              g.rating,
              g.user_rating,
              g.background_image,
              g.platforms,
              g.genres
         FROM wishlist_games wg
         LEFT JOIN games g ON g.rawg_id = wg.rawg_id
        WHERE wg.user_id = ?
        ORDER BY wg.created_at DESC
        LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,   PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'id'               => (int)$r['rawg_id'],
        'rawg_id'          => (int)$r['rawg_id'],
        'name'             => $r['name'] ?? null,
        'released'         => $r['released'] ?? null,
        'rating'           => isset($r['rating']) ? (float)$r['rating'] : null,
        'user_rating'      => isset($r['user_rating']) ? (float)$r['user_rating'] : null,
        'background_image' => $r['background_image'] ?? null,
        'platforms'        => $r['platforms'] ? (json_decode($r['platforms'], true) ?: []) : [],
        'genres'           => $r['genres'] ? (json_decode($r['genres'], true) ?: []) : [],
      ];
    }

    $totalPages = max(1, (int)ceil($total / $pageSize));
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages];

  } catch (Throwable $e) {
    error_log('[wishlistList] error: ' . $e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

function playedList(string $sessionId, int $page = 1, int $pageSize = 24): array {
  $userId = resolveUserIdFromSession($sessionId);
  if (!$userId) return ['success'=>false,'message'=>'Unauthorized'];

  $page = max(1,$page);
  $pageSize = max(1,min(100,$pageSize));
  $offset = ($page-1)*$pageSize;

  try {
    $pdo = getPDO();

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM played_games WHERE user_id = ?');
    $cnt->execute([$userId]);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare(
      'SELECT pg.rawg_id,
              g.name,
              g.released,
              g.rating,
              g.user_rating,
              g.background_image,
              g.platforms,
              g.genres
         FROM played_games pg
         LEFT JOIN games g ON g.rawg_id = pg.rawg_id
        WHERE pg.user_id = ?
        ORDER BY pg.created_at DESC
        LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,   PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'id'               => (int)$r['rawg_id'],
        'rawg_id'          => (int)$r['rawg_id'],
        'name'             => $r['name'] ?? null,
        'released'         => $r['released'] ?? null,
        'rating'           => isset($r['rating']) ? (float)$r['rating'] : null,
        'user_rating'      => isset($r['user_rating']) ? (float)$r['user_rating'] : null,
        'background_image' => $r['background_image'] ?? null,
        'platforms'        => $r['platforms'] ? (json_decode($r['platforms'], true) ?: []) : [],
        'genres'           => $r['genres'] ? (json_decode($r['genres'], true) ?: []) : [],
      ];
    }

    $totalPages = max(1, (int)ceil($total / $pageSize));
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages];

  } catch (Throwable $e) {
    error_log('[playedList] error: ' . $e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}


function ensureRatingsTable(PDO $pdo): void {
 
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS ratings (
      user_id    INT NOT NULL,
      rawg_id    BIGINT UNSIGNED NOT NULL,
      value      DECIMAL(3,1) NOT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, rawg_id),
      INDEX idx_ratings_rawg (rawg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
 
  try {
    $pdo->exec("ALTER TABLE ratings ADD CONSTRAINT fk_ratings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
  } catch (Throwable $e) {
 
  }
}

function doRatingSet(string $sessionId, int $rawgId, float $value): array {
  if ($rawgId <= 0 || $value < 0.5 || $value > 5.0) {
    return ['success'=>false,'message'=>'Invalid rating'];
  }
  try {
    $pdo = getPDO();
    ensureRatingsTable($pdo);
    ensureGamesHasUserRating($pdo);

    $uid = resolveUserIdFromSession($sessionId);
    if (!$uid) return ['success'=>false,'message'=>'Invalid/expired session'];

   
    $stmt = $pdo->prepare("
      INSERT INTO ratings (user_id, rawg_id, value) VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$uid, $rawgId, $value]);


    $avg = (float)$pdo->query("SELECT ROUND(AVG(value),1) FROM ratings WHERE rawg_id = ".((int)$rawgId))->fetchColumn();

   
    $upg = $pdo->prepare("UPDATE games SET user_rating = ? WHERE rawg_id = ?");
    $upg->execute([$avg, $rawgId]);

    
    try {
      ensureDetailsTable($pdo);
      $q = $pdo->prepare("SELECT details_json FROM game_details WHERE rawg_id = ? LIMIT 1");
      $q->execute([$rawgId]);
      if ($row = $q->fetch()) {
        $json = json_decode($row['details_json'], true);
        if (is_array($json)) {
          $json['user_rating'] = $avg;
          $u = $pdo->prepare("
            UPDATE game_details SET details_json = ?, updated_at = NOW()
            WHERE rawg_id = ?
          ");
          $u->execute([json_encode($json, JSON_UNESCAPED_UNICODE), $rawgId]);
        }
      }
    } catch (Throwable $e) {
      error_log('[doRatingSet] cache update warn: '.$e->getMessage());
    }

    return ['success'=>true, 'user_value'=>$value, 'avg'=>$avg];

  } catch (Throwable $e) {
    error_log('[doRatingSet] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}


function ensureLikesTable(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS liked_games (
      user_id  INT NOT NULL,
      rawg_id  BIGINT UNSIGNED NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, rawg_id),
      INDEX idx_liked_rawg (rawg_id),
      CONSTRAINT fk_liked_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}
function getUserIdBySession(PDO $pdo, string $sessionId): ?int {
  $q = $pdo->prepare('SELECT u.id FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.session_key=? AND s.expires_at > NOW() LIMIT 1');
  $q->execute([$sessionId]);
  $uid = $q->fetchColumn();
  return $uid ? (int)$uid : null;
}
function doLikeToggle(string $sessionId, int $rawg_id, int $liked, array $meta = []): array {
  if ($rawg_id <= 0) return ['success'=>false,'message'=>'Invalid rawg_id'];
  try {
    $pdo = getPDO();
    ensureLikesTable($pdo);

    $uid = getUserIdBySession($pdo, $sessionId);
    if (!$uid) return ['success'=>false,'message'=>'Invalid/expired session'];

    try {
      $name  = trim((string)($meta['name'] ?? ''));
      $img   = trim((string)($meta['background_image'] ?? ''));
      $rel   = trim((string)($meta['released'] ?? ''));
      $rate  = $meta['rating'] ?? null;

      if ($name !== '' || $img !== '' || $rel !== '' || $rate !== null) {
        upsertGame($pdo, [
          'rawg_id'          => $rawg_id,
          'name'             => $name,
          'released'         => ($rel !== '' ? $rel : null),
          'rating'           => ($rate !== '' ? (float)$rate : null),
          'background_image' => ($img !== '' ? $img : null),
          'platforms'        => [],
          'genres'           => [],
        ]);
      }
    } catch (Throwable $e) {
      error_log('[doLikeToggle] upsertGame warn: '.$e->getMessage());
    }

    if ($liked) {
      $ins = $pdo->prepare('INSERT IGNORE INTO liked_games (user_id, rawg_id) VALUES (?, ?)');
      $ins->execute([$uid, $rawg_id]);
    } else {
      $del = $pdo->prepare('DELETE FROM liked_games WHERE user_id = ? AND rawg_id = ?');
      $del->execute([$uid, $rawg_id]);
    }
    return ['success'=>true, 'liked'=>$liked ? 1 : 0];
  } catch (Throwable $e) {
    error_log('[doLikeToggle] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}
function doLikeList(string $sessionId, int $page, int $pageSize): array {
  try {
    $pdo = getPDO();
    ensureLikesTable($pdo);

    $uid = getUserIdBySession($pdo, $sessionId);
    if (!$uid) return ['success'=>false,'message'=>'Invalid/expired session'];

    $page     = max(1, $page);
    $pageSize = max(1, min(60, $pageSize));
    $offset   = ($page - 1) * $pageSize;

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM liked_games WHERE user_id = ?');
    $cnt->execute([$uid]);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare("
      SELECT g.rawg_id, g.name, g.released, g.rating, g.user_rating, g.background_image, g.platforms, g.genres
      FROM liked_games lg
      LEFT JOIN games g ON g.rawg_id = lg.rawg_id
      WHERE lg.user_id = ?
      ORDER BY COALESCE(g.name, '') ASC
      LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'id'               => isset($r['rawg_id']) ? (int)$r['rawg_id'] : null,
        'rawg_id'          => isset($r['rawg_id']) ? (int)$r['rawg_id'] : null,
        'name'             => $r['name'] ?? 'Untitled',
        'released'         => $r['released'] ?? null,
        'rating'           => is_null($r['rating']) ? null : (float)$r['rating'],
        'user_rating'      => isset($r['user_rating']) ? (float)$r['user_rating'] : null,
        'background_image' => $r['background_image'] ?? null,
        'platforms'        => json_decode($r['platforms'] ?? '[]', true) ?: [],
        'genres'           => json_decode($r['genres'] ?? '[]', true) ?: [],
      ];
    }
    $totalPages = max(1, (int)ceil($total / $pageSize));
    return ['success'=>true, 'items'=>$items, 'page'=>$page, 'pageSize'=>$pageSize, 'total'=>$total, 'totalPages'=>$totalPages];
  } catch (Throwable $e) {
    error_log('[doLikeList] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

function forumCreate(string $sessionId, int $rawgId, string $title, string $description): array {
  $title = trim($title);
  $description = trim($description);
  if ($rawgId <= 0 || $title === '') {
    return ['success'=>false,'message'=>'Missing game or title'];
  }
  try {
    $pdo = getPDO();
    ensureForumsTables($pdo);

    $uid = resolveUserIdFromSession($sessionId);
    if (!$uid) return ['success'=>false,'message'=>'Invalid/expired session'];

    // Ensure game row exists to enrich forum cards later (best-effort)
    try {
      $gq = $pdo->prepare('SELECT 1 FROM games WHERE rawg_id = ?');
      $gq->execute([$rawgId]);
      if (!$gq->fetchColumn()) {
        // insert stub game row so FK-less join still has data later
        upsertGame($pdo, ['rawg_id'=>$rawgId, 'name'=>'', 'background_image'=>null, 'platforms'=>[], 'genres'=>[]]);
      }
    } catch (Throwable $e) { /* ignore */ }

    $ins = $pdo->prepare("
      INSERT INTO forums (rawg_id, created_by, title, description)
      VALUES (?, ?, ?, ?)
    ");
    $ins->execute([$rawgId, $uid, $title, $description]);

    $forumId = (int)$pdo->lastInsertId();
    return ['success'=>true,'forum_id'=>$forumId];
  } catch (PDOException $e) {
    if ($e->getCode() === '23000') {
      return ['success'=>false,'message'=>'You already created a forum with the same title for this game'];
    }
    error_log('[forumCreate] DB error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  } catch (Throwable $e) {
    error_log('[forumCreate] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

function forumList(int $page = 1, int $pageSize = 24, ?int $rawgId = null): array {
  $page = max(1,$page);
  $pageSize = max(1,min(100,$pageSize));
  $offset = ($page-1)*$pageSize;

  try {
    $pdo = getPDO();
    ensureForumsTables($pdo);

    if ($rawgId) {
      $cnt = $pdo->prepare('SELECT COUNT(*) FROM forums WHERE rawg_id = ?');
      $cnt->execute([$rawgId]);
    } else {
      $cnt = $pdo->query('SELECT COUNT(*) FROM forums');
    }
    $total = (int)$cnt->fetchColumn();

    $sql = "
      SELECT f.id, f.rawg_id, f.title, f.description, f.created_at, f.updated_at,
             u.username AS author,
             g.name AS game_name, g.background_image AS game_image,
             COALESCE(g.user_rating, g.rating) AS game_rating,
             (SELECT COUNT(*) FROM forum_messages m WHERE m.forum_id = f.id) AS message_count
        FROM forums f
        JOIN users u ON u.id = f.created_by
        LEFT JOIN games g ON g.rawg_id = f.rawg_id
    ";
    $params = [];
    if ($rawgId) { $sql .= " WHERE f.rawg_id = ?"; $params[] = $rawgId; }
    $sql .= " ORDER BY f.updated_at DESC, f.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $pageSize; $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $i=>$v) {
      $stmt->bindValue($i+1, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'id' => (int)$r['id'],
        'rawg_id' => (int)$r['rawg_id'],
        'title' => $r['title'],
        'description' => $r['description'],
        'author' => $r['author'],
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
        'game_name' => $r['game_name'],
        'game_image' => $r['game_image'],
        'game_rating' => isset($r['game_rating']) ? (float)$r['game_rating'] : null,
        'message_count' => (int)$r['message_count'],
      ];
    }

    $totalPages = max(1, (int)ceil($total / $pageSize));
    return ['success'=>true,'items'=>$items,'page'=>$page,'pageSize'=>$pageSize,'total'=>$total,'totalPages'=>$totalPages];

  } catch (Throwable $e) {
    error_log('[forumList] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

function forumGet(int $forumId, int $page = 1, int $pageSize = 50): array {
  if ($forumId <= 0) return ['success'=>false,'message'=>'Invalid forum'];
  $page = max(1,$page);
  $pageSize = max(1,min(200,$pageSize));
  $offset = ($page-1)*$pageSize;

  try {
    $pdo = getPDO();
    ensureForumsTables($pdo);

    $f = $pdo->prepare("
      SELECT f.id, f.rawg_id, f.title, f.description, f.created_at, f.updated_at,
             u.username AS author,
             g.name AS game_name, g.background_image AS game_image,
             COALESCE(g.user_rating, g.rating) AS game_rating
        FROM forums f
        JOIN users u ON u.id = f.created_by
        LEFT JOIN games g ON g.rawg_id = f.rawg_id
       WHERE f.id = ?
       LIMIT 1
    ");
    $f->execute([$forumId]);
    $forum = $f->fetch();
    if (!$forum) return ['success'=>false,'message'=>'Forum not found'];

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM forum_messages WHERE forum_id = ?');
    $cnt->execute([$forumId]);
    $total = (int)$cnt->fetchColumn();

    $m = $pdo->prepare("
      SELECT m.id, m.message, m.created_at, u.username
        FROM forum_messages m
        JOIN users u ON u.id = m.user_id
       WHERE m.forum_id = ?
       ORDER BY m.created_at ASC
       LIMIT ? OFFSET ?
    ");
    $m->bindValue(1, $forumId, PDO::PARAM_INT);
    $m->bindValue(2, $pageSize, PDO::PARAM_INT);
    $m->bindValue(3, $offset, PDO::PARAM_INT);
    $m->execute();

    $msgs = [];
    foreach ($m->fetchAll() as $row) {
      $msgs[] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'message' => $row['message'],
        'created_at' => $row['created_at'],
      ];
    }
    $totalPages = max(1, (int)ceil($total / $pageSize));

    return [
      'success'=>true,
      'forum'=>[
        'id'=>(int)$forum['id'],
        'rawg_id'=>(int)$forum['rawg_id'],
        'title'=>$forum['title'],
        'description'=>$forum['description'],
        'author'=>$forum['author'],
        'created_at'=>$forum['created_at'],
        'updated_at'=>$forum['updated_at'],
        'game_name'=>$forum['game_name'],
        'game_image'=>$forum['game_image'],
        'game_rating'=> isset($forum['game_rating']) ? (float)$forum['game_rating'] : null
      ],
      'messages'=>$msgs,
      'page'=>$page,
      'pageSize'=>$pageSize,
      'total'=>$total,
      'totalPages'=>$totalPages
    ];

  } catch (Throwable $e) {
    error_log('[forumGet] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

function forumPostMessage(string $sessionId, int $forumId, string $message): array {
  $message = trim($message);
  if ($forumId <= 0 || $message === '') return ['success'=>false,'message'=>'Empty message'];

  try {
    $pdo = getPDO();
    ensureForumsTables($pdo);

    $uid = resolveUserIdFromSession($sessionId);
    if (!$uid) return ['success'=>false,'message'=>'Invalid/expired session'];

    // ensure forum exists
    $chk = $pdo->prepare('SELECT id FROM forums WHERE id = ?');
    $chk->execute([$forumId]);
    if (!$chk->fetchColumn()) return ['success'=>false,'message'=>'Forum not found'];

    $ins = $pdo->prepare('INSERT INTO forum_messages (forum_id, user_id, message) VALUES (?,?,?)');
    $ins->execute([$forumId, $uid, $message]);

    // touch forum.updated_at so it floats up in list
    $pdo->prepare('UPDATE forums SET updated_at = NOW() WHERE id = ?')->execute([$forumId]);

    return ['success'=>true, 'message_id'=>(int)$pdo->lastInsertId()];
  } catch (Throwable $e) {
    error_log('[forumPostMessage] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}

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

   
    case 'like_toggle': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $rawgId    = (int)($request['rawg_id'] ?? 0);
      $on        = isset($request['on']) ? (bool)$request['on'] : true;
      return likeToggle($sessionId, $rawgId, $on);
    }
    case 'like_list': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $page      = (int)($request['page'] ?? 1);
      $ps        = (int)($request['pageSize'] ?? 24);
      return likeList($sessionId, $page, $ps);
    }
    case 'wishlist_toggle': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $rawgId    = (int)($request['rawg_id'] ?? 0);
      $on        = isset($request['on']) ? (bool)$request['on'] : (bool)($request['wanted'] ?? 1);
      return wishlistToggle($sessionId, $rawgId, $on);
    }
    case 'wishlist_list': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $page      = (int)($request['page'] ?? 1);
      $ps        = (int)($request['pageSize'] ?? 24);
      return wishlistList($sessionId, $page, $ps);
    }
    case 'played_toggle': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $rawgId    = (int)($request['rawg_id'] ?? 0);
      $on        = isset($request['on']) ? (bool)$request['on'] : (bool)($request['played'] ?? 1);
      return playedToggle($sessionId, $rawgId, $on);
    }
    case 'played_list': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $page      = (int)($request['page'] ?? 1);
      $ps        = (int)($request['pageSize'] ?? 24);
      return playedList($sessionId, $page, $ps);
    }
    case 'rating_set': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $rawgId    = (int)($request['rawg_id'] ?? 0);
      $value     = (float)($request['value'] ?? 0);
      return doRatingSet($sessionId, $rawgId, $value);
    }
    case 'forum_create': {
      $sessionId   = (string)($request['sessionId'] ?? '');
      $rawgId      = (int)($request['rawg_id'] ?? 0);
      $title       = (string)($request['title'] ?? '');
      $description = (string)($request['description'] ?? '');
      return forumCreate($sessionId, $rawgId, $title, $description);
    }
    case 'forum_list': {
      $page   = (int)($request['page'] ?? 1);
      $ps     = (int)($request['pageSize'] ?? 24);
      $rawgId = isset($request['rawg_id']) ? (int)$request['rawg_id'] : null;
      return forumList($page, $ps, $rawgId);
    }
    case 'forum_get': {
      $forumId = (int)($request['forum_id'] ?? 0);
      $page    = (int)($request['page'] ?? 1);
      $ps      = (int)($request['pageSize'] ?? 50);
      return forumGet($forumId, $page, $ps);
    }
    case 'forum_post_message': {
      $sessionId = (string)($request['sessionId'] ?? '');
      $forumId   = (int)($request['forum_id'] ?? 0);
      $message   = (string)($request['message'] ?? '');
      return forumPostMessage($sessionId, $forumId, $message);
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
