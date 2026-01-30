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