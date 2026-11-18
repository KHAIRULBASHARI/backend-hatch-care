from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
SKETCHES = [ROOT / 'firmware/incubator/incubator.ino', ROOT / 'esp32_incubator/esp32_incubator.ino']


def function_body(source, name):
    start = re.search(r'\b' + re.escape(name) + r'\([^)]*\)\s*\{', source)
    if start is None:
        raise AssertionError(f'Missing function {name}')
    position = start.end()
    depth = 1
    for index in range(position, len(source)):
        if source[index] == '{':
            depth += 1
        elif source[index] == '}':
            depth -= 1
            if depth == 0:
                return source[position:index]
    raise AssertionError(f'Unterminated function {name}')


class IncubatorMqttTests(unittest.TestCase):
    def test_http_upload_cannot_transport_controls_or_live_telemetry(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                upload = function_body(source, 'cloudUpload')
                self.assertIn('POST /api/incubator/upload', upload)
                self.assertIn('image/jpeg', upload)
                for token in ['X-Temp', 'X-Humidity', 'X-Lamp', 'X-Motor', 'X-RSSI', 'applyCloudCommands']:
                    self.assertNotIn(token, upload)
                self.assertNotIn('cloudPoll(', source)
                self.assertNotIn('/api/incubator/device-control', source)

    def test_mqtt_settings_and_lwt_match_dashboard_topics(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                self.assertIn('MQTT_TOPIC_SETTINGS', source)
                self.assertIn('esp_mqtt_client_subscribe(mqttClient, MQTT_TOPIC_SETTINGS, 1)', source)
                self.assertIn('mqtt_cfg.session.last_will.topic = MQTT_TOPIC_STATUS', source)
                self.assertIn('mqtt_cfg.broker.address.uri = MQTT_BROKER_URI', source)
                self.assertIn('event->retain', source)

    def test_live_telemetry_includes_confirmed_schedule_and_uses_no_retain(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                telemetry = function_body(source, 'publishTelemetry')
                for field in ['motorIntervalHours', 'motorDurationSeconds', 'captureIntervalSeconds', 'turnCountdownSec']:
                    self.assertIn(field, telemetry)
                self.assertRegex(telemetry, r'esp_mqtt_client_publish\(mqttClient, "incubator/telemetry", json, 0, [01], 0\)')

    def test_flash_stays_off_and_heater_uses_separate_gpio(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                self.assertRegex(source, r'#define PIN_RELAY_HEATER\s+12\b')
                self.assertRegex(source, r'#define PIN_FLASH_LED\s+4\b')
                self.assertRegex(source, r'#define PIN_RELAY_MOTOR\s+2\b')
                setup = function_body(source, 'setup')
                self.assertIn('digitalWrite(33, HIGH)', setup)
                self.assertIn('digitalWrite(PIN_FLASH_LED, LOW)', setup)
                self.assertNotIn('digitalWrite(PIN_FLASH_LED, HIGH)', source)
                self.assertLess(setup.index('gpio_set_level((gpio_num_t)PIN_RELAY_HEATER, RELAY_OFF)'), setup.index('pinMode(PIN_RELAY_HEATER, OUTPUT)'))

    def test_photo_uses_shared_heater_light_and_releases_it_on_all_capture_exits(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                capture = function_body(source, 'capturePhotoTo')
                self.assertIn('beginPhotoLight()', capture)
                self.assertEqual(capture.count('endPhotoLight()'), 2)
                self.assertLess(capture.index('beginPhotoLight()'), capture.index('camera_fb_t *old'))
                self.assertLess(capture.rindex('endPhotoLight()'), capture.rindex('xSemaphoreGive(cameraMutex)'))
                self.assertIn('photoLightReady()', capture)
                self.assertIn('photoLightRequested', function_body(source, 'applyHeaterPin'))
                self.assertIn('updateThermostat()', function_body(source, 'endPhotoLight'))

    def test_photo_light_has_settling_deadline_and_sensor_overheat_protection(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                heater = function_body(source, 'applyHeaterPin')
                self.assertIn('PHOTO_LIGHT_MAX_MS', heater)
                self.assertIn('SENSOR_STALE_MS', heater)
                self.assertIn('lastGoodSensorAt', heater)
                self.assertIn('tempValid && !overheat', heater)
                self.assertIn('!tempValid || overheat', heater)
                self.assertIn('PHOTO_LIGHT_SETTLE_MS', function_body(source, 'beginPhotoLight'))
                self.assertIn('refreshHeaterPin()', function_body(source, 'telemetryTask'))
                self.assertIn('lastGoodSensorAt = now', function_body(source, 'loop'))

    def test_telemetry_runs_separately_from_http_upload_and_local_web_pause(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                task = function_body(source, 'telemetryTask')
                self.assertIn('publishTelemetry()', task)
                self.assertNotIn('cloudUpload()', task)
                self.assertNotIn('localWebBusyUntil', task)
                self.assertNotIn('publishTelemetry()', function_body(source, 'cloudTask'))
                self.assertIn('xTaskCreatePinnedToCore(telemetryTask,', function_body(source, 'setup'))

    def test_photo_writes_have_a_bounded_overall_deadline(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                upload = function_body(source, 'cloudUpload')
                self.assertIn('UPLOAD_TIMEOUT_MS', upload)
                self.assertIn('client.connect("iot.walker880.my.id", 80, 4000)', upload)

    def test_motor_pause_is_restored_and_blocks_all_motor_requests(self):
        source = SKETCHES[0].read_text(encoding='utf-8')
        commands = function_body(source, 'applyCloudCommands')
        motor = function_body(source, 'updateMotor')
        self.assertIn('allowTriggers ? nullptr : jsonValue(json, "motorPaused")', commands)
        self.assertIn('prefs.putBool("motorPaused", value)', commands)
        self.assertIn('prefs.getBool("motorPaused", false)', function_body(source, 'setup'))
        self.assertIn('digitalWrite(PIN_RELAY_MOTOR, RELAY_OFF)', commands)
        self.assertIn('motorRequest = false', commands)
        self.assertIn('isNew && !motorPaused && !isMotorRunning', source)
        self.assertLess(motor.index('if (motorPaused)'), motor.index('digitalWrite(PIN_RELAY_MOTOR, RELAY_ON)'))
        self.assertIn('if (!paused) motorRequest = true', function_body(source, 'handleTurn'))
        self.assertIn('motorPaused', function_body(source, 'publishTelemetry'))


if __name__ == '__main__':
    unittest.main()
