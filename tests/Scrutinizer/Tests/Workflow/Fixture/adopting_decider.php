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
            if ( ! preg_match('/^adoption\.(.+)$/', $adoptionTask->get()->getName(), $match)) {
                $builder->failExecution(sprintf('Adoption Task name was invalid, got "%s".', $adoptionTask->get()->getName()));
            } elseif ($executionTask->get()->getName() !== $match[1]) {
                $builder->failExecution(sprintf('Workflow name was "%s", but expected "%s".', $executionTask->get()->getName(), $match[1]));
            } else {
                $builder->succeedExecution();
            }
        }
    }
);
$decider->run();