#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

/**
 * Fake DB check — replace with a real DB lookup.
 * Return a structured array so the client and JS can read fields.
 */
function doLogin($username, $password)
{
    // TODO: Replace with actual DB verification.
    // Example rule: username 'admin' and password '12345' are valid
    $ok = (!empty($username) && !empty($password) && $username === 'admin' && $password === '12345');

    if ($ok) {
        return [
            'success' => true,
            'message' => 'Login successful',
            'username' => $username,
            // include anything else you want to return to the web client (e.g., role, token, etc.)
        ];
    }

    return [
        'success' => false,
        'message' => 'Invalid username or password',
    ];
}

function requestProcessor($request)
{
    echo "Received request" . PHP_EOL;
    var_dump($request);

    if (!isset($request['type'])) {
        return ['success' => false, 'message' => 'ERROR: unsupported message type'];
    }

    switch ($request['type']) {
        case 'login':
            // NOTE: your frontend sends fields as 'uname' and 'pword'
            $uname = isset($request['uname']) ? $request['uname'] : (isset($request['username']) ? $request['username'] : '');
            $pword = isset($request['pword']) ? $request['pword'] : (isset($request['password']) ? $request['password'] : '');
            return doLogin($uname, $pword);

        // You can add other cases later, e.g., validate_session, register, etc.
        default:
            return ['success' => false, 'message' => 'ERROR: unsupported message type'];
    }
}

echo "Login RabbitMQ Server BEGIN" . PHP_EOL;
// IMPORTANT: bind this server to the [loginServer] section
$server = new rabbitMQServer("testRabbitMQ.ini", "loginServer");
$server->process_requests('requestProcessor');
echo "Login RabbitMQ Server END" . PHP_EOL;
exit();
?>