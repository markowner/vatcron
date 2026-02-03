<?php

namespace Vatcron\Task;

use Vatcron\Utils\ClassExec;
use Workerman\Coroutine;

class CoroutineExec extends TaskExec{

    protected $task = null;
    /**
     * 执行任务
     */
    public function run($task, $logId){
        $this->task = $task;
        $command = $this->formatCommand($task);
        switch ($task['task_type']) {
            case 1: // command - 直接执行命令
                return $this->command($command, $logId);
            
            case 2: // class - 类方法
                return $this->classMethod($command, $logId);
        
            case 3: // url - HTTP请求
                return $this->url($command, $logId);

            case 4: // shell - Shell命令
                return $this->shell($command, $logId);
            
            default: // 未设置类型，使用自动检测
                $this->pushExecutionLog($this->task['id'], $logId, '任务类型未设置');
                throw new \Exception('任务类型未设置');
        }
    }
    /**
     * 协程执行命令
     */
    public function command($command, $logId)
    {
        Coroutine::create(function() use ($command, $logId) {
            try {
                $this->createProcess($this->task, $logId);
            } catch (\Exception $e) {
                 $errorJson = json_encode([
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], JSON_UNESCAPED_UNICODE);
                echo "Command命令执行失败: {$errorJson}\n";
                $this->logger->error("Command命令执行失败: {$errorJson}");
                $this->pushExecutionLog($this->task['id'], $logId, "Command命令执行失败: {$errorJson}");
                $this->taskManager->logTaskEnd( $logId, 'error', $errorJson);
            }
        });
    }
    /**
     * 协程执行类方法
     */
    public function classMethod($command, $logId)
    {
        Coroutine::create(function() use ($command, $logId) {
            try {
                $result = ClassExec::execute($command);
                $result = json_encode($result, JSON_UNESCAPED_UNICODE);
                $this->pushExecutionLog($this->task['id'], $logId, "类方法执行成功: {$result}");
                $this->taskManager->logTaskEnd($logId, 'success', $result);
            } catch (\Exception $e) {
                $errorJson = json_encode([
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], JSON_UNESCAPED_UNICODE);
                $this->pushExecutionLog($this->task['id'], $logId, "类方法执行失败: {$errorJson}");
                $this->taskManager->logTaskEnd($logId, 'error', $errorJson);
            }
        });
    }

    /**
     * 请求URL
     * @param mixed $command
     * @param mixed $logId
     * @return void
     */
    public function url($command, $logId)
    {
         Coroutine::create(function() use ($command, $logId) {
            $http = new \Workerman\Http\Client();
            $http->get($command, function($response) use ($logId) {
                $this->pushExecutionLog($this->task['id'], $logId, "URL请求结果: {$response->getBody()}");
                $this->taskManager->logTaskEnd($logId, 'success', $response->getBody());
            }, function($err) use ($logId) {
                $this->pushExecutionLog($this->task['id'], $logId, "URL请求失败: {$err}");
                $this->taskManager->logTaskEnd($logId, 'error', $err);
            });
        });
    }
    /**
     * 协程执行Shell命令
     */
    public function shell($command, $logId)
    {
        Coroutine::create(function() use ($command, $logId) {
            try{
                $this->createProcess($this->task, $logId);
            } catch (\Exception $e) {
                $errorJson = json_encode([
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], JSON_UNESCAPED_UNICODE);
                $this->pushExecutionLog($this->task['id'], $logId, "Shell命令执行失败: {$errorJson}");
                $this->taskManager->logTaskEnd($logId, 'error', $errorJson);
            }
        });
    }
    
}