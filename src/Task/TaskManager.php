<?php

namespace Vatcron\Task;

use think\facade\Db;
use support\Redis;
use Vatcron\Utils\CronParser;
use support\Log;

/**
 * 任务管理器
 * 负责任务的管理、锁机制、日志记录等
 */
class TaskManager
{
    protected $config;
    protected $logger;

    public function __construct()
    {
        $this->config = config('plugin.vatcron.app', []);
    }

    /**
     * 设置日志记录器
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
    

    /**
     * 获取需要执行的任务
     */
    public function getDueTasks()
    {
        $currentTime = date('Y-m-d H:i:s');
        
        return Db::table($this->config['table_cron'])
            ->where('status', 0)
            ->where(function($query) use ($currentTime) {
                $query->where('next_run_time', '<=', $currentTime)
                      ->whereOr('next_run_time', null);
            })
            ->select();
    }

    /**
     * 获取任务锁
     */
    protected function acquireLock($task)
    {
        $lockKey = $this->config['lock_prefix'] . $task['id'];
        // 使用Redis分布式锁
        if($task['lock_time']){
            if(Redis::set($lockKey, time(), 'EX', $task['lock_time'], 'NX')){
                return true;
            }else{
                echo "任务 {$task['id']} 已被锁定，跳过执行\n";
                Log::info("任务 {$task['id']} 已被锁定，跳过执行");
            }
        }
    
        return false;
    }

    /**
     * 释放任务锁
     */
    protected function releaseLock($taskId)
    {
        try{
            $lockKey = $this->config['lock_prefix'] . $taskId;
            Redis::del($lockKey);
        }catch(\Exception $e){
            echo "释放任务锁失败：{$e->getMessage()}\n";
            Log::info("释放任务锁失败：{$e->getMessage()}");
        }
    }

    /**
     * 记录任务开始执行
     */
    public function logTaskStart($task)
    {
        $this->acquireLock($task);
        $logId = Db::table($this->config['table_log'])->insertGetId([
            'cron_id' => $task['id'],
            'task_name' => $task['name'],
            'status' => 'running',
            'start_time' => date('Y-m-d H:i:s'),
            'pid' => getmypid(),
            'retry_count' => Redis::get("vatcron:retry_count:{$task['id']}") ?: 0
        ]);

        // 更新任务最后执行时间
        Db::table($this->config['table_cron'])
            ->where('id', $task['id'])
            ->update([
                'last_run_time' => date('Y-m-d H:i:s'),
                'next_run_time' => $this->calculateNextRunTime($task)
            ]);

        return $logId;
    }

    /**
     * 记录任务结束
     */
    public function logTaskEnd($logId, $status, $output = null, $error = null)
    {
        $cronLog = Db::table($this->config['table_log'])->where('id', $logId)->find();
        $this->releaseLock($cronLog['cron_id']);

        $updateData = [
            'status' => $status,
            'end_time' => date('Y-m-d H:i:s'),
            'duration' => Db::raw('TIMESTAMPDIFF(SECOND, start_time, NOW())')
        ];

        if ($output) {
            $updateData['output'] = is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE);
        }

        if ($error) {
            $updateData['error'] = $error;
        }

        Db::table($this->config['table_log'])->where('id', $logId)->update($updateData);
    }

    /**
     * 计算下次执行时间
     */
    protected function calculateNextRunTime($task)
    {
        try {
            $cronExpression = $task['cron_expression'];
            // 检查cron表达式字段数量
            $parts = preg_split('/\s+/', trim($cronExpression));
            // 如果是5个字段（标准cron表达式：分 时 日 月 周），转换为6个字段格式（秒 分 时 日 月 周），秒字段默认为0
            if (count($parts) === 5) {
                array_unshift($parts, '0'); // 在前面加上秒字段，默认为0
                $cronExpression = implode(' ', $parts);
            }
            $nextRunTime = date('Y-m-d H:i:s', CronParser::getNextRunTime($cronExpression));
            return $nextRunTime;
        } catch (\Exception $e) {
            echo "Invalid cron expression for task {$task['name']}: {$task['cron_expression']}\n{$e->getMessage()}";
            return null;
        }
    }

    /**
     * 创建新任务
     */
    public function createTask($data)
    {
        $data['next_run_time'] = $this->calculateNextRunTime($data);
        $id = Db::table($this->config['table_cron'])->insertGetId($data);
        return json_encode(['code' => 200,'msg' => '创建任务成功', 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 更新任务
     */
    public function updateTask($data)
    {
        $task = Db::table($this->config['table_cron'])->where('id', $data['id'])->find();   
        if ($task) {
            $taskArray = is_array($task) ? $task : $task->toArray();
            $data['next_run_time'] = $this->calculateNextRunTime(array_merge(
                $taskArray,
                $data
            ));
        }
        
        return Db::table($this->config['table_cron'])
            ->where('id', $data['id'])
            ->update($data);
    }

    /**
     * 删除任务
     */
    public function deleteTask($data)
    {
        // 删除相关日志
        try{
            Db::table($this->config['table_log'])->where('cron_id', $data['id'])->delete();
            Db::table($this->config['table_cron'])->where('id', $data['id'])->delete(); 
            $this->releaseLock($data['id']);
            return true;
        }catch(\Exception $e){
            $this->logger->error("删除任务失败：{$e->getMessage()}");
        }
        return false;
    }

    /**
     * 获取任务列表
     */
    public function getTaskList($page = 1, $pageSize = 20)
    {
        return Db::table($this->config['table_cron'])
            ->order('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * 获取任务执行日志
     */
    public function getTaskLogs($cronId, $page = 1, $pageSize = 20)
    {
        return Db::table($this->config['table_log'])
            ->where('cron_id', $cronId)
            ->order('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
    
    /**
     * 根据ID获取任务
     */
    public function getTaskById($cronId)
    {
        return Db::table($this->config['table_cron'])
            ->where('id', $cronId)
            ->find();
    }
    
    /**
     * 获取所有任务
     */
    public function getAllTasks()
    {
        return Db::table($this->config['table_cron'])
            ->order('id', 'desc')
            ->select();
    }
    
    /**
     * 根据日志ID获取任务日志
     */
    public function getTaskLogById($logId)
    {
        return Db::table($this->config['table_log'])
            ->where('id', $logId)
            ->find();
    }
    
    /**
     * 重新加载任务
     */
    public function reloadTask($taskId)
    {
        $task = $this->getTaskById($taskId);
        if (!$task) {
            throw new \Exception("Task not found: {$taskId}");
        }
        
        // 重新计算下次执行时间
        $nextRunTime = $this->calculateNextRunTime($task);
        
        // 更新任务
        Db::table($this->config['table_cron'])
            ->where('id', $taskId)
            ->update([
                'next_run_time' => $nextRunTime,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        
        return true;
    }
    
    /**
     * 启动任务
     */
    public function startTask($taskId)
    {
        // 将任务状态设置为启用
        return Db::table($this->config['table_cron'])
            ->where('id', $taskId)
            ->update([
                'enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
    
    /**
     * 关闭任务
     */
    public function closeTask($taskId)
    {
        // 将任务状态设置为禁用
        return Db::table($this->config['table_cron'])
            ->where('id', $taskId)
            ->update([
                'enabled' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
    
    /**
     * 停止任务执行
     */
    public function stopTask($taskId)
    {
        // 设置任务停止标志
        $stopKey = "vatcron:stop:{$taskId}";
        Redis::setex($stopKey, 3600, time());
        
        // 释放任务锁
        $this->releaseLock($taskId);
        
        // 终止相关子进程
        $this->terminateTaskProcesses($taskId);
        
        return true;
    }
    
    /**
     * 终止任务相关的子进程
     */
    protected function terminateTaskProcesses($taskId)
    {
        // 获取任务相关的进程ID
        $processIdsKey = "vatcron:process_ids:{$taskId}";
        $processIds = Redis::lrange($processIdsKey, 0, -1);
        
        if (!empty($processIds)) {
            foreach ($processIds as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    // 尝试终止子进程
                    if (function_exists('posix_kill')) {
                        @posix_kill($pid, SIGTERM);
                        // 等待一段时间后检查进程是否仍在运行
                        usleep(100000); // 100ms
                        if (function_exists('posix_getpgid') && posix_getpgid($pid) !== false) {
                            // 进程仍在运行，强制终止
                            @posix_kill($pid, SIGKILL);
                        }
                    } elseif (DIRECTORY_SEPARATOR === '/' && function_exists('exec')) {
                        // Linux环境下没有posix扩展时，使用exec命令终止进程
                        @exec("kill -9 {$pid}");
                    }
                }
            }
            
            // 清理进程ID列表
            Redis::del($processIdsKey);
        }
        
        return true;
    }
    
    /**
     * 记录任务的子进程ID
     */
    public function recordTaskProcess($taskId, $pid)
    {
        if ($pid > 0) {
            $processIdsKey = "vatcron:process_ids:{$taskId}";
            Redis::rpush($processIdsKey, $pid);
            Redis::expire($processIdsKey, 3600);
        }
        return true;
    }
    
    /**
     * 移除任务的子进程ID
     */
    public function removeTaskProcess($taskId, $pid)
    {
        if ($pid > 0) {
            $processIdsKey = "vatcron:process_ids:{$taskId}";
            Redis::lrem($processIdsKey, 0, $pid);
        }
        return true;
    }
    
    /**
     * 检查任务是否需要停止
     */
    public function shouldStopTask($taskId)
    {
        $stopKey = "vatcron:stop:{$taskId}";
        return Redis::exists($stopKey);
    }
    
    /**
     * 清除任务停止标志
     */
    public function clearStopFlag($taskId)
    {
        $stopKey = "vatcron:stop:{$taskId}";
        Redis::del($stopKey);
        return true;
    }
    
    /**
     * 重启任务
     */
    public function restartTask($taskId)
    {
        // 先停止任务
        $this->stopTask($taskId);
        
        // 清除停止标志
        $this->clearStopFlag($taskId);
        
        // 启动任务
        $this->startTask($taskId);
        
        // 重新加载任务
        $this->reloadTask($taskId);
        
        return true;
    }
    
    /**
     * 立即执行任务
     */
    public function executeImmediately($taskId)
    {
        $task = $this->getTaskById($taskId);
        if (!$task) {
            throw new \Exception("Task not found: {$taskId}");
        }
        
        // 设置下次执行时间为当前时间
        Db::table('vat_cron')
            ->where('id', $taskId)
            ->update([
                'next_run_time' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        
        return true;
    }
}