<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/apache2/game_endpoint.log'); // adjust if needed

header('Content-Type: application/json');

try {
  // match the paths you used in home.php
  require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
  require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
  require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  // accept x-www-form-urlencoded or raw body
  $id = 0;
  if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
  } else {
    $raw = file_get_contents('php://input') ?: '';
    parse_str($raw, $post);
    $id = isset($post['id']) ? (int)$post['id'] : 0;
  }

  if ($id <= 0) {
    error_log("[game.php] invalid id payload: POST=".json_encode($_POST));
    echo json_encode(['success'=>false,'message'=>'Invalid id']); exit;
  }

  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
  $req = ['type'=>'game_details','id'=>$id];
  error_log("[game.php] send_request -> ".json_encode($req));
  $res = $client->send_request($req);

  if (!is_array($res)) {
    error_log("[game.php] invalid response from DB listener");
    echo json_encode(['success'=>false,'message'=>'Invalid server response']); exit;
  }

  // we expect { success: true, item: {...} }
  if (isset($res['items'])) {
    // safety: someone routed to list by mistake
    error_log("[game.php] WARNING: received list payload for details request");
  }

  echo json_encode($res);
} catch (Throwable $e) {
  error_log("[game.php] fatal: ".$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server error']);
}
