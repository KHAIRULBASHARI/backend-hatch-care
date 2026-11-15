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

    def test_red_led_and_relay_wiring_are_preserved(self):
        for sketch in SKETCHES:
            with self.subTest(sketch=sketch.name):
                source = sketch.read_text(encoding='utf-8')
                self.assertRegex(source, r'#define PIN_RELAY_HEATER\s+4\b')
                self.assertRegex(source, r'#define PIN_RELAY_MOTOR\s+2\b')
                setup = function_body(source, 'setup')
                self.assertIn('digitalWrite(33, HIGH)', setup)
                self.assertIn('digitalWrite(PIN_RELAY_HEATER, RELAY_OFF)', setup)

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

    def test_duplicate_sketches_remain_identical(self):
        self.assertEqual(SKETCHES[0].read_bytes(), SKETCHES[1].read_bytes())


if __name__ == '__main__':
    unittest.main()
