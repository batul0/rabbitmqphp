<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

header('Content-Type: application/json');

$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') {
  echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit;
}

function sendToLogin(array $payload): array {
  try {
    $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
    $res = $client->send_request($payload);
    if (!is_array($res)) return ['success'=>false,'message'=>'Invalid response'];
    return $res;
  } catch (Throwable $e) {
    return ['success'=>false,'message'=>'Server error'];
  }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
  // Toggle like on/off
  $action   = $_POST['action']   ?? '';
  if ($action !== 'toggle') {
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
  }
  $rawg_id  = (int)($_POST['rawg_id'] ?? 0);
  $liked    = (int)($_POST['liked'] ?? 0) ? 1 : 0;

  // optional metadata to help upsert the game row
  $name     = trim((string)($_POST['name'] ?? ''));
  $image    = trim((string)($_POST['image'] ?? ''));
  $released = trim((string)($_POST['released'] ?? ''));
  $rating   = trim((string)($_POST['rating'] ?? ''));

  if ($rawg_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid rawg_id']); exit;
  }

  $payload = [
    'type'      => 'like_toggle',
    'sessionId' => $sid,
    'rawg_id'   => $rawg_id,
    'liked'     => $liked,
    'meta'      => [
      'name' => $name,
      'background_image' => $image,
      'released' => $released,
      'rating' => $rating,
    ],
  ];
  $res = sendToLogin($payload);
  echo json_encode($res); exit;
}

// GET -> list liked games (paged)
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(1, min(48, (int)($_GET['pageSize'] ?? 24)));

$res = sendToLogin([
  'type'      => 'like_list',
  'sessionId' => $sid,
  'page'      => $page,
  'pageSize'  => $pageSize,
]);

echo json_encode($res);
