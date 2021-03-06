<?php
/**
 * @link http://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Yiisoft\Yii\Queue\Tests\Drivers;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Yiisoft\Yii\Queue\Tests\App\PriorityJob;

/**
 * CLI Test Case.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
abstract class CliTestCase extends TestCase
{
    /**
     * @var Process[] ids of started processes
     */
    private $processes = [];

    /**
     * @param string $cmd
     */
    protected function runProcess($cmd)
    {
        $cmd = $this->prepareCmd($cmd);
        $process = new Process($cmd);
        $process->mustRun();

        $error = $process->getErrorOutput();
        $this->assertEmpty($error, "Can not execute '$cmd' command:\n$error");
    }

    /**
     * @param string $cmd
     */
    protected function startProcess($cmd)
    {
        $process = new Process('exec '.$this->prepareCmd($cmd));
        $process->start();
        sleep(2);
        if ($process->getExitCode() !== null) {
            throw new ProcessFailedException($process);
        }
        $this->processes[] = $process;
    }

    /**
     * @param string $cmd
     *
     * @return string
     */
    private function prepareCmd($cmd)
    {
        $class = new \ReflectionClass($this->getQueue());
        $method = $class->getMethod('getCommandId');
        $method->setAccessible(true);

        return strtr($cmd, [
            'php'   => PHP_BINARY,
            'yii'   => 'tests/yii',
            'queue' => $method->invoke($this->getQueue()),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        if (file_exists(PriorityJob::getFileName())) {
            unlink(PriorityJob::getFileName());
        }

        // Kills started processes
        foreach ($this->processes as $process) {
            $process->stop();
        }
        $this->processes = [];

        parent::tearDown();
    }
}
