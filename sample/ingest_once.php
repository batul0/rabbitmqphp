#!/usr/bin/php
<?php
declare(strict_types=1);

require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

// Tweak as you like
$startPage = 1;
$maxPages  = 250;  // 250 * 40 = 10,000 games max; adjust to your comfort

$cli = new rabbitMQClient('/home/vboxuser/git/rabbitmqphp/testRabbitMQ.ini', 'loginServer');

$req = [
  'type'      => 'ingest_all_games',
  'startPage' => $startPage,
  'maxPages'  => $maxPages
];

echo "[INGEST] Sending request: startPage={$startPage}, maxPages={$maxPages}\n";
$res = $cli->send_request($req);
echo "[INGEST] Response: " . json_encode($res) . "\n";
