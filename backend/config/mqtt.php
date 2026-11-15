<?php

return [
    'host' => env('MQTT_HOST', '127.0.0.1'),
    'port' => (int) env('MQTT_PORT', 1883),
    'websocket_url' => env('MQTT_WEBSOCKET_URL', 'wss://mqtt.walker880.my.id:443/mqtt'),
    'incubator_telemetry_topic' => env('MQTT_INCUBATOR_TELEMETRY_TOPIC', 'incubator/telemetry'),
    'incubator_status_topic' => env('MQTT_INCUBATOR_STATUS_TOPIC', 'incubator/status'),
];
