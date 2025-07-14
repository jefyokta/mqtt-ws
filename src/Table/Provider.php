<?php

namespace Jefyokta\Mqttbroker\Table;

use Jefyokta\Mqttbroker\Exception\InvalidProtocol;
use Swoole\Table;

class Provider
{
    private static ?Table $table = null;
    private string $protocol;

    public static function create($topicSize = 512): void
    {
        $table = new Table(1024);
        $table->column("fd_type", Table::TYPE_STRING, 8);
        $table->column("topics", Table::TYPE_STRING, $topicSize);
        $table->create();
        self::$table = $table;
    }

    /**
     * @param "ws"|"mqtt" $protocol
     */
    public static function from(string $protocol): self
    {

        self::ensureValidProtocol($protocol);
        $instance = new static();
        $instance->protocol = $protocol;
        return $instance;
    }

    public static function ensureValidProtocol(string $protocol)
    {
        if (!in_array($protocol, ["ws", "mqtt"])) {
            throw new InvalidProtocol("Invalid protocol `$protocol`! Allowed: [ws, mqtt]");
        }
    }
    /**
     * @param "ws"|"mqtt" $protocol
     * @param int $fd
     * 
     * @return Client
     */
    public static function clientFrom(string $protocol, int $fd): Client
    {
        self::ensureValidProtocol($protocol);
        $key = "$protocol:$fd";
        return new Client($key);
    }

    public function getAsClient($fd): Client
    {
        return new Client($this->key($fd));
    }


    public function get(int $fd): ?array
    {
        $key = $this->key($fd);
        return self::$table?->get($key);
    }

    public function set(int $fd, array $data): void
    {
        $key = $this->key($fd);
        self::$table?->set($key, $data);
    }

    public function delete(int $fd): void
    {
        $key = $this->key($fd);
        self::$table?->del($key);
    }

    public function key(int $fd): string
    {
        self::ensureValidProtocol($this->protocol);
        return "{$this->protocol}:$fd";
    }

    public static function raw(): ?Table
    {
        return self::$table;
    }

    /**
     * 
     * @param bool $lazy
     * 
     * return Client[] once lazy is false, \Generator once lazy is true
     * @return Client[] | Generator<int,Client>
     */
    public static function getWsClients($lazy =  false)
    {

        return $lazy ? static::clientsFromProtocolLazily('ws') : static::clientsFromProtocol('ws');;
    }


    /**
     * 
     * @param bool $lazy
     * 
     * return Client[] once lazy is false, \Generator once lazy is true
     * @return Client[] | Generator<int,Client>
     */
    public static function getMqttClients($lazy = false)
    {
        return $lazy ? static::clientsFromProtocolLazily('mqtt') : static::clientsFromProtocol('mqtt');
    }

    private static function clientsFromProtocolLazily(string $protocol)
    {
        foreach (self::$table as $key => $value) {
            [$protocol, $fd] = explode(":", $key);
            if ($protocol == $protocol) {
                yield new Client($key);
            }
        }
    }

    private static function clientsFromProtocol(string $protocol)
    {
        $clients = [];
        // var_dump(self::$table->count());
        foreach (self::$table as $key => $value) {
            [$protocol, $fd] = explode(":", $key);
            if ($protocol == $protocol) {
                $clients[] = new Client($key);
            }
        }

        return $clients;
    }
}
