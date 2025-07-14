<?php

namespace Jefyokta\Mqttbroker\Trait;

use Error;
use Jefyokta\Mqttbroker\Mqtt;
use Jefyokta\Mqttbroker\Table\Client;
use Jefyokta\Mqttbroker\Table\Provider;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server\Port;
use Swoole\WebSocket\Server;

trait OverWs
{
    /** @var Server */
    protected $ws;
    /**
     * @var \Swoole\Server\Port
     */
    protected  $server;

    protected $buffer;

    private $request_event;
    protected function setupWebSocketHandlers(): void
    {
        $this->ws->set([
            'open_websocket_protocol' => true,
            'websocket_compression' => false,
        ]);
        $this->ws->on('open', function (Server $serv, $req) {
            $connack = chr(0x20) . chr(0x02) . chr(0x00) . chr(0x00);
            $serv->push($req->fd, $connack, WEBSOCKET_OPCODE_BINARY);
        });
        $this->ws->on("handshake", fn(Request $req, Response $res) => $this->handleHandshake($req, $res));
        $this->ws->on("message", fn($server, $frame) => $this->handleWebSocketMessage($server, $frame));
        if ($this->request_event) {
            $this->ws->on("request", $this->request_event);
        }
        $this->ws->on('close', function ($serv, $fd) {
            echo "Client $fd disconnected\n";
            $protocol =  $this->isFromWs($serv, $fd) ? "ws" : "mqtt";
            Provider::from($protocol)->delete($fd);
        });
    }

    /**
     * Set request event callback to enable http protocol
     *
     * @param callable(\Swoole\Http\Request, \Swoole\Http\Response) $callback
     *  
     * @return $this
     */

    public function requestable($callback)
    {
        $this->request_event =  $callback;
        return $this;
    }

    protected  function setupServer(string $host = '127.0.0.1', int $port = 1883): void
    {

        $this->server = $this->ws->listen($host, $port, SWOOLE_BASE);
        $this->server->set([
            "open_mqtt_protocol" => true
        ]);
        $this->setupMqttListener($host, $port);
    }
    private function createWsServer($host, $port)
    {
        $this->ws = new Server($host, $port);
        $this->setupWebSocketHandlers();
    }

    protected function handleHandshake(Request $request, Response $response): bool
    {
        $secWebSocketKey = $request->header['sec-websocket-key'] ?? null;
        $secWebSocketProtocol = $request->header['sec-websocket-protocol'] ?? null;

        if (!$secWebSocketKey) {
            $response->end();
            return false;
        }

        $key = base64_encode(pack(
            'H*',
            sha1($secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
        ));

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
        ];

        if ($secWebSocketProtocol && in_array($secWebSocketProtocol, ['mqtt', 'mqttv3.1', 'mqttv3.1.1'])) {
            $headers['Sec-WebSocket-Protocol'] = $secWebSocketProtocol;
        }

        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }

        $response->status(101);
        $response->end();


        return true;
    }
    function _decodeValue($data)
    {
        return (ord($data[0]) << 8) + ord($data[1]);
    }

    function _decodeString($data)
    {
        $len = $this->_decodeValue($data);
        return substr($data, 2, $len);
    }


    protected function handleWebSocketMessage($server, $frame): void
    {
        $fd = $frame->fd;

        $this->buffer[$fd] = ($this->buffer[$fd] ?? '') . $frame->data;

        while (true) {
            if (strlen($this->buffer[$fd]) < 2) {
                return;
            }

            $parsed = $this->parseHeader($this->buffer[$fd]);
            if (!$parsed) return;

            list($headerLen, $bodyLen) = $parsed;
            $totalLen = $headerLen + $bodyLen;

            if (strlen($this->buffer[$fd]) < $totalLen) {
                return;
            }

            $packet = substr($this->buffer[$fd], 0, $totalLen);
            $this->buffer[$fd] = substr($this->buffer[$fd], $totalLen);

            $type = $this->getType($packet);

            if ($type === Mqtt::CONNECT) {
                $this->connect($server, $fd);
            } elseif ($type === Mqtt::PUBLISH) {
                $this->publish($server, $packet, $fd);
            } elseif ($type === Mqtt::SUBSCRIBE) {
                $this->subscribe($server, $packet, $fd);
            }
        }
    }



    protected function publish($server, $data, $fd)
    {
        $offset = 2;
        $topic = $this->decodeString(substr($data, $offset));
        $offset += strlen($topic) + 2;
        $msg = substr($data, $offset);
        $body = chr(strlen($topic) >> 8) . chr(strlen($topic) & 0xFF) . $topic . $msg;
        $pkt = chr(0x30) . chr(strlen($body)) . $body;

        //sending to ws subs
        $wsSubscriber =    Provider::getWsClients();
        $subscriber =  Provider::getMqttClients();

        foreach ($server->connections as $fd) {
            $cli =  new Client("ws:" . $fd);
            if ($cli->inTopic($topic)) {
                $server->push($fd, $pkt, WEBSOCKET_OPCODE_BINARY);
            }
        }


        foreach ($subscriber as $cli) {
            if ($cli->inTopic($topic) && $cli->getFd() !== $fd) {
                $server->send($cli->getFd(), $pkt);
            }
        }
    }
    protected function subscribe($server, $data, $fd)
    {
        $offset = 2;
        $msgId = $this->_decodeValue(substr($data, $offset));
        $offset += 2;
        $topic = $this->_decodeString(substr($data, $offset));
        $offset += strlen($topic) + 2;
        $qos = ord($data[$offset]);

        echo "WS $fd SUBSCRIBED  to [$topic]\n";

        $suback = chr(0x90) . chr(0x03) . chr($msgId >> 8) . chr($msgId & 0xFF) . chr($qos);
        $server->push($fd, $suback, WEBSOCKET_OPCODE_BINARY);
        Provider::clientFrom('ws', $fd)->addTopic($topic);
    }


    protected function connect(Server $server, $fd)
    {
        Provider::clientFrom('ws', $fd)->addTopics([]);
        $server->push($fd, $this->connectData, WEBSOCKET_OPCODE_BINARY);
    }

    public function listen($mqttHost, $mqttPort, $wsHost = null, $wsPort = null)
    {
        if (is_null($wsHost) || is_null($wsPort)) {
            throw new Error("Websocket Host and Websocket Port cannot be null while using over ws!");
        }
        ($this->bootCallback)();
        $this->createWsServer($wsHost, $wsPort);
        $this->setupServer($mqttHost, $mqttPort);
        $this->ws->start();
    }

    private function isFromWs($server, $fd)
    {
        return $server->connection_info($fd)["server_port"] === $server->ports[0]->port;
    }

    function _eventConnect($data)
    {
        $info = [];
        $info['protocol'] = $this->_decodeString($data);
        $offset = strlen($info['protocol']) + 2;

        $info['version'] = ord($data[$offset]);
        $offset++;

        $flags = ord($data[$offset]);
        $info['clean'] = ($flags & 0x02) !== 0;
        $offset++;

        $info['keepalive'] =  $this->_decodeValue(substr($data, $offset, 2));
        $offset += 2;

        $info['clientId'] =  $this->_decodeString(substr($data, $offset));
        return $info;
    }

    protected function parseHeader(string $data): ?array
    {
        $offset = 1;
        $multiplier = 1;
        $value = 0;

        do {
            if (!isset($data[$offset])) return null;
            $digit = ord($data[$offset]);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $offset++;
        } while ($digit & 128);

        return [$offset, $value];
    }
}
