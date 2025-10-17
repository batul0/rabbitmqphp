<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

try {
  $page     = isset($_POST['page']) ? (int)$_POST['page'] : 1;
  $pageSize = isset($_POST['pageSize']) ? (int)$_POST['pageSize'] : 9;
  $scope    = isset($_POST['scope']) ? (string)$_POST['scope'] : 'recent';
  $query    = isset($_POST['query']) ? trim((string)$_POST['query']) : '';

  $payload = [
    'type'     => 'games_list',
    'page'     => max(1, $page),
    'pageSize' => max(1, min(50, $pageSize)),
  ];

  if ($scope === 'search' && $query !== '') {
    $payload['scope'] = 'search';
    $payload['query'] = $query;
  } else {
    $payload['scope'] = 'recent'; // default feed
  }

  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
  $res = $client->send_request($payload);

  if (!is_array($res)) {
    echo json_encode(['success'=>false,'message'=>'Invalid server response']); exit;
  }
  echo json_encode($res);
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'message'=>'Server error']);
}
