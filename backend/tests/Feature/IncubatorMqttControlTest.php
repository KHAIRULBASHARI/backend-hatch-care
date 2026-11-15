<?php

namespace Tests\Feature;

use App\Services\IncubatorMqtt;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class IncubatorMqttControlTest extends TestCase
{
    public function test_publisher_uses_qos_one_without_retaining_manual_commands(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Process::fake();
        Process::preventStrayProcesses();

        app(IncubatorMqtt::class)->publish(['lampMode' => 'auto']);

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command === [
                'mosquitto_pub', '-h', '127.0.0.1', '-p', '1883',
                '-q', '1', '-t', 'incubator/mqtt-test-device/control', '-s',
            ] && json_decode($process->input, true) === ['lampMode' => 'auto'];
        });
    }

    public function test_retained_settings_never_contain_manual_motor_or_capture_triggers(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Process::fake();
        Process::preventStrayProcesses();

        app(IncubatorMqtt::class)->publish(['lampMode' => 'auto'], [
            'lampMode' => 'auto', 'tempMin' => 37.0, 'tempMax' => 38.0,
            'motorIntervalHours' => 4, 'motorDurationSeconds' => 15,
            'captureIntervalSeconds' => 120, 'motorManualRequest' => true,
            'motorCommandId' => 'old-command', 'triggerCapture' => true,
        ]);

        Process::assertRan(function (PendingProcess $process): bool {
            $data = json_decode($process->input, true);

            return in_array('incubator/mqtt-test-device/settings', $process->command, true)
                && in_array('-r', $process->command, true)
                && $data === [
                    'lampMode' => 'auto', 'tempMin' => 37, 'tempMax' => 38,
                    'motorIntervalHours' => 4, 'motorDurationSeconds' => 15,
                    'captureIntervalSeconds' => 120,
                ];
        });
    }

    public function test_control_publishes_before_saving_and_reports_mqtt_transport(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Process::fake();
        Process::preventStrayProcesses();

        $response = $this->postJson('/incubator/control', ['lampMode' => 'off']);

        $response->assertOk()->assertJsonPath('transport', 'mqtt');
        $this->assertSame('off', Cache::get('incubator:control')['lampMode']);
        Process::assertRan(fn (PendingProcess $process): bool => json_decode($process->input, true) === ['lampMode' => 'off']);
    }

    public function test_control_saves_safe_settings_for_reconnect_without_retaining_triggers(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Process::fake();
        Process::preventStrayProcesses();

        $response = $this->postJson('/incubator/control', ['triggerMotor' => true]);

        $response->assertOk();
        $this->assertIsString($response->json('control.motorCommandId'));
        Process::assertRan(function (PendingProcess $process): bool {
            $data = json_decode($process->input, true);

            return in_array('incubator/mqtt-test-device/settings', $process->command, true)
                && ! array_key_exists('motorManualRequest', $data)
                && ! array_key_exists('motorCommandId', $data)
                && ! array_key_exists('triggerCapture', $data);
        });
    }

    public function test_invalid_setpoints_do_not_publish_or_modify_saved_settings(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Cache::forever('incubator:control', ['tempMin' => 37.0, 'tempMax' => 38.0]);
        Process::fake();
        Process::preventStrayProcesses();

        $response = $this->postJson('/incubator/control', ['tempMin' => 39]);

        $response->assertUnprocessable()->assertJsonValidationErrors('tempMax');
        $this->assertSame(['tempMin' => 37.0, 'tempMax' => 38.0], Cache::get('incubator:control'));
        Process::assertNothingRan();
    }

    public function test_photo_interval_rejects_values_below_firmware_minimum(): void
    {
        Process::fake();
        Process::preventStrayProcesses();

        $response = $this->postJson('/incubator/control', ['captureIntervalSeconds' => 14]);

        $response->assertUnprocessable()->assertJsonValidationErrors('captureIntervalSeconds');
        Process::assertNothingRan();
    }

    public function test_settings_failure_cannot_send_a_manual_trigger_first(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Process::preventStrayProcesses();

        $this->postJson('/incubator/control', ['triggerMotor' => true])->assertServiceUnavailable();

        Process::assertNotRan(fn (PendingProcess $process): bool => in_array('incubator/mqtt-test-device/control', $process->command, true));
    }

    public function test_broker_failure_does_not_save_a_command_or_report_success(): void
    {
        config(['services.device.key' => 'mqtt-test-device']);
        Cache::forever('incubator:control', ['lampMode' => 'auto']);
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Process::preventStrayProcesses();

        $response = $this->postJson('/incubator/control', ['lampMode' => 'off']);

        $response->assertServiceUnavailable()->assertJsonPath('transport', 'mqtt');
        $this->assertSame(['lampMode' => 'auto'], Cache::get('incubator:control'));
        Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'mosquitto_pub');
    }
}
