<?php

namespace Vatcron\Process;

use Workerman\Timer;
use Workerman\Worker;
use Vatcron\Task\TaskManager;
use Vatcron\Task\TaskExec;
use support\Redis;

class CronExec
{
    protected $logger;
    protected $taskManager;
    protected $taskExecutor;


    public function __construct($logger)
    {
        $this->logger = $logger;
        $this->taskManager = new TaskManager();
        $this->taskExecutor = (new TaskExec())->setLogger($logger);
    }

    public function onWorkerStart(Worker $worker)
    {
        // 直接调用run方法，而不是使用Timer，避免重复创建连接
        $this->run();
        $this->logger->info("定时任务执行进程 Started (PID: " . getmypid() . ")");
        echo "定时任务执行进程 Started (PID: " . getmypid() . ")\n";
    }

    /**
     * 执行Redis队列任务
     */
    public function run()
    {
        //获取队列任务
        $config = config('plugin.vatcron.app');
        while (true) {
            try {
                // 使用带超时的brPop，避免无限等待
                $cronTask = Redis::brPop($config['cron_queue'], 1);
                if ($cronTask) {
                    $task = json_decode($cronTask[1], true);
                    $this->logger->info("从定时任务队列获取任务: " ,$task);
                    // 开始任务
                    $logId = $this->taskManager->logTaskStart($task);
                    $this->taskExecutor->execute($task, $logId);
                }
            } catch (\Exception $e) {
                $this->logger->error("执行Redis队列任务失败: " . $e->getMessage());
                // 避免异常导致循环退出
                sleep(1);
            }
        }
    }


}
