<?php

namespace Vatcron\Process;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use support\Redis;
use Workerman\Coroutine;

/**
 * WebSocket服务进程
 * 用于实时推送任务执行日志到前端
 */
class LogSocket
{
    protected $clients = [];

    protected $channels = [];

    public function __construct()
    {
    }

    public function onWorkerStart(Worker $worker)
    {
        echo "Log WebSocket Server Started\n";
    
        Coroutine::create(function() {
            $this->subscribeRedisChannels();
        });
        
    }

    /**
     * WebSocket连接建立
     */
    public function onConnect(TcpConnection $connection)
    {
        $connectionId = $connection->id;
        $this->clients[$connectionId] = [
            'connection' => $connection,
            'connected_at' => time(),
            'subscribed_channels' => []
        ];
        
        echo "WebSocket Client Connected: {$connectionId}\n";
        
        // 发送连接成功消息
        $connection->send(json_encode([
            'type' => 'connected',
            'message' => 'Connected successfully',
            'timestamp' => time()
        ]));
    }


    /**
     * 处理WebSocket消息
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        $message = json_decode($data, true);
        
        if (!$message || !isset($message['type'])) {
            $connection->send(json_encode([
                'type' => 'error',
                'message' => 'Invalid message format'
            ]));
            return;
        }
        
        $connectionId = $connection->id;
        
        switch ($message['type']) {
            case 'subscribe':
                $this->handleSubscribe($connectionId, $message);
                break;
                
            case 'unsubscribe':
                $this->handleUnsubscribe($connectionId, $message);
                break;
                
            case 'ping':
                $connection->send(json_encode([
                    'type' => 'pong',
                    'timestamp' => time()
                ]));
                break;
            default:
                $connection->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unknown message type'
                ]));
        }
    }

    /**
     * WebSocket连接关闭
     */
    public function onClose(TcpConnection $connection)
    {
        $connectionId = $connection->id;
        unset($this->clients[$connectionId]);
        
        // 取消订阅所有频道
        foreach ($this->channels as $channel => $subscribers) {
            if (isset($subscribers[$connectionId])) {
                unset($this->channels[$channel][$connectionId]);
            }
        }
        
        echo "WebSocket Client Disconnected: {$connectionId}\n";
    }


    public function onStop(Worker $worker)
    {
        // 关闭所有客户端连接
        foreach ($this->clients as $client) {
            try {
                $client['connection']->close();
            } catch (\Exception $e) {
                // 忽略关闭异常
            }
        }
        
        echo "Log WebSocket Server Stopped\n";
    }



    /**
     * 处理订阅请求
     */
    protected function handleSubscribe($connectionId, $message)
    {
        if (!isset($message['channel'])) {
            $this->clients[$connectionId]['connection']->send(json_encode([
                'type' => 'error',
                'message' => 'Channel not specified'
            ]));
            return;
        }
        
        $channel = $message['channel'];
        $this->clients[$connectionId]['subscribed_channels'][$channel] = true;
        $this->channels[$channel][$connectionId] = 1; 
        
        $this->clients[$connectionId]['connection']->send(json_encode([
            'type' => 'subscribed',
            'channel' => $channel,
            'message' => 'Successfully subscribed to channel'
        ]));
        
        echo "Client {$connectionId} subscribed to channel: {$channel}\n";
    }

    /**
     * 处理取消订阅请求
     */
    protected function handleUnsubscribe($connectionId, $message)
    {
        if (!isset($message['channel'])) {
            $this->clients[$connectionId]['connection']->send(json_encode([
                'type' => 'error',
                'message' => 'Channel not specified'
            ]));
            return;
        }
        
        $channel = $message['channel'];
        if (!isset($this->channels[$channel][$connectionId])) {
            $this->clients[$connectionId]['connection']->send(json_encode([
                'type' => 'error',
                'message' => 'Not subscribed to channel'
            ]));
            return;
        }
        
        unset($this->clients[$connectionId]['subscribed_channels'][$channel]);
        unset($this->channels[$channel][$connectionId]);
        
        $this->clients[$connectionId]['connection']->send(json_encode([
            'type' => 'unsubscribed',
            'channel' => $channel,
            'message' => 'Successfully unsubscribed from channel'
        ]));
        
        echo "Client {$connectionId} unsubscribed from channel: {$channel}\n";
    }


    /**
     * 订阅Redis频道
     */
    protected function subscribeRedisChannels()
    {
        // 订阅任务日志频道
        $logSubscribe = config('plugin.vatcron.app.log_subscribe');
        Redis::subscribe([$logSubscribe], function($message, $channel) {
            $this->channelSubscribersNotice($channel, $message);
        });
    }

    /**
     * 广播消息给订阅者
     */
    public function channelSubscribersNotice($channel, $message)
    {
        $data = json_decode($message, true);
        if (!$data) {
            return;
        }
        
        $broadcastData = [
            'type'      => 'log',
            'channel'   => $channel,
            'data'      => $data,
            'timestamp' => time()
        ];
        
        $jsonMessage = json_encode($broadcastData, JSON_UNESCAPED_UNICODE);
        foreach ($this->channels[$channel] as $connectionId => $subscriber) {
            if (isset($this->clients[$connectionId])) {
                try {
                    $this->clients[$connectionId]['connection']->send($jsonMessage);
                } catch (\Exception $e) {
                    echo "Error sending message to client: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    /**
     * 获取连接统计信息
     */
    public function getStats()
    {
        return [
            'connections' => count($this->clients),
            'channels' => $this->getChannelStats(),
        ];
    }

    /**
     * 获取频道统计信息
     */
    protected function getChannelStats()
    {
        return count($this->channels);
    }
}