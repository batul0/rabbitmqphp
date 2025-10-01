<?php

session_start();

include (testRabbitMQClient.php);
require_once (testRabbitMQ.ini);

$username = $_POST['username'];
$password = $_POST['password'];

$response = register($username, $password);

if ($response  == true) {

	$_SESSION['token'] = $username; //will need to change to parse for token in return statement once completed
  echo json_encode(["success" => true, "message" => "Registration successful"]);

} 
else {

  echo json_encode(["success" => false, "message" => "Username is already taken"]);

}
exit;