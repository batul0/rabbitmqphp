#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

/**
 * Connect to MySQL.
 * Adjust DB credentials/host according to your DB VM setup.
 */
function getDB()
{
    $host = "100.94.90.92";   // or your DB VM IP
    $user = "dbuser";
    $pass = "dbpass";
    $dbname = "myapp";

    $mysqli = new mysqli($host, $user, $pass, $dbname);
    if ($mysqli->connect_errno) {
        error_log("DB Connection failed: " . $mysqli->connect_error);
        return null;
    }
    return $mysqli;
}

/**
 * Check login against DB
 */
function doLogin($username, $password)
{
    $db = getDB();
    if ($db === null) {
        return [
            'success' => false,
            'message' => 'Database connection failed'
        ];
    }

    // Prepared statement to avoid SQL injection
    $stmt = $db->prepare("SELECT password FROM users WHERE username = ?");
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'DB prepare failed'
        ];
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($hashedPassword);
    if ($stmt->fetch()) {
        $stmt->close();
        $db->close();

        // If you’re storing hashed passwords (recommended)
        if (password_verify($password, $hashedPassword)) {
            return [
                'success' => true,
                'message' => 'Login successful',
                'username' => $username
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Invalid username or password'
            ];
        }
    }

    $stmt->close();
    $db->close();

    return [
        'success' => false,
        'message' => 'User not found'
    ];
}

/**
 * RabbitMQ request handler
 */
function requestProcessor($request)
{
    echo "Received request" . PHP_EOL;
    var_dump($request);

    if (!isset($request['type'])) {
        return ['success' => false, 'message' => 'ERROR: unsupported message type'];
    }

    switch ($request['type']) {
        case 'login': {
            $uname = $request['uname'] ?? ($request['username'] ?? '');
            $pword = $request['pword'] ?? ($request['password'] ?? '');
            return doLogin($uname, $pword);
        }
        default:
            return ['success' => false, 'message' => 'ERROR: unsupported message type'];
    }
}

echo "testRabbitMQServer BEGIN" . PHP_EOL;
$server = new rabbitMQServer("testRabbitMQ.ini", "loginServer");
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END" . PHP_EOL;
exit();
?>