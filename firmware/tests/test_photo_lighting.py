from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
SKETCH = ROOT / 'firmware/incubator/incubator.ino'


def body(source, name):
    match = re.search(r'\b' + re.escape(name) + r'\([^)]*\)\s*\{', source)
    if match is None:
        raise AssertionError(f'Missing function: {name}')
    start = match.end()
    depth = 1
    for index in range(start, len(source)):
        if source[index] == '{':
            depth += 1
        elif source[index] == '}':
            depth -= 1
            if depth == 0:
                return source[start:index]
    raise AssertionError(f'Unterminated function: {name}')


class PhotoLightingTests(unittest.TestCase):
    def test_both_photo_upload_and_local_capture_use_same_lit_capture_path(self):
        source = SKETCH.read_text(encoding='utf-8')
        self.assertIn('capturePhotoTo(cloudPhoto)', body(source, 'cloudUpload'))
        self.assertIn('capturePhotoTo(localPhoto)', body(source, 'handleCapture'))
        self.assertNotIn('manualLight =', body(source, 'capturePhotoTo'))
        self.assertNotIn('lampMode =', body(source, 'capturePhotoTo'))

    def test_lighting_lease_is_cancelled_before_transmission_and_on_lighting_failure(self):
        source = SKETCH.read_text(encoding='utf-8')
        capture = body(source, 'capturePhotoTo')
        fail = re.search(r'if \(!beginPhotoLight\(\)\)\s*\{([^}]+)\}', capture)
        self.assertIsNotNone(fail)
        self.assertIn('endPhotoLight()', fail[1])
        self.assertIn('xSemaphoreGive(cameraMutex)', fail[1])
        self.assertLess(capture.rindex('endPhotoLight()'), capture.index('return ok;'))
        self.assertNotIn('beginPhotoLight', body(source, 'cloudUpload'))

    def test_freshness_watchdog_runs_even_when_wifi_is_disconnected(self):
        source = SKETCH.read_text(encoding='utf-8')
        task = body(source, 'telemetryTask')
        self.assertLess(task.index('refreshHeaterPin()'), task.index('WiFi.status()'))
        self.assertRegex(source, r'PHOTO_LIGHT_MAX_MS\s*=\s*10000;')
        self.assertRegex(source, r'PHOTO_LIGHT_SETTLE_MS\s*=\s*600;')
        self.assertRegex(source, r'SENSOR_STALE_MS\s*=\s*8000;')

    def test_image_is_rejected_if_light_safety_expires_during_frame_acquisition(self):
        source = SKETCH.read_text(encoding='utf-8')
        capture = body(source, 'capturePhotoTo')
        self.assertIn('if (!photoLightReady()) break;', capture)
        self.assertIn('if (jpegValid(fb) && photoLightReady())', capture)


if __name__ == '__main__':
    unittest.main()
