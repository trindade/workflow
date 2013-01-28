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
            $builder->adoptExecution($execution->input);

            return;
        }

        $adoptionTask = $execution->tasks->find(function(\Scrutinizer\Workflow\Client\Transport\AbstractTask $task) {
            return $task instanceof \Scrutinizer\Workflow\Client\Transport\AdoptionTask;
        });
        $executionTask = $execution->tasks->find(function(\Scrutinizer\Workflow\Client\Transport\AbstractTask $task) {
            return $task instanceof \Scrutinizer\Workflow\Client\Transport\WorkflowExecutionTask;
        });

        if ($adoptionTask->isDefined() && $executionTask->isDefined() && $executionTask->get()->isClosed()) {
            $builder->succeedExecution();
        }
    }
);
$decider->run();