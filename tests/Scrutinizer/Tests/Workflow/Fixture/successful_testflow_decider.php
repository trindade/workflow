<?php

require_once __DIR__.'/../../../../bootstrap.php';

$amqpCon = \Scrutinizer\RabbitMQ\Util\DsnUtils::createCon($_SERVER['CONFIG']['rabbitmq']['dsn']);
$serializer = \JMS\Serializer\SerializerBuilder::create()
    ->addDefaultHandlers()
    ->configureHandlers(function(\JMS\Serializer\Handler\HandlerRegistryInterface $registry) {
        $registry->registerSubscribingHandler(new \Scrutinizer\Workflow\Client\Serializer\TaskHandler());
    })
    ->build();
$decider = new \Scrutinizer\Workflow\Client\Decider\CallbackDecider(
    $amqpCon,
    $serializer,
    'test_deciderqueue',
    new \Scrutinizer\RabbitMQ\Rpc\RpcClient($amqpCon, $serializer),
    function (\Scrutinizer\Workflow\Client\Transport\WorkflowExecution $execution, \Scrutinizer\Workflow\Client\Decider\DecisionsBuilder $decisionsBuilder) {
        $decisionsBuilder->succeedExecution();
    }
);
$decider->run();