<?php
/**
 * @link http://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Yiisoft\Yii\Queue\Tests\Drivers\Db;

use Yiisoft\Db\Query;
use Yiisoft\Yii\Queue\Tests\App\PriorityJob;
use Yiisoft\Yii\Queue\Tests\App\RetryJob;
use Yiisoft\Yii\Queue\Tests\Drivers\CliTestCase;

/**
 * Db Queue Test Case.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
abstract class TestCase extends CliTestCase
{
    public function testRun()
    {
        $job = $this->createSimpleJob();
        $this->getQueue()->push($job);
        $this->runProcess('php yii queue/run');

        $this->assertSimpleJobDone($job);
    }

    public function testStatus()
    {
        $job = $this->createSimpleJob();
        $id = $this->getQueue()->push($job);
        $isWaiting = $this->getQueue()->isWaiting($id);
        $this->runProcess('php yii queue/run');
        $isDone = $this->getQueue()->isDone($id);

        $this->assertTrue($isWaiting);
        $this->assertTrue($isDone);
    }

    public function testPriority()
    {
        $this->getQueue()->withPriority(100)->push(new PriorityJob(['number' => 1]));
        $this->getQueue()->withPriority(300)->push(new PriorityJob(['number' => 5]));
        $this->getQueue()->withPriority(200)->push(new PriorityJob(['number' => 3]));
        $this->getQueue()->withPriority(200)->push(new PriorityJob(['number' => 4]));
        $this->getQueue()->withPriority(100)->push(new PriorityJob(['number' => 2]));
        $this->runProcess('php yii queue/run');

        $this->assertEquals('12345', file_get_contents(PriorityJob::getFileName()));
    }

    public function testListen()
    {
        $this->startProcess('php yii queue/listen 1');
        $job = $this->createSimpleJob();
        $this->getQueue()->push($job);

        $this->assertSimpleJobDone($job);
    }

    public function testLater()
    {
        $this->startProcess('php yii queue/listen 1');
        $job = $this->createSimpleJob();
        $this->getQueue()->withDelay(2)->push($job);

        $this->assertSimpleJobLaterDone($job, 2);
    }

    public function testRetry()
    {
        $this->startProcess('php yii queue/listen 1');
        $job = new RetryJob(uniqid());
        $this->getQueue()->push($job);
        sleep(6);

        $this->assertFileExists($job->getFileName());
        $this->assertEquals('aa', file_get_contents($job->getFileName()));
    }

    public function testClear()
    {
        $this->getQueue()->push($this->createSimpleJob());
        $this->runProcess('php yii queue/clear --interactive=0');
        $actual = (new Query())
            ->from($this->getQueue()->tableName)
            ->where(['channel' => $this->getQueue()->channel])
            ->count('*', $this->getQueue()->db);

        $this->assertEquals(0, $actual);
    }

    public function testRemove()
    {
        $id = $this->getQueue()->push($this->createSimpleJob());
        $this->runProcess("php yii queue/remove $id");
        $actual = (new Query())
            ->from($this->getQueue()->tableName)
            ->where(['channel' => $this->getQueue()->channel, 'id' => $id])
            ->count('*', $this->getQueue()->db);

        $this->assertEquals(0, $actual);
    }

    protected function tearDown(): void
    {
        $this->getQueue()->db->createCommand()
            ->delete($this->getQueue()->tableName)
            ->execute();

        parent::tearDown();
    }
}
