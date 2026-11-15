<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncubatorMqttPhotoTest extends TestCase
{
    public function test_http_photo_upload_still_saves_and_serves_jpeg_without_publishing_commands(): void
    {
        config(['services.device.key' => 'mqtt-photo-test-device']);
        Storage::fake('public');
        Process::fake();
        Process::preventStrayProcesses();
        Http::preventStrayRequests();
        Cache::forever('incubator:control', [
            'motorManualRequest' => true, 'motorCommandId' => 'already-sent-command',
        ]);
        $jpeg = "\xFF\xD8".str_repeat('A', 2000)."\xFF\xD9";

        $response = $this->call('POST', '/api/incubator/upload', [], [], [], [
            'HTTP_X_DEVICE_KEY' => 'mqtt-photo-test-device',
            'CONTENT_TYPE' => 'image/jpeg',
        ], $jpeg);

        $response->assertOk()->assertJsonPath('status', 'success');
        $url = $response->json('url');
        $this->assertStringStartsWith('/incubator/photo/', $url);
        $this->get($url)->assertOk()->assertContent($jpeg)->assertHeader('Content-Type', 'image/jpeg');
        $this->getJson('/incubator/latest')->assertOk()->assertJsonCount(1, 'history');
        $this->assertNull(Cache::get('incubator:temperature'));
        $this->assertNull(Cache::get('incubator:humidity'));
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_mqtt_photo_trigger_has_one_shot_id_and_is_not_retained(): void
    {
        config(['services.device.key' => 'mqtt-photo-test-device']);
        Process::fake();
        Process::preventStrayProcesses();

        $response = $this->postJson('/incubator/control', ['triggerCapture' => true]);

        $response->assertOk()->assertJsonPath('transport', 'mqtt');
        Process::assertRan(function ($process): bool {
            $data = json_decode($process->input, true);

            return in_array('incubator/mqtt-photo-test-device/control', $process->command, true)
                && ! in_array('-r', $process->command, true)
                && $data['triggerCapture'] === true
                && is_string($data['captureCommandId']);
        });
    }
}
