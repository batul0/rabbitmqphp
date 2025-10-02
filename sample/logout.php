<?php
// logout.php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/logout_php_errors.log');

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

$sid = $_COOKIE['sid'] ?? '';

try {
    if ($sid !== '') {
        $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer'); // same section you use for login
        // Best-effort logout on server (delete session row)
        $client->send_request(['type' => 'logout', 'sessionId' => $sid]);
    }
} catch (Throwable $e) {
    error_log('logout.php send_request error: ' . $e->getMessage());
}

// Always clear cookie and redirect
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
setcookie('sid', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $secure,
]);

header('Location: index.html');
exit;
