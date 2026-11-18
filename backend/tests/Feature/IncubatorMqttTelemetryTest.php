<?php

namespace Tests\Feature;

use App\Console\Commands\SubscribeIncubatorTelemetry;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IncubatorMqttTelemetryTest extends TestCase
{
    public function test_firmware_payload_updates_cache_and_null_clears_old_sensor_values(): void
    {
        $command = app(SubscribeIncubatorTelemetry::class);
        $command->cacheMessage(json_encode(['payload' => json_encode(['temperature' => 37.56, 'humidity' => 61, 'heater' => true, 'motor' => false, 'rssi' => -57])]));

        $this->assertSame(37.6, Cache::get('incubator:temperature'));
        $this->assertEquals(61, Cache::get('incubator:humidity'));
        $this->assertTrue(Cache::get('incubator:lamp'));
        $this->assertFalse(Cache::get('incubator:motor'));
        $this->assertSame(-57, Cache::get('incubator:rssi'));
        $this->assertSame(now()->timestamp, Cache::get('incubator:last_seen'));

        $command->cacheMessage(json_encode(['payload' => '{"temp":null,"hum":null}']));
        $this->assertNull(Cache::get('incubator:temperature'));
        $this->assertNull(Cache::get('incubator:humidity'));
    }

    public function test_invalid_messages_do_not_change_cache(): void
    {
        Cache::put('incubator:temperature', 38, 3600);
        $command = app(SubscribeIncubatorTelemetry::class);
        $command->cacheMessage('invalid');
        $command->cacheMessage('{"payload":"not an object"}');
        $this->assertSame(38, Cache::get('incubator:temperature'));
        $this->assertNull(Cache::get('incubator:last_seen'));
    }
}
