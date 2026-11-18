<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class IncubatorMqtt
{
    public function publish(array $payload, array $settings = []): void
    {
        $deviceKey = config('services.device.key');
        if (! is_string($deviceKey) || $deviceKey === '') {
            throw new RuntimeException('Identitas perangkat MQTT belum dikonfigurasi.');
        }

        $persistent = array_intersect_key($settings, array_flip([
            'lampMode', 'tempMin', 'tempMax', 'motorIntervalHours',
            'motorDurationSeconds', 'captureIntervalSeconds', 'motorPaused',
        ]));
        if ($persistent !== []) {
            $saved = Process::timeout(5)
                ->input(json_encode($persistent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
                ->run([
                    'mosquitto_pub', '-h', config('mqtt.host', '127.0.0.1'),
                    '-p', (string) config('mqtt.port', 1883), '-q', '1',
                    '-t', 'incubator/'.$deviceKey.'/settings', '-r', '-s',
                ]);
            if (! $saved->successful()) {
                throw new RuntimeException('Pengaturan MQTT belum tersimpan di broker.');
            }
        }

        unset($payload['motorPaused']);
        if ($payload === []) {
            return;
        }

        $result = Process::timeout(5)
            ->input(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->run([
                'mosquitto_pub', '-h', config('mqtt.host', '127.0.0.1'),
                '-p', (string) config('mqtt.port', 1883), '-q', '1',
                '-t', 'incubator/'.$deviceKey.'/control', '-s',
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('Broker MQTT tidak menerima perintah.');
        }
    }
}
