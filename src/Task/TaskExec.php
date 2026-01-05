<?php

namespace Vatcron\Task;

use Symfony\Component\Process\Process;
use support\Redis;
use Vatcron\Client;
use Workerman\Timer;

/**
 * 任务执行器
 * 支持异步协程执行各种类型的任务
 */
class TaskExec
{
    protected $config;
    protected $actuator;
    protected $taskManager;
    protected $logger;


    public function __construct()
    {
        $this->taskManager = new TaskManager();
    }


    public function setLogger($logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * 执行任务
     */
    public function execute($task, $logId)
    {
        $startTime = microtime(true);
        
        try {
            $config = config('plugin.vatcron.app', []);
             if($config['enable_coroutine']){
                $actuator = new CoroutineExec();
            }else{
                $actuator = new AsyncTaskExec();
            }
            // 实时推送开始日志
            $this->pushExecutionLog($task['id'], $logId, "开始执行任务: {$task['name']}({$task['id']})");
            
            // 根据命令类型执行任务
            $result = $actuator->run($task, $logId);
            
            $duration = round(microtime(true) - $startTime, 2);
            $this->pushExecutionLog($task['id'], $logId, "任务执行成功，耗时: {$duration}秒", 'success');
            
            return $result;
            
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            $this->pushExecutionLog($task['id'], $logId, "任务执行失败，耗时: {$duration}秒，错误: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * 格式化任务命令和参数
     * @param mixed $task
     */
    public function formatCommand($task)
    {
        // 解析任务命令
        $command = trim($task['command']);
        return $command;
    }

    /**
     * 创建进程执行任务
     */
    public function createProcess($task, $logId)
    {
         echo "开始创建Command任务子进程「{$task['id']}」\n";
        $command = explode(' ', $this->formatCommand($task));
        $process = new Process($command);
        $timeout = $task['timeout'] ?? 300;
        $process->setTimeout($timeout);
        $process->start(function ($type, $buffer) use ($task, $logId) {
            echo '执行返回结果：'.$buffer;
            if ($type === Process::ERR) {
                // 错误输出
                $this->pushExecutionLog($task['id'], $logId, "执行错误输出：{$buffer}", 'error');
            } else {
                // 标准输出
                $this->pushExecutionLog($task['id'], $logId, "执行标准输出：{$buffer}");
            }
        });
        $this->processFinish($logId, $process);
        $pid = $process->getPid();
        $this->pushExecutionLog($task['id'], $logId, "创建任务子进程ID: {$pid}");
        echo "创建任务子进程ID: {$pid}\n";
    }


    public function processFinish($logId, $process)
    {
        $timerId = Timer::add(2, function () use ($logId, $process, &$timerId) {
            if(!$process->isRunning()){
                $exitCode = $process->getExitCode();
                $this->taskManager->logTaskEnd(
                    $logId,
                    $exitCode === 0 ? 'success' : 'error',
                    $process->getOutput(),
                );
                // 关闭定时器
                Timer::del($timerId);
            }
        });
    }

    /**
     * 推送执行日志
     */
    protected function pushExecutionLog($taskId, $logId, $message, $level = 'info')
    {
        $logData = [
            'task_id'   => $taskId,
            'log_id'    => $logId,
            'level'     => $level,
            'message'   => $message,
            'timestamp' => time(),
            'pid'       => getmypid()
        ];
        
        try {
            // 通过Redis发布实时日志
            Redis::publish(config('plugin.vatcron.app.log_subscribe'), \json_encode($logData, JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            // Redis连接失败时记录到文件
            echo "Vatcron Log Error: {$e->getMessage()}";
        }
    }

    /**
     * 记录性能日志
     */
    protected function logPerformance($logId, $operation, $startTime, $endTime = null)
    {
        if (!$endTime) {
            $endTime = microtime(true);
        }
        
        $duration = round(($endTime - $startTime) * 1000, 2); // 毫秒
        
        if ($duration > 1000) { // 超过1秒记录警告
            $this->logger->warning($logId, "{$operation} 执行时间过长: {$duration}ms");
        }
        
        $this->logger->debug($logId, "{$operation} 执行时间: {$duration}ms");
    }
}