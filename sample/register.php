<?php
// keep JSON clean and log to a file
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/register_php_errors.log');
ob_start();

header('Content-Type: application/json');

try {
    require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
    require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
    require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

    $uname = isset($_POST['uname']) ? trim($_POST['uname']) : '';
    $pword = isset($_POST['pword']) ? (string)$_POST['pword'] : '';

    if ($uname === '' || $pword === '') {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Missing username or password']); exit;
    }
    if (strlen($pword) < 4) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Password must be at least 4 characters']); exit;
    }

    $req = [
        'type'     => 'register',
        'username' => $uname,
        'password' => $pword,
    ];

    $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
    $res = $client->send_request($req);

    if (!is_array($res)) {
        error_log('register.php: non-array response from server: ' . print_r($res, true));
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid response from server']); exit;
    }

    ob_clean(); echo json_encode($res);
} catch (Throwable $e) {
    error_log('register.php error: ' . $e->getMessage());
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>