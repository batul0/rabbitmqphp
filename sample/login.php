<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php/login_php_errors.log');
error_log("logger alive: " . date('c'));

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');


header('Content-Type: application/json');


if (!isset($_POST['uname']) || !isset($_POST['pword'])) {
    echo json_encode(['success' => false, 'message' => 'Missing username or password']);
    exit;
}


$request = [
    'type'     => 'login',
    'username' => $_POST['uname'],
    'password' => $_POST['pword'],
];


try {
    $client = new rabbitMQClient("testRabbitMQ.ini", "loginServer");
    $response = $client->send_request($request);

    if (is_array($response) && !empty($response['success'])) {
        // If server returned a session_key, set cookie
        if (!empty($response['session_key'])) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            setcookie('sid', $response['session_key'], [
                'expires'  => time() + 7*24*3600, // match server expiry
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $secure
            ]);
        }
    }

    // Make sure the output is clean JSON only
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
