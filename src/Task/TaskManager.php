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
        $this->config = \config('plugin.vatcron.app');
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
            'pid' => getmypid()
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
    public function getTaskLogs($data = [])
    {
        return Db::table($this->config['table_log'])
            ->where($data['where']??[])
            ->order($data['order'] ?? ['id' => 'desc'])
            ->paginate($data['paginate'] ?? ['page' => $data['paginate']['page'] ?? 1, 'list_rows' => $data['paginate']['list_rows'] ?? 10]);
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
     * 分页获取所有任务
     */
    public function getList($data = [])
    {
        return Db::table($this->config['table_cron'])
            ->where($data['where'] ?? [])
            ->order($data['order'] ?? ['id' => 'desc'])
            ->paginate($data['paginate'] ?? ['page' => $data['paginate']['page'] ?? 1, 'list_rows' => $data['paginate']['list_rows'] ?? 10]);
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
        if (!$nextRunTime) {
            throw new \Exception("Failed to calculate next run time for task: {$taskId}");
        }
        
        // 更新任务
        return Db::table($this->config['table_cron'])
            ->where('id', $taskId)
            ->update([
                'next_run_time' => $nextRunTime
            ]);
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
                'status' => 0
            ]);
    }
    
    /**
     * 关闭任务
     */
    public function closeTask($taskId)
    {
        // 将任务状态设置为禁用
        $rs = Db::table($this->config['table_cron'])
            ->where('id', $taskId)
            ->update([
                'status' => 1
            ]);
        // 释放任务锁
        $this->releaseLock($taskId);    
        return $rs;
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
     * 重启任务
     */
    public function restartTask($taskId)
    {
        // 先停止任务
        $this->closeTask($taskId);
        
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
        Db::table($this->config['table_cron'])
            ->where('id', $taskId)
            ->update([
                'next_run_time' => date('Y-m-d H:i:s')
            ]);
        
        return true;
    }
}