<?php
// session_check.php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/session_check_php_errors.log');

header('Content-Type: application/json');

// If no cookie, short-circuit
$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') {
  echo json_encode(['valid' => false]);
  exit;
}

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

try {
  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
  $res = $client->send_request(['type' => 'validate_session', 'sessionId' => $sid]);

  $ok = is_array($res) && !empty($res['success']);
  echo json_encode(['valid' => $ok, 'username' => $ok ? ($res['username'] ?? null) : null]);
} catch (Throwable $e) {
  error_log('session_check.php error: ' . $e->getMessage());
  echo json_encode(['valid' => false]);
}
?>