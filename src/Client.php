<?php

declare(strict_types=1);

namespace Vatcron;

/**
 * TCP客户端，用于与vatcron TCP服务器通信
 * 支持任务的增删改查、执行和状态切换
 */
class Client
{
    private $client;
    protected static $instance = null;
    protected $serverAddress;

    /**
     * 构造函数
     * @param string $serverAddress TCP服务器地址，格式：host:port
     */
    public function __construct($serverAddress = null)
    {
        $serverAddress = $serverAddress ?? config('plugin.vatcron.app.listen');
        $this->client = stream_socket_client('tcp://' . $serverAddress, $errno, $errstr, 30);
        if (!$this->client) {
            throw new \Exception("Failed to connect to server: {$errstr} ({$errno})");
        }
    }

    /**
     * 获取单例实例
     * @param string $serverAddress TCP服务器地址
     * @return VatcronClient
     */
    public static function instance($serverAddress = null)
    {
        if (!static::$instance) {
            static::$instance = new static($serverAddress);
        }
        return static::$instance;
    }

    /**
     * 发送请求
     * @param array $param 请求参数
     * @return mixed 响应结果（解码后的JSON对象）
     */
    public function request(array $param)
    {
        if (!$this->client) {
            throw new \Exception("TCP connection not established");
        }

        fwrite($this->client, json_encode($param) . "\n"); // Text协议末尾有个换行符"\n"
        $result = fgets($this->client, 10240000);
        if ($result === false) {
            throw new \Exception("Failed to receive response from server");
        }
        return json_decode($result);
    }

    /**
     * 创建任务
     * @param array $taskData 任务数据
     * @return mixed 响应结果
     */
    public function createTask(array $taskData)
    {
        return $this->request($taskData);
    }

    /**
     * 更新任务
     * @param int $taskId 任务ID
     * @param array $taskData 任务数据
     * @return mixed 响应结果
     */
    public function updateTask($taskId, array $taskData)
    {
        return $this->request([
            'action' => 'update_task',
            'task_id' => $taskId,
            'task' => $taskData
        ]);
    }

    /**
     * 删除任务
     * @param int $taskId 任务ID
     * @return mixed 响应结果
     */
    public function deleteTask($taskId)
    {
        return $this->request([
            'action' => 'delete_task',
            'task_id' => $taskId
        ]);
    }

    /**
     * 执行任务
     * @param int|null $taskId 任务ID
     * @param array|null $taskData 任务数据（当task_id为null时使用）
     * @return mixed 响应结果
     */
    public function executeTask($taskId = null, array $taskData = null)
    {
        $params = ['action' => 'execute_task'];
        
        if ($taskId !== null) {
            $params['task_id'] = $taskId;
        } elseif ($taskData !== null) {
            $params['task'] = $taskData;
        } else {
            throw new \InvalidArgumentException('Either task_id or taskData must be provided');
        }
        
        return $this->request($params);
    }

    /**
     * 获取任务状态
     * @param int $logId 日志ID
     * @return mixed 响应结果
     */
    public function getTaskStatus($logId)
    {
        return $this->request([
            'action' => 'get_task_status',
            'log_id' => $logId
        ]);
    }

    /**
     * 获取任务列表
     * @return mixed 响应结果
     */
    public function getTaskList()
    {
        return $this->request([
            'action' => 'list_tasks'
        ]);
    }

    /**
     * 切换任务状态
     * @param int $taskId 任务ID
     * @return mixed 响应结果
     */
    public function toggleTask($taskId)
    {
        return $this->request([
            'action' => 'toggle_task',
            'task_id' => $taskId
        ]);
    }

    /**
     * 心跳检测
     * @return mixed 响应结果
     */
    public function ping()
    {
        return $this->request([
            'action' => 'ping'
        ]);
    }

    /**
     * 关闭连接
     */
    public function close()
    {
        if ($this->client) {
            fclose($this->client);
            $this->client = null;
        }
    }

    /**
     * 析构函数，关闭连接
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * 获取当前连接的服务器地址
     * @return string
     */
    public function getServerAddress()
    {
        return $this->serverAddress;
    }
}

// 使用示例
/*
// 初始化客户端
$client = VatcronClient::instance('127.0.0.1:2347');

// 执行任务
$result = $client->executeTask(1);

// 获取任务列表
$tasks = $client->getTaskList();

// 切换任务状态
$result = $client->toggleTask(1);

// 删除任务
$result = $client->deleteTask(1);
*/