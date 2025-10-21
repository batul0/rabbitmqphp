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
      $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
      $ps   = (int)($_POST['pageSize'] ?? $_GET['pageSize'] ?? 9);
      $req = [
        'type'      => 'forum_list',
        'sessionId' => $sid,
        'page'      => max(1,$page),
        'pageSize'  => max(1,min(50,$ps)),
      ];
      $res = $client->send_request($req);
      echo json_encode($res ?: ['success'=>false,'message'=>'listener error']); exit;
    }

    case 'create': {
      $rawg_id = (int)($_POST['rawg_id'] ?? 0);
      $title   = trim((string)($_POST['title'] ?? ''));
      $desc    = trim((string)($_POST['description'] ?? ''));
      if ($rawg_id <= 0 || $title === '') bad('Invalid input');
      $req = [
        'type'        => 'forum_create',
        'sessionId'   => $sid,
        'rawg_id'     => $rawg_id,
        'title'       => $title,
        'description' => $desc,
      ];
      $res = $client->send_request($req);
      echo json_encode($res ?: ['success'=>false,'message'=>'listener error']); exit;
    }

    // forums.php (only showing changed cases)
case 'get': {
  $id   = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
  $page = (int)($_POST['page'] ?? $_GET['page'] ?? 1);
  $ps   = (int)($_POST['pageSize'] ?? $_GET['pageSize'] ?? 50);
  if ($id <= 0) bad('Invalid id');

  // Map to listener’s expected keys
  $req = [
    'type'      => 'forum_get',
    'sessionId' => $sid,
    'forum_id'  => $id,
    'page'      => max(1, $page),
    'pageSize'  => max(1, min(200, $ps)),
  ];
  $res = $client->send_request($req);

  // Normalize to keep front-end working: expose forum as item
  if (is_array($res) && isset($res['forum']) && !isset($res['item'])) {
    $res['item'] = $res['forum'];
  }
  echo json_encode($res ?: ['success'=>false,'message'=>'listener error']); exit;
}

case 'post_message': {
  $id        = (int)($_POST['id'] ?? 0);
  $text      = trim((string)($_POST['text'] ?? ''));
  $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
  if ($id <= 0 || $text === '') bad('Invalid input');

  $req = [
    'type'      => 'forum_post_message',
    'sessionId' => $sid,
    'forum_id'  => $id,
    'message'   => $text,
    'parent_id' => $parent_id,
  ];
  $res = $client->send_request($req);
  echo json_encode($res ?: ['success'=>false,'message'=>'listener error']); exit;
}



    default:
      bad('Unknown action');
  }

} catch (Throwable $e) {
  echo json_encode(['success'=>false,'message'=>'Server error']); exit;
}
