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
    if (!is_array($res)) return ['success'=>false,'message'=>'Invalid response'];
    return $res;
  } catch (Throwable $e) {
    return ['success'=>false,'message'=>'Server error'];
  }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  echo json_encode(['success'=>false,'message'=>'Use POST']); exit;
}

$rawg_id = (int)($_POST['rawg_id'] ?? 0);
$value   = (float)($_POST['value'] ?? 0);
if ($rawg_id <= 0 || $value < 0.5 || $value > 5.0) {
  echo json_encode(['success'=>false,'message'=>'Invalid input']); exit;
}

$res = sendToLogin([
  'type'      => 'rating_set',
  'sessionId' => $sid,
  'rawg_id'   => $rawg_id,
  'value'     => $value,
]);

echo json_encode($res);
