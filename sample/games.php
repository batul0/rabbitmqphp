<?php
// games.php — JSON-only endpoint for listing/searching games

// keep output clean
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// ---- RabbitMQ includes ----
require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

function readInt($key, $default) {
  $v = isset($_POST[$key]) ? (int)$_POST[$key] : (isset($_GET[$key]) ? (int)$_GET[$key] : $default);
  return max(1, $v);
}
function readStr($key, $default='') {
  if (isset($_POST[$key])) return trim((string)$_POST[$key]);
  if (isset($_GET[$key]))  return trim((string)$_GET[$key]);
  return $default;
}

$page     = readInt('page', 1);
$pageSize = min(50, readInt('pageSize', 9));
$scope    = readStr('scope', 'recent'); // 'recent' or 'search'
$query    = readStr('query', '');

$request = [
  'type'     => 'games_list',
  'page'     => $page,
  'pageSize' => $pageSize,
  'scope'    => ($scope === 'search' && $query !== '') ? 'search' : 'recent',
  'query'    => $query,
];

try {
  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');

  // If your rabbitMQLib supports these, you can uncomment:
  // $client->retries = 1;
  // $client->timeout = 6; // seconds (name may vary by library)

  $resp = $client->send_request($request);

  if (!is_array($resp)) {
    echo json_encode([
      'success' => false,
      'message' => 'Invalid response from service',
    ]);
    exit;
  }

  // pass-through happy path
  echo json_encode($resp, JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  // NEVER echo raw exception text; always return JSON
  // Optionally log the actual error server-side:
  error_log('[games.php] RPC error: ' . $e->getMessage());

  echo json_encode([
    'success' => false,
    'message' => 'Service temporarily unavailable (RPC timeout). Please try again.',
    'hint'    => 'rpc_timeout', // UI can check this if needed
  ]);
  exit;
}
