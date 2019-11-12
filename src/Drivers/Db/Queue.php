<?php
/**
 * @link http://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Yiisoft\Yii\Queue\Drivers\Db;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Db\Connection;
use Yiisoft\Db\ConnectionInterface;
use Yiisoft\Db\Query;
use Yiisoft\Mutex\Mutex;
use Yiisoft\Serializer\SerializerInterface;
use Yiisoft\Yii\Queue\Cli\Queue as CliQueue;

/**
 * Db Queue.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class Queue extends CliQueue
{
    /**
     * @var Connection|array|string
     */
    private $db;
    /**
     * @var Mutex|array|string
     */
    private $mutex;
    /**
     * @var int timeout
     */
    public $mutexTimeout = 3;
    /**
     * @var string table name
     */
    public $tableName = '{{%queue}}';
    /**
     * @var string
     */
    public $channel = 'queue';
    /**
     * @var bool ability to delete released messages from table
     */
    public $deleteReleased = true;
    /**
     * @var string command class name
     */
    public $commandClass = Command::class;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        SerializerInterface $serializer,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        ConnectionInterface $db,
        Mutex $mutex
    ) {
        parent::__construct($serializer, $eventDispatcher, $logger);
        $this->db = $db;
        $this->mutex = $mutex;
    }

    public function getDb()
    {
        return $this->db;
    }

    /**
     * Listens queue and runs each job.
     *
     * @param bool $repeat  whether to continue listening when queue is empty.
     * @param int  $timeout number of seconds to sleep before next iteration.
     *
     * @return null|int exit code.
     *
     * @internal for worker command only
     *
     * @since 2.0.2
     */
    public function run($repeat, $timeout = 0)
    {
        return $this->runWorker(function (callable $canContinue) use ($repeat, $timeout) {
            while ($canContinue()) {
                if ($payload = $this->reserve()) {
                    if ($this->handleMessage(
                        $payload['id'],
                        $payload['job'],
                        $payload['ttr'],
                        $payload['attempt']
                    )) {
                        $this->release($payload);
                    }
                } elseif (!$repeat) {
                    break;
                } elseif ($timeout) {
                    sleep($timeout);
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function status($id)
    {
        $payload = (new Query())
            ->from($this->tableName)
            ->where(['id' => $id])
            ->one($this->db);

        if (!$payload) {
            if ($this->deleteReleased) {
                return self::STATUS_DONE;
            }

            throw new \InvalidArgumentException("Unknown message ID: $id.");
        }

        if (!$payload['reserved_at']) {
            return self::STATUS_WAITING;
        }

        if (!$payload['done_at']) {
            return self::STATUS_RESERVED;
        }

        return self::STATUS_DONE;
    }

    /**
     * Clears the queue.
     *
     * @since 2.0.1
     */
    public function clear()
    {
        $this->db->createCommand()
            ->delete($this->tableName, ['channel' => $this->channel])
            ->execute();
    }

    /**
     * Removes a job by ID.
     *
     * @param int $id of a job
     * @return bool
     * @throws \Yiisoft\Db\Exception
     * @since 2.0.1
     */
    public function remove($id)
    {
        return (bool) $this->db->createCommand()
            ->delete($this->tableName, ['channel' => $this->channel, 'id' => $id])
            ->execute();
    }

    /**
     * {@inheritdoc}
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        $this->db->createCommand()->insert($this->tableName, [
            'channel'   => $this->channel,
            'job'       => $message,
            'pushed_at' => time(),
            'ttr'       => $ttr,
            'delay'     => $delay,
            'priority'  => $priority ?: 1024,
        ])->execute();
        $tableSchema = $this->db->getTableSchema($this->tableName);

        return $this->db->getLastInsertID($tableSchema->sequenceName);
    }

    /**
     * Takes one message from waiting list and reserves it for handling.
     *
     * @throws Exception in case it hasn't waited the lock
     *
     * @return array|false payload
     */
    protected function reserve()
    {
        return $this->db->useMaster(function () {
            if (!$this->mutex->acquire(__CLASS__.$this->channel, $this->mutexTimeout)) {
                throw new Exception('Has not waited the lock.');
            }

            try {
                $this->moveExpired();

                // Reserve one message
                $payload = (new Query())
                    ->from($this->tableName)
                    ->andWhere(['channel' => $this->channel, 'reserved_at' => null])
                    ->andWhere('[[pushed_at]] <= :time - [[delay]]', [':time' => time()])
                    ->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
                    ->limit(1)
                    ->one($this->db);
                if (is_array($payload)) {
                    $payload['reserved_at'] = time();
                    $payload['attempt'] = (int) $payload['attempt'] + 1;
                    $this->db->createCommand()->update($this->tableName, [
                        'reserved_at' => $payload['reserved_at'],
                        'attempt'     => $payload['attempt'],
                    ], [
                        'id' => $payload['id'],
                    ])->execute();

                    // pgsql
                    if (is_resource($payload['job'])) {
                        $payload['job'] = stream_get_contents($payload['job']);
                    }
                }
            } finally {
                $this->mutex->release(__CLASS__.$this->channel);
            }

            return $payload;
        });
    }

    /**
     * @param array $payload
     */
    protected function release($payload)
    {
        if ($this->deleteReleased) {
            $this->db->createCommand()->delete(
                $this->tableName,
                ['id' => $payload['id']]
            )->execute();
        } else {
            $this->db->createCommand()->update(
                $this->tableName,
                ['done_at' => time()],
                ['id'      => $payload['id']]
            )->execute();
        }
    }

    /**
     * Moves expired messages into waiting list.
     */
    private function moveExpired()
    {
        if ($this->reserveTime !== time()) {
            $this->reserveTime = time();
            $this->db->createCommand()->update(
                $this->tableName,
                ['reserved_at' => null],
                '[[reserved_at]] < :time - [[ttr]] and [[done_at]] is null',
                [':time' => $this->reserveTime]
            )->execute();
        }
    }

    private $reserveTime;
}
