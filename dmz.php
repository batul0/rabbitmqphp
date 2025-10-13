#!/usr/bin/php
<?php
declare(strict_types=1);

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function loadDotEnv(string $file): void {
  if (!is_file($file) || !is_readable($file)) return;
  foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));
    if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
        (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
      $val = substr($val, 1, -1);
    }
    putenv("$key=$val");
    $_ENV[$key] = $val;
  }
}

loadDotEnv(__DIR__ . '/.env');  // <-- add this line

function rawgApiKey(): string {
  return (string)(getenv('RAWG_API_KEY') ?: '');
}


function httpGetJson(string $url, int $timeout = 10): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_USERAGENT => 'DMZFetcher/1.0'
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  error_log("[DMZ] GET $url -> HTTP $code, curl_err='$err', len=" . ($body === false ? 0 : strlen($body)));
  if ($body === false || $code < 200 || $code >= 300) {
    throw new RuntimeException("HTTP $code $err");
  }
  $json = json_decode($body, true);
  if (!is_array($json)) {
    throw new RuntimeException("Invalid JSON");
  }
  return $json;
}

function mapRawgItem(array $g): array {
  $platforms = [];
  if (!empty($g['platforms']) && is_array($g['platforms'])) {
    foreach ($g['platforms'] as $p) {
      if (!empty($p['platform']['name'])) $platforms[] = $p['platform']['name'];
    }
  }
  $genres = [];
  if (!empty($g['genres']) && is_array($g['genres'])) {
    foreach ($g['genres'] as $gn) {
      if (!empty($gn['name'])) $genres[] = $gn['name'];
    }
  }
  return [
    'rawg_id'          => $g['id'] ?? null,
    'name'             => $g['name'] ?? '',
    'released'         => $g['released'] ?? null,
    'rating'           => isset($g['rating']) ? (float)$g['rating'] : null,
    'background_image' => $g['background_image'] ?? null,
    'platforms'        => $platforms,
    'genres'           => $genres,
  ];
}

function doFetchGames(int $page, int $pageSize, string $query): array {
  $key = rawgApiKey();
  if ($key === '') {
    return ['success'=>false, 'message'=>'RAWG API key not configured on DMZ'];
  }
  $page = max(1, $page);
  $pageSize = min(40, max(1, $pageSize)); // RAWG page_size limit

  $base = 'https://api.rawg.io/api/games';
  $params = [
    'key'       => $key,
    'page'      => $page,
    'page_size' => $pageSize,
  ];
  if ($query !== '') $params['search'] = $query;

  $url = $base . '?' . http_build_query($params);
  $data = httpGetJson($url);

  $results = $data['results'] ?? [];
  $items = [];
  foreach ($results as $g) {
    $items[] = mapRawgItem($g);
  }

  // RAWG gives next/previous; total is not always provided—approximate if needed
  $next  = !empty($data['next']);
  $prev  = !empty($data['previous']);
  $totalPages = $next ? $page + 1 : $page; // conservative; DB can compute real total later

  return [
    'success'    => true,
    'items'      => $items,
    'page'       => $page,
    'pageSize'   => $pageSize,
    'total'      => null,       // unknown; DB can fill after storing/counting
    'totalPages' => $totalPages,
    'source'     => 'dmz'
  ];
}

function requestProcessor($req) {
  error_log('DMZ received: '.json_encode($req));
  if (!isset($req['type'])) return ['success'=>false,'message'=>'unsupported message type'];
  switch ($req['type']) {
    case 'fetch_games':
      $page = (int)($req['page'] ?? 1);
      $ps   = (int)($req['pageSize'] ?? 9);
      $q    = trim((string)($req['query'] ?? ''));
      try {
        return doFetchGames($page, $ps, $q);
      } catch (Throwable $e) {
        error_log('DMZ fetch error: '.$e->getMessage());
        return ['success'=>false,'message'=>'DMZ fetch failed'];
      }
    default:
      return ['success'=>false,'message'=>'unknown type'];
  }
}

$server = new rabbitMQServer('testRabbitMQ.ini', 'dmzServer');
echo "dmzFetcher BEGIN\n";
$server->process_requests('requestProcessor');
echo "dmzFetcher END\n";
