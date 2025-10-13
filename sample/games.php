<?php
// games.php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/games_php_errors.log');

header('Content-Type: application/json');

// Basic input
$page     = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
$pageSize = isset($_POST['pageSize']) ? max(1, min(50, (int)$_POST['pageSize'])) : 9;
$query    = isset($_POST['query']) ? trim($_POST['query']) : '';

// Require session cookie (optional—home.php already guards)
$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') {
  echo json_encode(['success' => false, 'message' => 'Not authenticated']); exit;
}

try {
  require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
  require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
  require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');

  // Send a request that your DB listener will handle
  $req = [
    'type'     => 'games_list',   // << implement this on the DB listener
    'page'     => $page,
    'pageSize' => $pageSize,
    'query'    => $query,
    // Optionally forward user/session info if you need per-user caching
    'sessionId'=> $sid
  ];

  $res = $client->send_request($req);

  if (!is_array($res)) {
    echo json_encode(['success'=>false,'message'=>'Invalid response from server']); exit;
  }
  // Pass through
  echo json_encode($res);
} catch (Throwable $e) {
  error_log('games.php error: ' . $e->getMessage());
  echo json_encode(['success'=>false,'message'=>'Server error']);
}
?>