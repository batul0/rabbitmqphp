<?php

session_start();

include (testRabbitMQClient.php);
require_once (testRabbitMQ.ini);

$username = $_POST['username'];
$password = $_POST['password'];

$response = login($username, $password);

if ($response  == true){

	$_SESSION['username'] = $username;
	header("location: ./home.html");

}

else{
	echo("Wrong username or password...try again please");
	header("location: ./index.html");
}


/*if (!isset($_POST))
{
	$msg = "NO POST MESSAGE SET, POLITELY FUCK OFF";
	echo json_encode($msg);
	exit(0);
}
$request = $_POST;
$response = "unsupported request type, politely FUCK OFF";
switch ($request["type"])
{
	case "login":
		$response = "login, yeah we can do that";
	break;
}
echo json_encode($response);
exit(0);
*/

?>
