#!/usr/bin/php
<?php
declare(strict_types=1);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');


/* =======================
  CONFIG: MySQL settings
  ======================= */
const DB_HOST = '100.76.74.77';     // change to your DB VM IP if this runs on a different VM
const DB_NAME = 'loginDB';
const DB_USER = 'loginapp';
const DB_PASS = 'loginappPass123';


/**
* Get a PDO connection to MySQL
*/
function getPDO(): PDO {
   $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
   $options = [
       PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
       PDO::ATTR_EMULATE_PREPARES   => false,
       PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_notes = 0"
   ];
   return new PDO($dsn, DB_USER, DB_PASS, $options);
}


/**
* Verify login against DB (username unique, password stored as password_hash())
* Returns an array payload suitable to json_encode and send back through RabbitMQ.
*/
function doLogin(string $username, string $password): array {
   if ($username === '' || $password === '') {
       return ['success' => false, 'message' => 'Username and password required'];
   }


   try {
       $pdo = getPDO();


       // Fetch the (hashed) password for this username
       $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
       $stmt->execute([$username]);
       $row = $stmt->fetch();


       if (!$row) {
           // Avoid leaking which part failed
           return ['success' => false, 'message' => 'Invalid credentials'];
       }


       $hash = $row['password'] ?? '';
       if ($hash === '' || !password_verify($password, $hash)) {
           return ['success' => false, 'message' => 'Invalid credentials'];
       }


       // Optional: You can rotate/rehash if algorithm updated
       if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
           $newHash = password_hash($password, PASSWORD_DEFAULT);
           $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
           $upd->execute([$newHash, $row['id']]);
       }


       $key = bin2hex(random_bytes(32));
       $exp = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

        $ins = $pdo->prepare('INSERT INTO sessions (user_id, session_key, expires_at) VALUES (?,?,?)');
        $ins->execute([$row['id'], $key, $exp]);

        return [
        'success'      => true,
        'message'      => 'Login successful',
        'username'     => $row['username'],
        'session_key'  => $key,        // <-- send back to webserver
        'expires_at'   => $exp
        ];


   } catch (Throwable $e) {
       // Log server-side; return generic error to client
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

        // Check if username exists
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $chk->execute([$username]);
        if ($chk->fetchColumn()) {
            return ['success' => false, 'message' => 'Username already exists'];
        }

        // Hash and insert
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins  = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
        $ins->execute([$username, $hash]);

        return ['success' => true, 'message' => 'Registration successful'];
    } catch (PDOException $e) {
        // Handle unique constraint race condition gracefully
        if ($e->getCode() === '23000') { // integrity constraint violation
            return ['success' => false, 'message' => 'Username already exists'];
        }
        error_log('[doRegister] DB error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    } catch (Throwable $e) {
        error_log('[doRegister] error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    }
}


function doValidate(string $sessionId): array {
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return ['success' => false, 'message' => 'No session'];
    }

    try {
        $pdo = getPDO();
        $q = $pdo->prepare(
            'SELECT u.id, u.username
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.session_key = ? AND s.expires_at > NOW()
             LIMIT 1'
        );
        $q->execute([$sessionId]);
        $row = $q->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'Invalid/expired session'];
        }

        // (Optional) sliding expiration:
        // $newExp = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
        // $upd = $pdo->prepare('UPDATE sessions SET expires_at=? WHERE session_key=?');
        // $upd->execute([$newExp, $sessionId]);

        return ['success' => true, 'username' => $row['username']];
    } catch (Throwable $e) {
        error_log('[doValidate] DB error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    }
}

function doLogout(string $sessionId): array {
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return ['success' => false, 'message' => 'No session'];
    }
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

function getGamesPageByDates(PDO $pdo, int $page, int $pageSize, string $from, string $to): array {
  $page = max(1, $page);
  $pageSize = max(1, min(50, $pageSize));
  $offset = ($page - 1) * $pageSize;

  $cnt = $pdo->prepare('SELECT COUNT(*) FROM games WHERE released IS NOT NULL AND released BETWEEN ? AND ?');
  $cnt->execute([$from, $to]);
  $total = (int)$cnt->fetchColumn();

  // Order by most recently released first, then name
  $stmt = $pdo->prepare(
    'SELECT * FROM games
     WHERE released IS NOT NULL AND released BETWEEN ? AND ?
     ORDER BY released DESC, name ASC
     LIMIT ? OFFSET ?');
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


function upsertGame(PDO $pdo, array $g): void {
  $stmt = $pdo->prepare(
    'INSERT INTO games (rawg_id, name, released, rating, background_image, platforms, genres)
     VALUES (:rid, :name, :rel, :rating, :img, :plats, :genres)
     ON DUPLICATE KEY UPDATE
       name=VALUES(name),
       released=VALUES(released),
       rating=VALUES(rating),
       background_image=VALUES(background_image),
       platforms=VALUES(platforms),
       genres=VALUES(genres)'
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

function getGamesPage(PDO $pdo, int $page, int $pageSize, string $query): array {
  $page = max(1, $page);
  $pageSize = max(1, min(50, $pageSize));
  $offset = ($page - 1) * $pageSize;

  if ($query !== '') {
    $like = '%' . $query . '%';
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM games WHERE name LIKE ?');
    $cnt->execute([$like]);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare('SELECT * FROM games WHERE name LIKE ? ORDER BY name LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
  } else {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM games ORDER BY name LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
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

function doGamesList(int $page, int $pageSize, string $query, string $scope = 'recent'): array {
  try {
    $pdo = getPDO();

    $page = max(1, $page);
    $pageSize = max(1, min(50, $pageSize));

    $today = new DateTimeImmutable('today');
    $from  = $today->sub(new DateInterval('P30D'))->format('Y-m-d'); // 30 days ago
    $to    = $today->add(new DateInterval('P90D'))->format('Y-m-d'); // 90 days ahead

    $useRecentWindow = ($scope === 'recent' && $query === '');

    // 1) Try DB first
    if ($useRecentWindow) {
      list($items, $total, $totalPages) = getGamesPageByDates($pdo, $page, $pageSize, $from, $to);
    } else {
      list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
    }

    // 2) If DB has nothing, ask DMZ with the right filters
    if (count($items) === 0) {
      $dmz = new rabbitMQClient('testRabbitMQ.ini', 'dmzServer');
      $dmzReq = [
        'type'     => 'fetch_games',
        'page'     => $page,
        'pageSize' => $pageSize,
        'query'    => $query
      ];
      if ($useRecentWindow) {
        $dmzReq['dates']    = $from . ',' . $to; // RAWG supports dates=YYYY-MM-DD,YYYY-MM-DD
        $dmzReq['ordering'] = '-released';       // newest releases first
      }

      $dmzRes = $dmz->send_request($dmzReq);
      if (!is_array($dmzRes) || empty($dmzRes['success'])) {
        return ['success'=>false,'message'=>'DMZ fetch failed'];
      }

      $inserted = 0;
      foreach (($dmzRes['items'] ?? []) as $g) {
        if (empty($g['rawg_id'])) continue;
        upsertGame($pdo, $g);
        $inserted++;
      }

      // Re-query DB for consistent paging/total
      if ($useRecentWindow) {
        list($items, $total, $totalPages) = getGamesPageByDates($pdo, $page, $pageSize, $from, $to);
      } else {
        list($items, $total, $totalPages) = getGamesPage($pdo, $page, $pageSize, $query);
      }

      return [
        'success'    => true,
        'items'      => $items,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'total'      => $total,
        'totalPages' => $totalPages,
        'source'     => ($inserted > 0 ? 'dmz->db' : 'db'),
        'window'     => $useRecentWindow ? ['from'=>$from,'to'=>$to] : null
      ];
    }

    // DB had data
    return [
      'success'    => true,
      'items'      => $items,
      'page'       => $page,
      'pageSize'   => $pageSize,
      'total'      => $total,
      'totalPages' => $totalPages,
      'source'     => 'db',
      'window'     => $useRecentWindow ? ['from'=>$from,'to'=>$to] : null
    ];
  } catch (Throwable $e) {
    error_log('[doGamesList] error: '.$e->getMessage());
    return ['success'=>false,'message'=>'Server error'];
  }
}


/**
* The request dispatcher that RabbitMQ calls per message
*/
function requestProcessor(array $request) {
 echo "Received request:\n";
 var_dump($request);


 if (!isset($request['type'])) {
   return ['success' => false, 'message' => 'ERROR: unsupported message type'];
 }


 switch ($request['type']) {
  case 'login':
    $username = isset($request['username']) ? (string)$request['username'] : '';
    $password = isset($request['password']) ? (string)$request['password'] : '';
    return doLogin($username, $password);

  case 'register':
    $username = isset($request['username']) ? (string)$request['username'] : '';
    $password = isset($request['password']) ? (string)$request['password'] : '';
    return doRegister($username, $password);

  case 'validate_session':
    $sid = isset($request['sessionId']) ? (string)$request['sessionId'] : '';
    return doValidate($sid);

  case 'logout':
    $sid = isset($request['sessionId']) ? (string)$request['sessionId'] : '';
    return doLogout($sid);

  case 'games_list':
    $page   = (int)($request['page'] ?? 1);
    $ps     = (int)($request['pageSize'] ?? 9);
    $query  = trim((string)($request['query'] ?? ''));
    $scope  = trim((string)($request['scope'] ?? 'recent'));   // ⬅️ new
    return doGamesList($page, $ps, $query, $scope);



  default:
    return ['success' => false, 'message' => 'ERROR: unknown type'];
}

}


/**
* Start the RabbitMQ request processor.
* IMPORTANT: the INI section name must match your testRabbitMQ.ini section.
* Your ini shows [loginServer], so use that.
*/
$server = new rabbitMQServer("testRabbitMQ.ini", "loginServer");


echo "testRabbitMQServer BEGIN\n";
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END\n";
exit();
?>
