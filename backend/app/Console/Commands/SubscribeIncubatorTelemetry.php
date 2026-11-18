<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

class SubscribeIncubatorTelemetry extends Command
{
    protected $signature = 'incubator:subscribe-telemetry';

    protected $description = 'Subscribe to incubator MQTT telemetry and update the cache';

    public function handle(): int
    {
        $key = config('services.device.key');
        if (! is_string($key) || $key === '' || strpbrk($key, '/+#') !== false) {
            $this->error('DEVICE_KEY harus berupa satu level topic MQTT yang valid.');

            return self::FAILURE;
        }

        $process = new Process([
            'mosquitto_sub', '-h', config('mqtt.host'), '-p', (string) config('mqtt.port'),
            '-q', '1', '-R', '-t', 'incubator/'.$key.'/telemetry', '-F', '%j',
        ], timeout: null);
        $buffer = '';

        try {
            $process->disableOutput();
            $process->run(function (string $type, string $chunk) use (&$buffer): void {
                if ($type !== Process::OUT) {
                    $this->error(trim($chunk));

                    return;
                }
                $buffer .= $chunk;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $this->cacheMessage(substr($buffer, 0, $end));
                    $buffer = substr($buffer, $end + 1);
                }
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->error('mosquitto_sub berhenti (kode '.$process->getExitCode().').');

        return self::FAILURE;
    }

    public function cacheMessage(string $message): void
    {
        $envelope = json_decode($message, true);
        $payload = isset($envelope['payload']) && is_string($envelope['payload'])
            ? json_decode($envelope['payload'], true) : null;
        if (! is_array($payload) || array_is_list($payload)) {
            return;
        }

        foreach (['temperature' => 'temp', 'humidity' => 'hum'] as $field => $alias) {
            $key = array_key_exists($field, $payload) ? $field : $alias;
            if (array_key_exists($key, $payload)) {
                if ($payload[$key] === null) {
                    Cache::forget('incubator:'.$field);
                } elseif (is_int($payload[$key]) || is_float($payload[$key])) {
                    Cache::put('incubator:'.$field, round($payload[$key], 1), 3600);
                }
            }
        }
        foreach (['lamp' => 'heater', 'motor' => 'motor'] as $field => $alias) {
            $value = $payload[$alias] ?? $payload[$field] ?? null;
            if (is_bool($value)) {
                Cache::put('incubator:'.$field, $value, 3600);
            }
        }
        if (isset($payload['motorPaused']) && is_bool($payload['motorPaused'])) {
            Cache::put('incubator:motorPaused', $payload['motorPaused'], 3600);
        }
        if (isset($payload['rssi']) && is_int($payload['rssi'])) {
            Cache::put('incubator:rssi', $payload['rssi'], 3600);
        }
        Cache::put('incubator:last_seen', now()->timestamp, 3600);
    }
}
