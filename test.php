<?php
require_once __DIR__ . "/vendor/autoload.php";

use Jefyokta\Mqttbroker\Mqtt;
use Jefyokta\Mqttbroker\Table\Provider;

Mqtt::overWs()
    ->requestable(
        function ($req, $res) {
            $res->end(file_get_contents(__DIR__ . "/browser.html"));
        }
    )->booting(function () {
        Provider::create();
    })
    ->listen('127.0.0.1', 1883, '127.0.0.1', 8000);
