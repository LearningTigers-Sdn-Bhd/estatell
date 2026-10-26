<?php

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Queue;

/**
 * Make the default queue a real one that only records the class of every job it accepts. Unlike `Queue::fake()`, it
 * runs the real after-commit logic, so a job dispatched with `afterCommit()` inside a transaction that rolls back is
 * never recorded. Returns the list the jobs are added to.
 *
 * @return ArrayObject<int, string>
 */
function recordQueuedJobs(): ArrayObject
{
    /** @var ArrayObject<int, string> $pushed */
    $pushed = new ArrayObject;
    $queue = new class($pushed) extends Queue implements QueueContract
    {
        /** @param  ArrayObject<int, string>  $pushed */
        public function __construct(private ArrayObject $pushed) {}

        public function size($queue = null): int
        {
            return count($this->pushed);
        }

        public function pendingSize($queue = null): int
        {
            return count($this->pushed);
        }

        public function delayedSize($queue = null): int
        {
            return 0;
        }

        public function reservedSize($queue = null): int
        {
            return 0;
        }

        public function creationTimeOfOldestPendingJob($queue = null): ?int
        {
            return null;
        }

        public function push($job, $data = '', $queue = null)
        {
            return $this->later(0, $job, $data, $queue);
        }

        public function pushRaw($payload, $queue = null, array $options = []) {}

        public function later($delay, $job, $data = '', $queue = null)
        {
            return $this->enqueueUsing($job, $this->createPayload($job, $queue, $data), $queue, $delay, function () use ($job): int {
                $this->pushed[] = is_object($job) ? $job::class : (string) $job;

                return count($this->pushed);
            });
        }

        public function pop($queue = null) {}
    };

    app('queue')->addConnector('recording', fn (): ConnectorInterface => new class($queue) implements ConnectorInterface
    {
        public function __construct(private QueueContract $queue) {}

        public function connect(array $config): QueueContract
        {
            return $this->queue;
        }
    });
    config(['queue.connections.recording' => ['driver' => 'recording'], 'queue.default' => 'recording']);

    return $pushed;
}
