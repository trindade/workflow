<?php

require_once __DIR__.'/../../../../bootstrap.php';

$amqpCon = \Scrutinizer\RabbitMQ\Util\DsnUtils::createCon($_SERVER['CONFIG']['rabbitmq']['dsn']);

$worker = new \Scrutinizer\Workflow\Client\Activity\CallbackActivityWorker(
    $amqpCon,
    new \Scrutinizer\RabbitMQ\Rpc\RpcClient($amqpCon, \JMS\Serializer\SerializerBuilder::create()->build()),
    $_SERVER['argv'][1],
    function ($input) {
        throw new \Exception('Activity failed.');
    }
);
$worker->run();