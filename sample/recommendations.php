<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') {
  echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit;
}

try {
  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');

  // Optional: allow overriding limit via query (?limit=12)
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
  if ($limit <= 0 || $limit > 50) $limit = 12;

  // Call the DB listener’s doRecommendations(), which itself may call DMZ/RAWG
  $res = $client->send_request([
    'type'      => 'recommendations',
    'sessionId' => $sid,
    'limit'     => $limit,
  ]);

  if (!is_array($res) || empty($res['success'])) {
    echo json_encode(['success'=>false,'message'=>$res['message'] ?? 'Failed to fetch recommendations']); exit;
  }

  echo json_encode([
    'success' => true,
    'items'   => $res['items'] ?? [],
    // pass-through if you want to show them in UI:
    'top_genres' => $res['top_genres'] ?? [],
  ]);
} catch (Throwable $e) {
  error_log('[recommendations.php] '.$e->getMessage());
  echo json_encode(['success'=>false,'message'=>'Server error']);
}
