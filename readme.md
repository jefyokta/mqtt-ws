

# A  MQTT Broker in PHP (with Swoole Support)

## Example

###  Basic TCP MQTT Broker
for example we have `mqtt.php` file
```php
use Jefyokta\Mqttbroker\Mqtt;
use Jefyokta\Mqttbroker\Table\Provider;

$mqtt =  new Mqtt;
$mqtt->booting(function(){
    Provider::create();

})->listen('127.0.0.1',1883);
```
then run
```bash
php mqtt.php
```

subscribe and publish with mosquitto client

```bash
mosquitto_sub -p 1883 -t mytopic
```
```bash
mosquitto_pub -p 1883 -t mytopic -m hellow
```

### Over Websocket Mode

Browsers cannot connect directly to raw TCP protocols. Therefore, MQTT over WebSocket is used to support browser-based clients.

```php
use Jefyokta\Mqttbroker\Mqtt;
use Jefyokta\Mqttbroker\Table\Provider;

Mqtt::overWs()
    ->booting(function () {
        //required to save clients information
        Provider::create();
    })

    //tcp host, tcp port, ws host, ws port
    ->listen('127.0.0.1', 1883, '127.0.0.1', 8000);

```

or manually 
```php
use Jefyokta\Mqttbroker\Mqtt;
use Jefyokta\Mqttbroker\Table\Provider;
use Jefyokta\Mqttbroker\Trait\OverWs;

$mqtt = new  class extends Mqtt {
    use OverWs
};

$mqtt->booting(function () {
        Provider::create();
    })
    ->listen('127.0.0.1', 1883, '127.0.0.1', 8000);

```

with over ws mode you also can open http protocol with `requestable` method

```php

use Jefyokta\Mqttbroker\Mqtt;
use Jefyokta\Mqttbroker\Table\Provider;

Mqtt::overWs()
    // swoole websocket request event
    ->requestable(
        function ($req, $res) {
            //send http response 
            //or you also can do routing or something else like running laravel app here
            $res->end(file_get_contents(__DIR__ . "/index.html"));
        }
    )->booting(function () {
        //required to save clients information
        Provider::create();
    })
    ->listen('127.0.0.1', 1883, '127.0.0.1', 8000);

```

## Provider Class
in examples above, we always call `Provider::create()` in the booting method, booting method is actually is not required for run the broker to saving clients information, You must call `Provider::create()` before starting the application ( before calling `listen()`).
