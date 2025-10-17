<?php
require_once('get_host_info.inc');

class rabbitMQServer
{
    private string $machineName = "";
    public string $BROKER_HOST;
    private int $BROKER_PORT;
    private string $USER;
    private string $PASSWORD;
    private string $VHOST;
    private string $exchange;
    private string $queue;
    private string $routing_key = '*';
    private string $exchange_type = "topic";
    private bool $auto_delete = false;

    /** formerly dynamic: */
    private ?AMQPQueue $conn_queue = null;
    private $callback = null;

    function __construct($machine, $server = "rabbitMQ")
    {
        $machine = getHostInfo(array($machine));
        $this->machineName = $server;

        $this->BROKER_HOST = $machine[$server]["BROKER_HOST"];
        $this->BROKER_PORT = (int)$machine[$server]["BROKER_PORT"];
        $this->USER        = $machine[$server]["USER"];
        $this->PASSWORD    = $machine[$server]["PASSWORD"];
        $this->VHOST       = $machine[$server]["VHOST"];

        if (isset($machine[$server]["EXCHANGE_TYPE"])) {
            $this->exchange_type = $machine[$server]["EXCHANGE_TYPE"];
        }
        if (isset($machine[$server]["AUTO_DELETE"])) {
            $this->auto_delete = (bool)$machine[$server]["AUTO_DELETE"];
        }
        $this->exchange = $machine[$server]["EXCHANGE"];
        $this->queue    = $machine[$server]["QUEUE"];
    }

    function process_message(AMQPEnvelope $msg, AMQPQueue $queue)
    {
        // Only process our routing key
        if ($msg->getRoutingKey() !== "*") {
            return;
        }

        // ACK the incoming request AFTER we build the response to be a bit safer
        // (old code ACKed immediately). We’ll ACK right before returning.

        try {
            $response = null;
            if ($msg->getReplyTo()) {
                // RPC style: caller expects a response
                $body    = $msg->getBody();
                $payload = json_decode($body, true);

                if (isset($this->callback)) {
                    $response = call_user_func($this->callback, $payload);
                }

                $params = [
                    'host'     => $this->BROKER_HOST,
                    'port'     => $this->BROKER_PORT,
                    'login'    => $this->USER,
                    'password' => $this->PASSWORD,
                    'vhost'    => $this->VHOST,
                ];
                $conn    = new AMQPConnection($params);
                $conn->connect();

                $channel  = new AMQPChannel($conn);
                $exchange = new AMQPExchange($channel);
                $exchange->setName($this->exchange);
                $exchange->setType($this->exchange_type);

                $replyQueue = new AMQPQueue($channel);
                $replyQueue->setName($msg->getReplyTo());
                $replyKey = $this->routing_key . ".response";
                $replyQueue->bind($exchange->getName(), $replyKey);

                $exchange->publish(
                    json_encode($response),
                    $replyKey,
                    AMQP_NOPARAM,
                    ['correlation_id' => $msg->getCorrelationId()]
                );

                // ACK original request now that we published a reply
                $queue->ack($msg->getDeliveryTag());
                return;
            }

        } catch (Exception $e) {
            error_log("rabbitMQServer::process_message exception: " . $e->getMessage());
        }

        // One-way message (no reply expected)
        $body    = $msg->getBody();
        $payload = json_decode($body, true);
        if (isset($this->callback)) {
            call_user_func($this->callback, $payload);
        }
        $queue->ack($msg->getDeliveryTag());
        echo "processed one-way message\n";
    }

    function process_requests($callback)
    {
        try {
            $this->callback = $callback;

            $params = [
                'host'     => $this->BROKER_HOST,
                'port'     => $this->BROKER_PORT,
                'login'    => $this->USER,
                'password' => $this->PASSWORD,
                'vhost'    => $this->VHOST,
            ];
            $conn = new AMQPConnection($params);
            $conn->connect();

            $channel  = new AMQPChannel($conn);
            $exchange = new AMQPExchange($channel);
            $exchange->setName($this->exchange);
            $exchange->setType($this->exchange_type);

            $this->conn_queue = new AMQPQueue($channel);
            $this->conn_queue->setName($this->queue);
            $this->conn_queue->bind($exchange->getName(), $this->routing_key);

            // Use the two-arg consume signature so we get the queue instance here:
            $this->conn_queue->consume(function(AMQPEnvelope $msg, AMQPQueue $q) {
                $this->process_message($msg, $q);
            });

            // If your extension supports ->wait() loop with callbacks, you could keep it,
            // but consume() already runs until cancelled.

        } catch (Exception $e) {
            trigger_error("Failed to start request processor: " . $e, E_USER_ERROR);
        }
    }
}

class rabbitMQClient
{
    private string $machineName = "";
    public string $BROKER_HOST;
    private int $BROKER_PORT;
    private string $USER;
    private string $PASSWORD;
    private string $VHOST;
    private string $exchange;
    private string $queue;
    private string $routing_key = '*';
    private array  $response_queue = [];
    private string $exchange_type = "topic";

    /** formerly dynamic: */
    private ?AMQPQueue $conn_queue = null;
    private ?AMQPQueue $callback_queue = null;

    function __construct($machine, $server = "rabbitMQ")
    {
        $machine = getHostInfo(array($machine));
        $this->machineName = $server;

        $this->BROKER_HOST = $machine[$server]["BROKER_HOST"];
        $this->BROKER_PORT = (int)$machine[$server]["BROKER_PORT"];
        $this->USER        = $machine[$server]["USER"];
        $this->PASSWORD    = $machine[$server]["PASSWORD"];
        $this->VHOST       = $machine[$server]["VHOST"];
        if (isset($machine[$server]["EXCHANGE_TYPE"])) {
            $this->exchange_type = $machine[$server]["EXCHANGE_TYPE"];
        }
        if (isset($machine[$server]["AUTO_DELETE"])) {
            // keep for completeness; not used directly in this class
            $auto_delete = (bool)$machine[$server]["AUTO_DELETE"];
        }
        $this->exchange = $machine[$server]["EXCHANGE"];
        $this->queue    = $machine[$server]["QUEUE"];
    }

    function process_response(AMQPEnvelope $response)
    {
        $uid = $response->getCorrelationId();
        if (!isset($this->response_queue[$uid])) {
            echo "unknown uid\n";
            return true; // keep consuming
        }

        // ACK on the **callback** queue that delivered this response
        if ($this->callback_queue instanceof AMQPQueue) {
            $this->callback_queue->ack($response->getDeliveryTag());
        } else {
            // guard: we should always have it, but avoid fatal
            error_log("rabbitMQClient::process_response WARNING: callback_queue is null; cannot ack");
        }

        $body    = $response->getBody();
        $payload = json_decode($body, true);
        if (!isset($payload)) {
            $payload = "[empty response]";
        }
        $this->response_queue[$uid] = $payload;
        return false; // stop consuming (we got our message)
    }

    function send_request($message)
    {
        $uid = uniqid('', true);
        $json_message = json_encode($message);

        try {
            $params = [
                'host'     => $this->BROKER_HOST,
                'port'     => $this->BROKER_PORT,
                'login'    => $this->USER,
                'password' => $this->PASSWORD,
                'vhost'    => $this->VHOST,
            ];

            $conn = new AMQPConnection($params);
            $conn->connect();

            $channel  = new AMQPChannel($conn);
            $exchange = new AMQPExchange($channel);
            $exchange->setName($this->exchange);
            $exchange->setType($this->exchange_type);

            // Response (callback) queue
            $callback_queue = new AMQPQueue($channel);
            $callback_queue->setName($this->queue . "_response");
            // replace deprecated ->declare() with ->declareQueue()
            $callback_queue->declareQueue();
            $callback_queue->bind($exchange->getName(), $this->routing_key . ".response");

            // Save it on the object so we can ack on **this** queue
            $this->callback_queue = $callback_queue;

            // Request queue
            $this->conn_queue = new AMQPQueue($channel);
            $this->conn_queue->setName($this->queue);
            $this->conn_queue->bind($exchange->getName(), $this->routing_key);

            // Publish request with reply_to and correlation_id
            $exchange->publish(
                $json_message,
                $this->routing_key,
                AMQP_NOPARAM,
                [
                    'reply_to'       => $callback_queue->getName(),
                    'correlation_id' => $uid
                ]
            );

            $this->response_queue[$uid] = "waiting";

            // Consume **just the matching correlation_id** response
            $timeoutSeconds = 30; // tune as needed
            $deadline = time() + $timeoutSeconds;

            // We wrap consume() to stop after we receive our response OR after timeout.
            $callback = function(AMQPEnvelope $env, AMQPQueue $q) use ($uid, &$deadline) {
                if ($env->getCorrelationId() !== $uid) {
                    // Not our message — either requeue or ack+ignore. We’ll ack to avoid redelivery storms.
                    $q->ack($env->getDeliveryTag());
                    return true; // keep waiting for ours
                }
                // It’s ours — delegate to process_response (which will ack on callback queue)
                $stop = $this->process_response($env);
                return $stop; // false from process_response means “stop”; we invert here as needed
            };

            // Since AMQPQueue::consume() doesn’t have a native timeout in all builds,
            // emulate it with a loop of get() + sleep(…)
            while (time() < $deadline) {
                $env = $callback_queue->get(AMQP_AUTOACK); // auto-ack here to avoid unacked pileups
                if ($env instanceof AMQPEnvelope) {
                    // manual invoke logic: we already auto-acked above, just store the payload
                    $cid = $env->getCorrelationId();
                    if ($cid === $uid) {
                        $payload = json_decode($env->getBody(), true);
                        if (!isset($payload)) { $payload = "[empty response]"; }
                        $this->response_queue[$uid] = $payload;
                        break;
                    }
                } else {
                    usleep(200000); // 200ms
                }
            }

            $response = $this->response_queue[$uid] ?? null;
            unset($this->response_queue[$uid]);

            // Cleanup: optional but nice
            try { $callback_queue->cancel(); } catch (\Throwable $e) {}
            try { $conn->disconnect(); } catch (\Throwable $e) {}

            if ($response === null || $response === "waiting") {
                throw new RuntimeException("RPC response timeout for uid=$uid");
            }

            return $response;

        } catch (Exception $e) {
            die("failed to send message to exchange: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Fire-and-forget publish (no response expected)
     */
    function publish($message)
    {
        $json_message = json_encode($message);
        try {
            $params = [
                'host'     => $this->BROKER_HOST,
                'port'     => $this->BROKER_PORT,
                'login'    => $this->USER,
                'password' => $this->PASSWORD,
                'vhost'    => $this->VHOST,
            ];
            $conn = new AMQPConnection($params);
            $conn->connect();

            $channel  = new AMQPChannel($conn);
            $exchange = new AMQPExchange($channel);
            $exchange->setName($this->exchange);
            $exchange->setType($this->exchange_type);

            $this->conn_queue = new AMQPQueue($channel);
            $this->conn_queue->setName($this->queue);
            $this->conn_queue->bind($exchange->getName(), $this->routing_key);

            $ok = $exchange->publish($json_message, $this->routing_key);

            try { $conn->disconnect(); } catch (\Throwable $e) {}

            return $ok;
        } catch (Exception $e) {
            die("failed to send message to exchange: " . $e->getMessage() . "\n");
        }
    }
}
