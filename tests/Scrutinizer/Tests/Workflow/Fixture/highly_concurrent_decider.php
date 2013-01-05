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
    function (\Scrutinizer\Workflow\Client\Transport\WorkflowExecution $execution, \Scrutinizer\Workflow\Client\Decider\DecisionsBuilder $builder) {
        if ($execution->isInitialDecision()) {
            for ($i=0; $i<50; $i++) {
                $builder->scheduleActivity('doA', 'foo', array('i' => $i));
            }

            return;
        }

        $scheduled = false;
        foreach ($execution->getClosedActivityTasksSinceLastDecision() as $task) {
            /** @var $task \Scrutinizer\Workflow\Client\Transport\ActivityTask */

            if ( ! isset($task->control['i']) || $task->control['i'] % 5 !== 0) {
                continue;
            }

            $scheduled = true;
            $builder->scheduleActivity('doA', 'bar', array('from' => $task->control['i']));
        }

        if ($scheduled || $execution->hasOpenActivities()) {
            return;
        }

        $builder->succeedExecution();
    }
);
$decider->run();