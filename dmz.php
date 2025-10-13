#!/usr/bin/php
<?php
declare(strict_types=1);

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

/**
 * RAWG API config
 * - Put your key in an env var: export RAWG_API_KEY=xxxxx
 * - Or hardcode below (not recommended)
 */
function rawgApiKey(): string {
  $k = getenv('RAWG_API_KEY');
  if (!$k || $k === '') {
    // fallback for testing ONLY:
    // $k = 'YOUR_KEY_HERE';
  }
  return (string)$k;
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
