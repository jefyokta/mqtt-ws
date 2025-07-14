<?php

namespace Jefyokta\Mqttbroker;

use Jefyokta\Mqttbroker\Table\Provider;
use Jefyokta\Mqttbroker\Trait\OverWs;
use Swoole\Server;

class Mqtt
{
    const PUBLISH = 3;
    const SUBSCRIBE = 8;
    const CONNECT = 1;

    protected $events = [
        "publish",
        "subscribe",
        "connect"
    ];
    protected $connectData;

    /**
     * @var Server
     */
    protected  $server;
    protected $bootCallback;

    public function __construct()
    {
        $this->connectData = chr(32) . chr(2) . chr(0) . chr(0);
    }


    protected function decodeValue($data)
    {
        if (strlen($data) < 2) return 0;
        return 256 * ord($data[0]) + ord($data[1]);
    }

    protected function decodeString($data)
    {
        if (strlen($data) < 2) return '';
        $length = $this->decodeValue($data);
        if (strlen($data) < 2 + $length) return '';
        return substr($data, 2, $length);
    }

    protected function mqttGetHeader($data)
    {
        $byte = ord($data[0]);

        $header['type'] = ($byte & 0xF0) >> 4;
        $header['dup'] = ($byte & 0x08) >> 3;
        $header['qos'] = ($byte & 0x06) >> 1;
        $header['retain'] = $byte & 0x01;
        $header["flags"] = ($byte & 0x0F);
        return $header;
    }

    protected function getType($data)
    {
        return $this->mqttGetHeader($data)['type'];
    }

    protected  function eventConnect($header, $data)
    {
        $connect_info['protocol_name'] = $this->decodeString($data);
        $offset = strlen($connect_info['protocol_name']) + 2;

        $connect_info['version'] = ord(substr($data, $offset, 1));
        $offset += 1;

        $byte = ord($data[$offset]);
        $connect_info['willRetain'] = ($byte & 0x20 == 0x20);
        $connect_info['willQos'] = ($byte & 0x18 >> 3);
        $connect_info['willFlag'] = ($byte & 0x04 == 0x04);
        $connect_info['cleanStart'] = ($byte & 0x02 == 0x02);
        $offset += 1;

        $connect_info['keepalive'] = $this->decodeValue(substr($data, $offset, 2));
        $offset += 2;
        $connect_info['clientId'] = $this->decodeString(substr($data, $offset));
        return $connect_info;
    }


    protected  function setupServer(string $host = '127.0.0.1', int $port = 1883): void
    {
        $this->server = new Server($host, $port);
        $this->setupMqttListener($host, $port);
    }

    protected function setupMqttListener(string $host, int $port): void
    {
        $this->server->set(["open_mqtt_protocol" => true, "worker_num" => 1]);
        $this->server->on('Receive', fn(...$param) => $this->handleMqttReceive(...$param));
    }

    public function booting($callback){
        $this->bootCallback = $callback;
        return $this;
    }

    private function handleMqttReceive($server, $fd, $rid, $data): void
    {
        $header = $this->mqttGetHeader($data);
        $type =  $this->getType($data);

        if ($type === Mqtt::CONNECT) {
            $this->eventConnect($header, $data);
            Provider::clientFrom('mqtt', $fd)->addTopics([]);
            $server->send($fd, $this->connectData);
        }

        if ($type === Mqtt::PUBLISH) {
            // $offset = 2;
            // $topic = $this->decodeString(substr($data, $offset));
            // $offset += strlen($topic) + 2;
            // $msg = substr($data, $offset);
            $this->publish($server, $data, $fd);
        }
        if ($type === Mqtt::SUBSCRIBE) {
            $offset = 2;
            $msgId = $this->decodeValue(substr($data, $offset));
            $offset += 2;
            $topic = $this->decodeString(substr($data, $offset));
            $offset += strlen($topic) + 2;
            $qos = ord($data[$offset]);
            Provider::clientFrom('mqtt', $fd)->addTopic($topic);
            $suback = chr(0x90) . chr(0x03) . chr($msgId >> 8) . chr($msgId & 0xFF) . chr($qos);
            $server->send($fd, $suback);
        }
    }

    function start(): void
    {
        $this->server->start();
    }


    protected function publish($server, $data, $fd)
    {
        $offset = 2;
        $topic = $this->decodeString(substr($data, $offset));
        $offset += strlen($topic) + 2;
        $msg = substr($data, $offset);

        $body = chr(strlen($topic) >> 8) . chr(strlen($topic) & 0xFF) . $topic . $msg;
        $pkt = chr(0x30) . chr(strlen($body)) . $body;
        $subscribers = Provider::getMqttClients();
        foreach ($subscribers  as $sub) {
            if ($sub->getFd() !== $fd) {
                $server->send($sub->getFd(), $pkt);
            }
        }
    }

    public function listen($mqttHost, $mqttPort, $wsHost = null, $wsPort = null)
    {
        ($this->bootCallback)();
        $this->setupServer($mqttHost, $mqttPort);
        $this->server->start();
    }

    static function overWs()
    {
        return new class extends Mqtt {
            use OverWs;
        };
    }
};
