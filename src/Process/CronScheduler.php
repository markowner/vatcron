<?php

namespace Vatcron\Process;

use Workerman\Timer;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use think\facade\Db;
use support\Redis;
use Vatcron\Task\TaskManager;
use Vatcron\Task\TaskExec;

/**
 * 定时任务调度器进程
 * 负责秒级扫描数据库中的任务并调度执行
 */
class CronScheduler
{
    protected $taskManager;
    protected $taskExecutor;
    protected $config;
    protected $runningTasks = [];
    protected $lastScanTime = 0;

    public function __construct()
    {
        $this->taskManager = new TaskManager();
    }

    public function onWorkerStart(Worker $worker)
    {
        // 启动任务扫描定时器
        $this->config = \config('plugin.vat.vatcron.app');
        $scanInterval = $this->config['scan_interval'] ?? 1;
        Timer::add($scanInterval, [$this, 'scanTasks']);
        echo "定时任务调度器进程 Started (PID: " . getmypid() . ")\n";
    }

    /**
     * 扫描需要执行的任务
     */
    public function scanTasks()
    {
        try {
            $currentTime = time();
            
            // 避免频繁扫描，最小间隔控制
            if ($currentTime - $this->lastScanTime < 0.5) {
                return;
            }
            
            $this->lastScanTime = $currentTime;
    
            // 获取需要执行的任务
            $tasks = $this->taskManager->getDueTasks();
            foreach ($tasks as $task) {
                // 加入定时任务队列
                $cronQueue = $this->config['cron_queue'];
                Redis::lpush($cronQueue, json_encode($task, JSON_UNESCAPED_UNICODE));
            }
            
        } catch (\Exception $e) {
            echo "定时任务调度器 Error: " . $e->getMessage() . "\n";
        }
    }

    public function onMessage(\Workerman\Connection\TcpConnection $connection, $data)
    {
        $data = json_decode($data, true);
        $method = $data['method'] ?? '';
        $args = $data['args'] ?? [];
        
        if (method_exists($this->taskManager, $method)) {
            try {
                $result = call_user_func([$this->taskManager, $method], $args);
                $connection->send(json_encode(['code' => 200, 'msg' => 'Success', 'data' => $result]));
            } catch (\Exception $e) {
                $connection->send(json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => []]));
            }
        } else {
            $connection->send(json_encode(['code' => 404, 'msg' => 'Method not found', 'data' => []]));
        }
    }
}