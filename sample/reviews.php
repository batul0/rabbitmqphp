<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

header('Content-Type: application/json');

$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

function bad($m){ echo json_encode(['success'=>false,'message'=>$m]); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
try {
  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');

  switch ($action) {
    case 'list': {
      $rawg = (int)($_POST['rawg_id'] ?? $_GET['rawg_id'] ?? 0);
      $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
      $ps   = (int)($_POST['pageSize'] ?? $_GET['pageSize'] ?? 6);
      if ($rawg <= 0) bad('Invalid game');
      $res = $client->send_request([
        'type'      => 'review_list',
        'sessionId' => $sid,
        'rawg_id'   => $rawg,
        'page'      => max(1,$page),
        'pageSize'  => max(1,min(50,$ps)),
      ]);
      echo json_encode($res ?: ['success'=>false,'message'=>'listener error']); exit;
    }

    case 'create': {
      $rawg = (int)($_POST['rawg_id'] ?? 0);
      $title= trim((string)($_POST['title'] ?? ''));
      $body = trim((string)($_POST['body']  ?? ''));
      $rating = (float)($_POST['rating'] ?? 0);
      if ($rawg <= 0 || $body === '' || $rating < 0.5 || $rating > 5.0) bad('Invalid input');

      $res = $client->send_request([
        'type'      => 'review_create',
        'sessionId' => $sid,
        'rawg_id'   => $rawg,
        'title'     => $title,
        'body'      => $body,
        'rating'    => $rating
      ]);
      echo json_encode($res ?: ['success'=>false,'message'=>'listener error']); exit;
    }

    default: bad('Unknown action');
  }
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'message'=>'Server error']); exit;
}
