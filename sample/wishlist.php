<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

header('Content-Type: application/json');

$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') { echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit; }

function sendToLogin(array $payload): array {
  try {
    $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
    $res = $client->send_request($payload);
    return is_array($res) ? $res : ['success'=>false,'message'=>'Invalid response'];
  } catch (Throwable $e) {
    return ['success'=>false,'message'=>'Server error'];
  }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action !== 'toggle') { echo json_encode(['success'=>false,'message'=>'Unknown action']); exit; }
  $rawg_id = (int)($_POST['rawg_id'] ?? 0);
  $wanted  = (int)($_POST['wanted'] ?? 1) ? 1 : 0;
  if ($rawg_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid rawg_id']); exit; }

  $res = sendToLogin([
    'type'      => 'wishlist_toggle',
    'sessionId' => $sid,
    'rawg_id'   => $rawg_id,
    'wanted'    => $wanted,
  ]);
  echo json_encode($res); exit;
}

// GET list
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(1, min(48, (int)($_GET['pageSize'] ?? 24)));
echo json_encode(sendToLogin([
  'type'      => 'wishlist_list',
  'sessionId' => $sid,
  'page'      => $page,
  'pageSize'  => $pageSize,
]));
