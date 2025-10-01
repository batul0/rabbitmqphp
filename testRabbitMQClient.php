#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function login($username, $password){

    $client = new rabbitMQClient("testRabbitMQ.ini","testServer"); //change to loginServer


    $request = array();
    $request['type'] = "login";
    $request['username'] = $username;
    $request['password'] = $password;
    $request['message'] = "HI";
    $response = $client->send_request($request);
    //$response = $client->publish($request);

    echo "client received response: ".PHP_EOL;
    print_r($response);
    echo "\n\n";

}

function register($username, $password) {
    $client = new rabbitMQClient("testRabbitMQ.ini","testServer"); //change to registerServer
    
    $request = array();
    $request['type'] = "register";
    $request['username'] = $username;
    $request['password'] = $password;
    $response = $client->send_request($request);
    return $response;
}

function logout($token) {
    $client = new rabbitMQClient("testRabbitMQ.ini","testServer");

    $request = array();
    $request['type'] = "logout";
    $request['token'] = $token;
    $response = $client->send_request($request);
    return $response;
}

function validate($token) {
    $client = new rabbitMQClient("testRabbitMQ.ini","testServer");
    
    $request = array();
    $request['type'] = "validate";
    $request['token'] = $token;
    $response = $client->send_request($request);
    return $response;
}

/*$client = new rabbitMQClient("testRabbitMQ.ini","testServer");


$request = array();
$request['type'] = "login";
$request['username'] = $argv[1];
$request['password'] = $argv[2];
$request['message'] = "HI";
$response = $client->send_request($request);
//$response = $client->publish($request);

echo "client received response: ".PHP_EOL;
print_r($response);
echo "\n\n";
*/
echo $argv[0]." END".PHP_EOL;
