<?php

session_start();

include (testRabbitMQClient.php);
require_once (testRabbitMQ.ini);

$username = $_POST['username'];
$password = $_POST['password'];

$response = register($username, $password);

if ($response  == true) {

	$_SESSION['username'] = $username; 
  echo json_encode(["success" => true, "message" => "Registration successful"]);

} 
else {

  echo json_encode(["success" => false, "message" => "Username is already taken"]);

}
exit;