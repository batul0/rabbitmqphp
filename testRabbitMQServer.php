#!/usr/bin/php
<?php
declare(strict_types=1);
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');


/* =======================
  CONFIG: MySQL settings
  ======================= */
const DB_HOST = '100.76.74.77';     // change to your DB VM IP if this runs on a different VM
const DB_NAME = 'loginDB';
const DB_USER = 'loginapp';
const DB_PASS = 'loginappPass123';


/**
* Get a PDO connection to MySQL
*/
function getPDO(): PDO {
   $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
   $options = [
       PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
       PDO::ATTR_EMULATE_PREPARES   => false,
       PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_notes = 0"
   ];
   return new PDO($dsn, DB_USER, DB_PASS, $options);
}


/**
* Verify login against DB (username unique, password stored as password_hash())
* Returns an array payload suitable to json_encode and send back through RabbitMQ.
*/
function doLogin(string $username, string $password): array {
   if ($username === '' || $password === '') {
       return ['success' => false, 'message' => 'Username and password required'];
   }


   try {
       $pdo = getPDO();


       // Fetch the (hashed) password for this username
       $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
       $stmt->execute([$username]);
       $row = $stmt->fetch();


       if (!$row) {
           // Avoid leaking which part failed
           return ['success' => false, 'message' => 'Invalid credentials'];
       }


       $hash = $row['password'] ?? '';
       if ($hash === '' || !password_verify($password, $hash)) {
           return ['success' => false, 'message' => 'Invalid credentials'];
       }


       // Optional: You can rotate/rehash if algorithm updated
       if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
           $newHash = password_hash($password, PASSWORD_DEFAULT);
           $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
           $upd->execute([$newHash, $row['id']]);
       }


       // Minimal success payload
       return [
           'success'  => true,
           'message'  => 'Login successful',
           'username' => $row['username'],
           // Add anything else you want to send back (e.g., user_id, roles)
       ];


   } catch (Throwable $e) {
       // Log server-side; return generic error to client
       error_log('[doLogin] DB error: ' . $e->getMessage());
       return ['success' => false, 'message' => 'Server error'];
   }
}


function doRegister(string $username, string $password): array {
    $username = trim($username);
    $password = (string)$password;

    if ($username === '' || strlen($password) < 4) {
        return ['success' => false, 'message' => 'Invalid input'];
    }

    try {
        $pdo = getPDO();

        // Check if username exists
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $chk->execute([$username]);
        if ($chk->fetchColumn()) {
            return ['success' => false, 'message' => 'Username already exists'];
        }

        // Hash and insert
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins  = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
        $ins->execute([$username, $hash]);

        return ['success' => true, 'message' => 'Registration successful'];
    } catch (PDOException $e) {
        // Handle unique constraint race condition gracefully
        if ($e->getCode() === '23000') { // integrity constraint violation
            return ['success' => false, 'message' => 'Username already exists'];
        }
        error_log('[doRegister] DB error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    } catch (Throwable $e) {
        error_log('[doRegister] error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Server error'];
    }
}

/**
* (Optional) Example of session validation handler stub
*/
function doValidate(string $sessionId): array {
   // Implement as needed if you add sessions later
   return ['success' => false, 'message' => 'Not implemented'];
}


/**
* The request dispatcher that RabbitMQ calls per message
*/
function requestProcessor(array $request) {
 echo "Received request:\n";
 var_dump($request);


 if (!isset($request['type'])) {
   return ['success' => false, 'message' => 'ERROR: unsupported message type'];
 }


 switch ($request['type']) {
   case 'login':
     // Expect 'username' and 'password' keys from the webserver
     $username = isset($request['username']) ? (string)$request['username'] : '';
     $password = isset($request['password']) ? (string)$request['password'] : '';
     return doLogin($username, $password);


   case 'validate_session':
     $sid = isset($request['sessionId']) ? (string)$request['sessionId'] : '';
     return doValidate($sid);


   default:
     return ['success' => false, 'message' => 'ERROR: unknown type'];
 }
}


/**
* Start the RabbitMQ request processor.
* IMPORTANT: the INI section name must match your testRabbitMQ.ini section.
* Your ini shows [loginServer], so use that.
*/
$server = new rabbitMQServer("testRabbitMQ.ini", "loginServer");


echo "testRabbitMQServer BEGIN\n";
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END\n";
exit();
?>
