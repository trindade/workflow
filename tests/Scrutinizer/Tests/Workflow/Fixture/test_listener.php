<?php

require_once __DIR__.'/../../../../bootstrap.php';

$amqpCon = \Scrutinizer\RabbitMQ\Util\DsnUtils::createCon($_SERVER['CONFIG']['rabbitmq']['dsn']);

if ( ! is_file($_SERVER['argv'][1])) {
    echo 'The logfile does not exist.';
    exit(1);
}

$serializer = \JMS\Serializer\SerializerBuilder::create()->build();

$listener = new \Scrutinizer\Workflow\Client\Listener\SimpleCallableListener($amqpCon, '#',
    function(\Scrutinizer\Workflow\Client\Transport\Event $event) use ($serializer) {
        file_put_contents($_SERVER['argv'][1], $serializer->serialize($event, 'json')."\n", FILE_APPEND);
    }
);
$listener->run();