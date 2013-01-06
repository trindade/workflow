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
    'test_deciderqueue',
    new \Scrutinizer\RabbitMQ\Rpc\RpcClient($amqpCon, $serializer),
    function (\Scrutinizer\Workflow\Client\Transport\WorkflowExecution $execution, \Scrutinizer\Workflow\Client\Decider\DecisionsBuilder $decisionsBuilder) {
        // Activity A succeeds, Activity B fails.

        if ($execution->isInitialDecision()) {
            $decisionsBuilder->scheduleActivity('doA', 'foo', array('attempt' => 1, 'run' => 1));
            $decisionsBuilder->scheduleActivity('doB', 'bar', array('attempt' => 1, 'run' => 1));

            return;
        }

        /** @var $closedActivityTasks \Scrutinizer\Workflow\Client\Transport\ActivityTask[] */
        $closedActivityTasks = $execution->getClosedActivityTasksSinceLastDecision();

        foreach ($closedActivityTasks as $task) {
            switch ($task->activityName) {
                case 'doA':
                    if ($task->hasSucceeded()) {
                        if ($task->control['run'] < 3) {
                            $decisionsBuilder->scheduleActivity('doA', 'foo', array('attempt' => 1, 'run' => $task->control['run'] + 1));

                            return;
                        }

                        if ( ! $execution->hasOpenActivities()) {
                            $decisionsBuilder->succeedExecution();
                        }

                        return;
                    }

                    if ($task->control['attempt'] < 2) {
                        $decisionsBuilder->rescheduleActivity($task, array('attempt' => $task->control['attempt'] + 1));

                        return;
                    }

                    $decisionsBuilder->failExecution('doA did not succeed after 2 tries.');

                    return;

                case 'doB':
                    if ($task->hasSucceeded()) {
                        if ( ! $execution->hasOpenActivities()) {
                            $decisionsBuilder->succeedExecution();
                        }

                        return;
                    }

                    // We try at most three times and otherwise give up.
                    if ($task->control['attempt'] < 3) {
                        $decisionsBuilder->rescheduleActivity($task, array('attempt' => $task->control['attempt'] + 1));

                        return;
                    }

                    if ( ! $execution->hasOpenActivities()) {
                        $decisionsBuilder->succeedExecution();
                    }

                    return;
            }
        }

        $decisionsBuilder->failExecution('Unknown execution state.');
    }
);
$decider->run();