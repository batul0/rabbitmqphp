<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/db_ingest.log');
ini_set('memory_limit', '512M');
set_time_limit(0);

// AMQP PHP INI (process-level)
ini_set('amqp.timeout', '30');
ini_set('amqp.read_timeout', '30');
ini_set('amqp.write_timeout', '30');
ini_set('amqp.heartbeat', '30');

require_once(__DIR__ . '/path.inc');
require_once(__DIR__ . '/get_host_info.inc');
require_once(__DIR__ . '/rabbitMQLib.inc');

// ---------- DB CONFIG ----------
const DB_HOST = '100.76.74.77';
const DB_NAME = 'loginDB';
const DB_USER = 'loginapp';
const DB_PASS = 'loginappPass123';

function getPDO(): PDO {
  $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
  $opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_notes = 0",
  ];
  return new PDO($dsn, DB_USER, DB_PASS, $opts);
}

// --- upsert from section above ---
function upsertGame(PDO $pdo, array $g): void {
  try {
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
    $plats  = json_encode($g['platforms'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $genres = json_encode($g['genres'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt->execute([
      ':rid'    => $g['rawg_id'],
      ':name'   => $g['name'] ?? '',
      ':rel'    => $g['released'] ?? null,
      ':rating' => isset($g['rating']) ? $g['rating'] : null,
      ':img'    => $g['background_image'] ?? null,
      ':plats'  => $plats,
      ':genres' => $genres,
    ]);
  } catch (Throwable $e) {
    error_log('[UPSERT] rawg_id=' . ($g['rawg_id'] ?? 'NULL') .
              ' name="' . ($g['name'] ?? '') . '" failed: ' . $e->getMessage());
  }
}

function logmsg(string $m): void {
  $ts = gmdate('Y-m-d H:i:s') . ' UTC';
  error_log("[$ts] $m");
  echo "$m\n";
}

function fetchPageViaDMZ(rabbitMQClient $dmz, int $page, int $pageSize): ?array {
  $req = [
    'type'     => 'fetch_games',
    'page'     => $page,
    'pageSize' => $pageSize,
    'ordering' => '-added',
  ];
  // up to 3 tries with backoff
  $tries = 0;
  while ($tries < 3) {
    $tries++;
    logmsg("Requesting DMZ page=$page size=$pageSize (try $tries)");
    $res = $dmz->send_request($req);
    if (is_array($res) && !empty($res['success'])) return $res;

    logmsg("DMZ page=$page failed or empty, sleeping before retry...");
    sleep($tries * 2);
  }
  return null;
}

// -------- main --------
$startPage = isset($argv[1]) ? max(1, (int)$argv[1]) : 1;
$maxPages  = isset($argv[2]) ? max(1, (int)$argv[2]) : 250;
$pageSize  = 20; // conservative; RAWG allows up to 40

logmsg("INGEST start: startPage=$startPage maxPages=$maxPages pageSize=$pageSize");

$pdo = getPDO();

// Reuse one DMZ client/connection
$dmz = new rabbitMQClient(__DIR__ . '/testRabbitMQ.ini', 'dmzServer');

$totalInserted = 0;
$page = $startPage;
$done = 0;

while ($done < $maxPages) {
  $res = fetchPageViaDMZ($dmz, $page, $pageSize);
  if ($res === null) {
    logmsg("Give up on page=$page after retries");
    break;
  }

  $items = $res['items'] ?? [];
  $count = count($items);
  logmsg("DMZ page=$page returned $count items");

  if ($count === 0) {
    logmsg("0 items => stopping");
    break;
  }

  // upsert each item
  $ins = 0;
  foreach ($items as $g) {
    if (!empty($g['rawg_id'])) { upsertGame($pdo, $g); $ins++; }
  }
  $totalInserted += $ins;
  logmsg("Upserted $ins items this page (total so far=$totalInserted)");

  // stopping condition: fewer than a full page
  if ($count < $pageSize) {
    logmsg("Short page ($count<$pageSize) => stopping");
    break;
  }

  // advance
  $page++;
  $done++;

  // polite pause & keep connections alive
  sleep(1);
}

$finalCount = (int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn();
logmsg("INGEST finished. inserted=$totalInserted rows_now=$finalCount");
