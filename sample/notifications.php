<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

header('Content-Type: application/json');

// Get session ID from cookie
$sid = $_COOKIE['sid'] ?? '';
if ($sid === '') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'list';

switch ($action) {

    case 'list':
        // Fetch notifications
        $payload = [
            'type' => 'notifications',
            'sessionId' => $sid,
        ];
        $res = sendToLogin($payload);
        echo json_encode($res);
        break;

    case 'delete':
        // Send delete request to the server
        $payload = [
            'type' => 'notifications_delete',
            'sessionId' => $sid,
        ];
        $res = sendToLogin($payload);
        echo json_encode($res);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}

exit;