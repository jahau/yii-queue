<?php
/**
 * @link http://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Yiisoft\Yii\Queue;

use yii\base\Behavior;
use yii\helpers\Yii;
use Yiisoft\Yii\Queue\Events\ExecEvent;
use Yiisoft\Yii\Queue\Events\JobEvent;
use Yiisoft\Yii\Queue\Events\PushEvent;
use Yiisoft\Yii\Queue\Jobs\JobInterface;

/**
 * Log Behavior.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class LogBehavior
{
    /**
     * @var Queue
     * {@inheritdoc}
     */
    public $owner;
    /**
     * @var bool
     */
    public $autoFlush = true;

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            PushEvent::AFTER       => 'afterPush',
            ExecEvent::BEFORE      => 'beforeExec',
            ExecEvent::AFTER       => 'afterExec',
            ExecEvent::ERROR       => 'afterError',
            Cli\WorkerEvent::START => 'workerStart',
            Cli\WorkerEvent::STOP  => 'workerStop',
        ];
    }

    /**
     * @param Cli\WorkerEvent $event
     *
     * @throws \yii\exceptions\InvalidConfigException
     * @since 2.0.2
     */
    public function workerStart(Cli\WorkerEvent $event): void
    {
        $title = 'Worker '.$event->getTarget()->getWorkerPid();
        Yii::info("$title is started.", Queue::class);
        Yii::beginProfile($title, Queue::class);
        if ($this->autoFlush) {
            Yii::get('logger')->flush(true);
        }
    }

    /**
     * @param Cli\WorkerEvent $event
     * @throws \yii\exceptions\InvalidConfigException
     */
    public function workerStop(Cli\WorkerEvent $event): void
    {
        $title = 'Worker '.$event->getTarget()->getWorkerPid();
        Yii::endProfile($title, Queue::class);
        Yii::info("$title is stopped.", Queue::class);
        if ($this->autoFlush) {
            Yii::get('logger')->flush(true);
        }
    }

    /**
     * @param JobEvent $event
     *
     * @return string
     *
     * @since 2.0.2
     */
    protected function getJobTitle(JobEvent $event): string
    {
        $name = $event->job instanceof JobInterface ? get_class($event->job) : 'unknown job';

        return "[$event->id] $name";
    }

    /**
     * @param ExecEvent $event
     *
     * @return string
     *
     * @since 2.0.2
     */
    protected function getExecTitle(ExecEvent $event): string
    {
        $title = $this->getJobTitle($event);
        $extra = "attempt: $event->attempt";
        if ($pid = $event->sender->getWorkerPid()) {
            $extra .= ", PID: $pid";
        }

        return "$title ($extra)";
    }
}
