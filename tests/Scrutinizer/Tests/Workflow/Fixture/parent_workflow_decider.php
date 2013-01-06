<?php

require_once __DIR__.'/../../../../bootstrap.php';

$amqpCon = \Scrutinizer\RabbitMQ\Util\DsnUtils::createCon($_SERVER['CONFIG']['rabbitmq']['dsn']);
$serializer = \JMS\Serializer\SerializerBuilder::create()
    ->addDefaultHandlers()
    ->configureHandlers(function(\JMS\Serializer\Handler\HandlerRegistryInterface $registry) {
        $registry->registerSubscribingHandler(new \Scrutinizer\Workflow\Client\Serializer\TaskHandler());
    })
    ->build();
$decider = new \Scrutinizer\Workflow\Client\Decider\SimpleCallableDecider(
    $amqpCon,
    $serializer,
    $_SERVER['argv'][1],
    new \Scrutinizer\RabbitMQ\Rpc\RpcClient($amqpCon, $serializer),
    function (\Scrutinizer\Workflow\Client\Transport\WorkflowExecution $execution, \Scrutinizer\Workflow\Client\Decider\DecisionsBuilder $builder) {
        if ($execution->isInitialDecision()) {
            $builder
                ->scheduleActivity('doA', '', array('id' => 'a'))
                ->scheduleChildWorkflow('child_flow', '', array('id' => 'b'))
                ->scheduleActivity('doA', '', array('id' => 'c'))
                ->scheduleChildWorkflow('child_flow', '', array('id' => 'd'))
            ;

            return;
        }

        if ( ! $execution->hasOpenActivities()) {
            $builder->succeedExecution();
        }
    }
);
$decider->run();