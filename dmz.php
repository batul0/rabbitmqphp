#!/usr/bin/php
<?php
declare(strict_types=1);

require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/dmz_fetcher.log');

/** Minimal .env loader (RAWG_API_KEY=xxxx) */
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
loadDotEnv(__DIR__ . '/.env');

function rawgApiKey(): string { return (string)(getenv('RAWG_API_KEY') ?: ''); }

function httpGetJson(string $url, int $timeout = 15): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_USERAGENT => 'DMZFetcher/1.0'
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch) ?: 'none';
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  error_log("[DMZ] GET $url -> HTTP $code, curl_err='$err', len=" . ($body === false ? 0 : strlen((string)$body)));
  if ($body === false || $code < 200 || $code >= 300) {
    throw new RuntimeException("HTTP $code $err");
  }
  $json = json_decode((string)$body, true);
  if (!is_array($json)) throw new RuntimeException("Invalid JSON");
  return $json;
}

/** --- LIST MAPPING --- */
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
    'id'               => $g['id'] ?? null,              // plain id for UI
    'rawg_id'          => $g['id'] ?? null,
    'name'             => $g['name'] ?? '',
    'released'         => $g['released'] ?? null,
    'rating'           => isset($g['rating']) ? (float)$g['rating'] : null,
    'background_image' => $g['background_image'] ?? null,
    'platforms'        => $platforms,
    'genres'           => $genres,
  ];
}

/** RAWG fetch (supports query, dates, ordering, page/pageSize) */
function doFetchGames(
  int $page, int $pageSize, string $query,
  ?string $dates = null, ?string $ordering = null, ?bool $search_precise = null
): array {
  $key = rawgApiKey();
  if ($key === '') return ['success'=>false, 'message'=>'RAWG API key not configured on DMZ'];

  $page     = max(1, $page);
  $pageSize = min(40, max(1, $pageSize)); // RAWG limit

  $base = 'https://api.rawg.io/api/games';
  $params = [
    'key'       => $key,
    'page'      => $page,
    'page_size' => $pageSize,
  ];
  if ($query !== '')            $params['search'] = $query;
  if (!empty($dates))           $params['dates'] = $dates;       // YYYY-MM-DD,YYYY-MM-DD
  if (!empty($ordering))        $params['ordering'] = $ordering; // e.g., -released
  if ($search_precise !== null) $params['search_precise'] = $search_precise ? 'true' : 'false';

  error_log("[DMZ] doFetchGames ENTER page=$page size=$pageSize q='$query' dates='".($dates??'')."' ord='".($ordering??'')."' precise=".var_export($search_precise, true));
  $url  = $base . '?' . http_build_query($params);
  $data = httpGetJson($url);

  $results = $data['results'] ?? [];
  error_log("[DMZ] RAWG results count=" . count($results) . " for query='$query'");
  if (!empty($results)) {
    $names = array_map(fn($g) => $g['name'] ?? '', array_slice($results, 0, 5));
    error_log("[DMZ] RAWG first names: " . implode(' | ', $names));
  }

  $items = [];
  foreach ($results as $g) $items[] = mapRawgItem($g);

  $next  = !empty($data['next']);
  $totalPages = $next ? $page + 1 : $page;

  error_log("[DMZ] doFetchGames EXIT ok items=".count($items));
  return [
    'success'    => true,
    'items'      => $items,
    'page'       => $page,
    'pageSize'   => $pageSize,
    'total'      => null,
    'totalPages' => $totalPages,
    'source'     => 'dmz'
  ];
}

/** --- DETAILS MAPPING --- */
function mapRawgDetail(array $d): array {
  $toNames = function($arr, $key='name'){
    $out = [];
    if (is_array($arr)) {
      foreach ($arr as $x) {
        if (is_array($x)) {
          if (isset($x['platform'][$key])) $out[] = $x['platform'][$key];
          elseif (isset($x['store'][$key])) $out[] = $x['store'][$key];
          elseif (isset($x[$key])) $out[] = $x[$key];
        }
      }
    }
    return array_values(array_unique(array_filter($out)));
  };

  return [
    'id'               => $d['id'] ?? null,
    'rawg_id'          => $d['id'] ?? null,
    'name'             => $d['name'] ?? '',
    'slug'             => $d['slug'] ?? '',
    'description'      => $d['description'] ?? null,
    'description_raw'  => $d['description_raw'] ?? null,
    'released'         => $d['released'] ?? null,
    'background_image' => $d['background_image'] ?? null,
    'background_image_additional' => $d['background_image_additional'] ?? null,
    'metacritic'       => $d['metacritic'] ?? null,
    'rating'           => isset($d['rating']) ? (float)$d['rating'] : null,
    'ratings_count'    => $d['ratings_count'] ?? null,
    'playtime'         => $d['playtime'] ?? null,
    'website'          => $d['website'] ?? null,
    'reddit_url'       => $d['reddit_url'] ?? null,
    'esrb_rating'      => $d['esrb_rating']['name'] ?? null,
    'platforms'        => $toNames($d['platforms'] ?? []),
    'genres'           => $toNames($d['genres'] ?? []),
    'tags'             => $toNames($d['tags'] ?? []),
    'stores'           => $toNames($d['stores'] ?? []),
    'developers'       => $toNames($d['developers'] ?? []),
    'publishers'       => $toNames($d['publishers'] ?? []),
    'screenshots'      => [],
  ];
}

function doFetchGameDetails(int $id): array {
  $key = rawgApiKey();
  if ($key === '') return ['success'=>false,'message'=>'RAWG API key not configured on DMZ'];
  if ($id <= 0)     return ['success'=>false,'message'=>'Invalid game id'];

  $url = 'https://api.rawg.io/api/games/' . urlencode((string)$id) . '?key=' . urlencode($key);
  $detail = httpGetJson($url);
  $mapped = mapRawgDetail($detail);

  // screenshots (optional)
  try {
    $shotsUrl = 'https://api.rawg.io/api/games/' . urlencode((string)$id) . '/screenshots?key=' . urlencode($key);
    $shots = httpGetJson($shotsUrl);
    $imgs = [];
    foreach ($shots['results'] ?? [] as $s) {
      if (!empty($s['image'])) $imgs[] = $s['image'];
    }
    $mapped['screenshots'] = $imgs;
  } catch (Throwable $e) { /* ignore */ }

  return ['success'=>true, 'item'=>$mapped, 'source'=>'dmz'];
}

function requestProcessor($req) {
  error_log('[DMZ] received: '.json_encode($req));
  if (!isset($req['type'])) return ['success'=>false,'message'=>'unsupported message type'];

  switch ($req['type']) {
    case 'fetch_games': {
      $page = (int)($req['page'] ?? 1);
      $ps   = (int)($req['pageSize'] ?? 9);
      $q    = trim((string)($req['query'] ?? ''));
      $dates= isset($req['dates']) ? (string)$req['dates'] : null;
      $ord  = isset($req['ordering']) ? (string)$req['ordering'] : null;
      $prec = isset($req['search_precise']) ? (bool)$req['search_precise'] : null;
      try {
        $res = doFetchGames($page, $ps, $q, $dates, $ord, $prec);
        error_log("[DMZ] requestProcessor responding items=".count($res['items'] ?? []));
        return $res;
      } catch (Throwable $e) {
        error_log('DMZ fetch error: '.$e->getMessage());
        return ['success'=>false,'message'=>'DMZ fetch failed'];
      }
    }
    case 'fetch_game_details': {
      try {
        $id = (int)($req['id'] ?? 0);
        return doFetchGameDetails($id);
      } catch (Throwable $e) {
        error_log('DMZ details error: '.$e->getMessage());
        return ['success'=>false,'message'=>'DMZ details failed'];
      }
    }
    default:
      return ['success'=>false,'message'=>'unknown type'];
  }
}

$server = new rabbitMQServer('testRabbitMQ.ini', 'dmzServer');
echo "dmzFetcher BEGIN\n";
$server->process_requests('requestProcessor');
echo "dmzFetcher END\n";
?>