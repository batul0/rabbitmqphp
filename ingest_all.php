#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * One-time RAWG ingest runner (DB VM)
 * ----------------------------------
 * - Runs on the DB VM
 * - Calls DMZ via RabbitMQ for RAWG pages
 * - Upserts results into MySQL `games`
 *
 * Usage:
 *   ./ingest_all.php                # default startPage=1, maxPages=250, pageSize=40
 *   ./ingest_all.php --start=51     # resume from page 51
 *   ./ingest_all.php --pages=100    # cap to 100 pages this run
 *   ./ingest_all.php --pagesize=40  # RAWG max page_size is 40
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/var/log/db_ingest.log');  // Logs here too

// --- Adjust if your paths differ ---
require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

// --- DB config (same as your dbListener) ---
const DB_HOST = '100.76.74.77';
const DB_NAME = 'loginDB';
const DB_USER = 'loginapp';
const DB_PASS = 'loginappPass123';

// --- RabbitMQ INI section names ---
const DMZ_SECTION   = 'dmzServer';     // in testRabbitMQ.ini on DMZ VM
const INI_FILE_PATH = '/home/vboxuser/git/rabbitmqphp/testRabbitMQ.ini'; // readable from DB VM

// ---------- CLI args ----------
$startPage = 1;
$maxPages  = 250;  // 250 * 40 ≈ 10k rows
$pageSize  = 40;   // RAWG max

foreach ($argv as $arg) {
  if (preg_match('/^--start=(\d+)$/', $arg, $m))   $startPage = max(1, (int)$m[1]);
  if (preg_match('/^--pages=(\d+)$/', $arg, $m))   $maxPages  = max(1, (int)$m[1]);
  if (preg_match('/^--pagesize=(\d+)$/', $arg, $m)) $pageSize = max(1, min(40, (int)$m[1]));
}

// ---------- Helpers ----------
function out(string $msg): void {
  $ts = gmdate('Y-m-d H:i:s').' UTC';
  echo "[$ts] $msg\n";
  error_log($msg);
}

function pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
  $opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_notes = 0",
  ];
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
  return $pdo;
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
    ':rid'    => $g['rawg_id'] ?? null,
    ':name'   => $g['name'] ?? '',
    ':rel'    => $g['released'] ?? null,
    ':rating' => isset($g['rating']) ? $g['rating'] : null,
    ':img'    => $g['background_image'] ?? null,
    ':plats'  => $plats,
    ':genres' => $genres,
  ]);
}

// ---------- Main ----------
out("INGEST start: startPage=$startPage maxPages=$maxPages pageSize=$pageSize");

$dmz = new rabbitMQClient(INI_FILE_PATH, DMZ_SECTION);
$pdo = pdo();

$totalUpserted = 0;
$page         = $startPage;
$donePages    = 0;

try {
  while ($donePages < $maxPages) {
    $req = [
      'type'     => 'fetch_games',
      'page'     => $page,
      'pageSize' => $pageSize,
      'ordering' => '-added',   // popular/most-added first (stable-ish way to walk catalog)
    ];

    out("Requesting DMZ page=$page size=$pageSize");
    $resp = $dmz->send_request($req);
    out("Returned from DMZ page=$page");

    if (!is_array($resp) || empty($resp['success'])) {
      out("ERROR: DMZ response invalid at page=$page → ".json_encode($resp));
      break;
    }

    $items = $resp['items'] ?? [];
    $count = count($items);
    out("DMZ page=$page returned $count items");

    if ($count === 0) {
      out("No more items; stopping.");
      break;
    }

    // Upsert items in a transaction for speed & consistency
    $pdo->beginTransaction();
    $ins = 0;
    foreach ($items as $g) {
      if (!empty($g['rawg_id'])) {
        upsertGame($pdo, $g);
        $ins++;
      }
    }
    $pdo->commit();

    $totalUpserted += $ins;
    out("Upserted $ins items this page (total so far=$totalUpserted)");

    // Stop if the page is short (end of catalog in this ordering)
    if ($count < $pageSize) {
      out("Short page ($count < $pageSize). Likely end of results. Stopping.");
      break;
    }

    $page++;
    $donePages++;

    // Be nice to RAWG (DMZ calls it, but we still throttle requests here)
    usleep(400000); // 0.4s
  }

  $final = (int)$pdo->query('SELECT COUNT(*) FROM games')->fetchColumn();
  out("INGEST finished. totalUpserted=$totalUpserted rowsInTable=$final");
  out("Done.");

} catch (Throwable $e) {
  // Roll back if we were in a transaction
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  out("FATAL: ".$e->getMessage());
  exit(1);
}
?>