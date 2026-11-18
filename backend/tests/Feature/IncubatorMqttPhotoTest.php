<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncubatorMqttPhotoTest extends TestCase
{
    public function test_out_of_order_detection_stays_with_its_photo(): void
    {
        config(['services.device.key' => 'mqtt-photo-test-device', 'services.incubator.api_url' => 'http://yolo/detect']);
        Storage::fake('public');
        Process::fake();
        $jpeg = "\xFF\xD8".str_repeat('A', 2000);
        $server = ['HTTP_X_DEVICE_KEY' => 'mqtt-photo-test-device', 'CONTENT_TYPE' => 'image/jpeg'];
        $first = true;
        Http::fake(function () use (&$first, $jpeg, $server) {
            if ($first) {
                $first = false;
                $this->call('POST', '/api/incubator/upload', [], [], [], $server, $jpeg)->assertOk();

                return Http::response(['status' => 'success', 'engine' => 'yolo', 'total_eggs' => 1, 'detections' => [['class' => 'retak']]]);
            }

            return Http::response(['status' => 'success', 'engine' => 'yolo', 'total_eggs' => 2, 'detections' => [['class' => 'utuh']]]);
        });

        $old = $this->call('POST', '/api/incubator/upload', [], [], [], $server, $jpeg)->assertOk();
        $latest = $this->getJson('/incubator/latest')->assertOk()->assertJsonPath('total_eggs', 2)->assertJsonPath('control.motorPaused', true);
        $this->assertNotSame($old->json('detection.photo_id'), $latest->json('detection.photo_id'));
        $latest->assertJsonPath('history.1.detection.photo_id', $old->json('detection.photo_id'));
    }

    public function test_crack_pauses_through_retained_settings_and_resume_is_explicit(): void
    {
        config(['services.device.key' => 'mqtt-photo-test-device', 'services.incubator.api_url' => 'http://yolo/detect']);
        Storage::fake('public');
        Process::fake();
        Http::fake(['*' => Http::response(['status' => 'success', 'engine' => 'yolo', 'total_eggs' => 1, 'detections' => [['class' => 'retak']]])]);
        $jpeg = "\xFF\xD8".str_repeat('A', 2000);
        $upload = $this->call('POST', '/api/incubator/upload', [], [], [], ['HTTP_X_DEVICE_KEY' => 'mqtt-photo-test-device', 'CONTENT_TYPE' => 'image/jpeg'], $jpeg);
        $upload->assertOk()->assertJsonPath('detection.status', 'success')->assertJsonPath('control.motorPaused', true);
        $this->getJson('/incubator/latest')->assertJsonPath('detection.photo_id', $upload->json('detection.photo_id'))->assertJsonPath('motorPaused', null);
        Process::assertRan(fn ($process) => in_array('incubator/mqtt-photo-test-device/settings', $process->command, true) && in_array('-r', $process->command, true) && json_decode($process->input, true)['motorPaused'] === true);
        $this->postJson('/incubator/control', ['lampMode' => 'off'])->assertOk()->assertJsonPath('control.motorPaused', true);
        $this->postJson('/incubator/control', ['motorPaused' => false])->assertOk()->assertJsonPath('control.motorPaused', false);
        Process::assertRan(fn ($process) => in_array('incubator/mqtt-photo-test-device/settings', $process->command, true) && json_decode($process->input, true)['motorPaused'] === false);
        Process::assertNotRan(fn ($process) => in_array('incubator/mqtt-photo-test-device/control', $process->command, true) && array_key_exists('motorPaused', json_decode($process->input, true)));
    }

    public function test_failed_detection_and_failed_pause_are_reported_honestly(): void
    {
        config(['services.device.key' => 'mqtt-photo-test-device', 'services.incubator.api_url' => 'http://yolo/detect']);
        Storage::fake('public');
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Http::fakeSequence()->push(['status' => 'success', 'engine' => 'fallback', 'total_eggs' => 0, 'detections' => []])->push(['status' => 'success', 'engine' => 'yolo', 'total_eggs' => 1, 'detections' => [['class' => 'retak']]]);
        $jpeg = "\xFF\xD8".str_repeat('A', 2000);
        $server = ['HTTP_X_DEVICE_KEY' => 'mqtt-photo-test-device', 'CONTENT_TYPE' => 'image/jpeg'];
        $this->call('POST', '/api/incubator/upload', [], [], [], $server, $jpeg)->assertOk()->assertJsonPath('detection.status', 'failed')->assertJsonPath('detection.total_eggs', null);
        $this->call('POST', '/api/incubator/upload', [], [], [], $server, $jpeg)->assertOk()->assertJsonPath('detection.motor_pause', 'publish_failed')->assertJsonPath('control.motorPaused', true);
    }

    public function test_manual_detection_does_not_change_control_or_publish(): void
    {
        config(['services.incubator.api_url' => 'http://yolo/detect']);
        Cache::forever('incubator:control', ['motorPaused' => false]);
        Process::fake();
        Http::fake(['*' => Http::response(['status' => 'success', 'engine' => 'yolo', 'total_eggs' => 1, 'detections' => [['class' => 'retak']], 'annotated_image' => 'image'])]);
        $this->postJson('/incubator/detect', ['image' => UploadedFile::fake()->image('egg.jpg')])->assertOk();
        $this->assertFalse(Cache::get('incubator:control')['motorPaused']);
        Process::assertNothingRan();
    }

    public function test_telemetry_reports_observed_pause_separately_from_desired_control(): void
    {
        config(['services.device.key' => 'mqtt-photo-test-device']);
        $this->withHeader('X-Device-Key', 'mqtt-photo-test-device')->postJson('/api/incubator/telemetry', ['motorPaused' => true])->assertOk();
        $this->getJson('/incubator/latest')->assertJsonPath('motorPaused', true)->assertJsonPath('control.motorPaused', false);
    }

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
