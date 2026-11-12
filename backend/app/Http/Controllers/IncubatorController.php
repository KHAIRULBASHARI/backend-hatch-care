<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IncubatorController extends Controller
{
    private const HISTORY_TTL_SECONDS = 3600; // Simpan foto 1 jam terakhir

    private const DEFAULT_CONTROL = [
        'lampMode' => 'auto',
        'motorManualRequest' => false,
        'motorCommandId' => null,
        'tempMin' => 37.0,
        'tempMax' => 38.0,
        'motorIntervalHours' => 4,
        'motorDurationSeconds' => 15,
        'captureIntervalSeconds' => 120, // default 2 menit (120 detik)
    ];

    private function controlSettings(): array
    {
        return array_replace(self::DEFAULT_CONTROL, Cache::get('incubator:control', []));
    }

    /**
     * Bersihkan foto & entri riwayat yang usianya lebih dari 1 jam (3600 detik).
     */
    private function pruneAndGetHistory(): array
    {
        $cutoff = now()->timestamp - self::HISTORY_TTL_SECONDS;
        $history = Cache::get('incubator:history', []);
        if (! is_array($history)) {
            $history = [];
        }

        $kept = [];
        $changed = false;

        foreach ($history as $item) {
            $ts = (int) ($item['timestamp'] ?? 0);
            if ($ts >= $cutoff && ! empty($item['filename'])) {
                $kept[] = $item;
            } else {
                $changed = true;
                if (! empty($item['filename'])) {
                    Storage::disk('public')->delete('incubator/'.basename($item['filename']));
                }
            }
        }

        // Bersihkan juga file yatim di folder public/incubator yang lebih tua dari 1 jam
        $disk = Storage::disk('public');
        if ($disk->exists('incubator')) {
            foreach ($disk->files('incubator') as $filePath) {
                if ($disk->lastModified($filePath) < $cutoff) {
                    $disk->delete($filePath);
                }
            }
        }

        if ($changed) {
            Cache::put('incubator:history', $kept, self::HISTORY_TTL_SECONDS + 600);
        }

        return $kept;
    }

    /**
     * Handle incubator snapshot upload from ESP32 Incubator.
     */
    public function upload(Request $request)
    {
        $content = null;

        if ($request->hasFile('image')) {
            $content = file_get_contents($request->file('image')->getRealPath());
        } elseif ($request->hasFile('file')) {
            $content = file_get_contents($request->file('file')->getRealPath());
        } elseif (count($request->allFiles()) > 0) {
            $files = $request->allFiles();
            $file = reset($files);
            if (is_array($file)) {
                $file = reset($file);
            }
            if ($file instanceof UploadedFile) {
                $content = file_get_contents($file->getRealPath());
            }
        }

        if (! $content && ($request->isJson() || (is_array($request->json()->all()) && ! empty($request->json()->all())))) {
            $b64 = $request->json('image') ?? $request->json('image_base64');
            if ($b64) {
                if (str_contains($b64, ',')) {
                    $b64 = explode(',', $b64, 2)[1];
                }
                $decoded = base64_decode($b64, true);
                if ($decoded !== false) {
                    $content = $decoded;
                }
            }
        }

        if (! $content) {
            $content = $request->getContent();
        }

        $length = is_string($content) ? strlen($content) : 0;

        if ($length < 100 || $length > 10 * 1024 * 1024) {
            abort(422, 'Invalid file size.');
        }

        if (! str_starts_with($content, "\xFF\xD8")) {
            abort(422, 'Invalid JPEG content.');
        }

        if ($request->hasHeader('X-RSSI')) {
            Cache::put('incubator:rssi', (int) $request->header('X-RSSI'), 3600);
        }

        $now = now();
        $timestamp = $now->timestamp;

        $temp = $request->header('X-Temp') ?? $request->header('X-Temperature') ?? $request->input('temperature');
        $humidity = $request->header('X-Humidity') ?? $request->input('humidity');
        $lamp = $request->header('X-Lamp') ?? $request->header('X-Heater') ?? $request->input('lamp');
        $motor = $request->header('X-Motor') ?? $request->input('motor');

        $control = $this->controlSettings();
        $cacheSeconds = max(3600, 2 * $control['captureIntervalSeconds'] + 60);

        $tempVal = null;
        if ($request->header('X-Temp-Valid') === '0') {
            Cache::forget('incubator:temperature');
        } elseif ($temp !== null) {
            $tempVal = round((float) $temp, 1);
            Cache::put('incubator:temperature', $tempVal, $cacheSeconds);
        } else {
            $tempVal = Cache::get('incubator:temperature');
        }

        $humVal = null;
        if ($request->header('X-Humidity-Valid') === '0') {
            Cache::forget('incubator:humidity');
        } elseif ($humidity !== null) {
            $humVal = round((float) $humidity, 1);
            Cache::put('incubator:humidity', $humVal, $cacheSeconds);
        } else {
            $humVal = Cache::get('incubator:humidity');
        }

        if ($lamp !== null) {
            Cache::put('incubator:lamp', (bool) $lamp, $cacheSeconds);
        }
        if ($motor !== null) {
            Cache::put('incubator:motor', (bool) $motor, $cacheSeconds);
        }

        // Simpan file JPEG ke disk untuk riwayat 1 jam terakhir
        $id = $now->format('Ymd_His').'_'.Str::lower(Str::random(4));
        $filename = "inc_{$id}.jpg";
        Storage::disk('public')->put("incubator/{$filename}", $content);
        $photoUrl = "/incubator/photo/{$filename}";

        $entry = [
            'id' => $id,
            'filename' => $filename,
            'url' => $photoUrl,
            'timestamp' => $timestamp,
            'at' => $now->toIso8601String(),
            'size_kb' => (int) round($length / 1024),
            'temperature' => $tempVal,
            'humidity' => $humVal,
            'lamp' => $lamp !== null ? (bool) $lamp : Cache::get('incubator:lamp'),
            'motor' => $motor !== null ? (bool) $motor : Cache::get('incubator:motor'),
        ];

        $history = $this->pruneAndGetHistory();
        array_unshift($history, $entry);
        Cache::put('incubator:history', $history, self::HISTORY_TTL_SECONDS + 600);

        Cache::put('incubator:latest_filename', $filename, $cacheSeconds);
        Cache::put('incubator:latest_url', $photoUrl, $cacheSeconds);
        Cache::put('incubator:last_seen', $timestamp, $cacheSeconds);

        // Foto sudah berhasil di-upload, reset trigger capture agar tidak looping
        Cache::forget('incubator:trigger_capture');
        Cache::forget('incubator:capture_command_id');
        $control['triggerCapture'] = false;
        unset($control['captureCommandId']);

        return response()->json([
            'status' => 'success',
            'message' => 'Incubator snapshot saved',
            'url' => $photoUrl,
            'timestamp' => $timestamp,
            'control' => $control,
        ]);
    }

    /**
     * Serve saved incubator photo from public storage.
     */
    public function photo(string $filename)
    {
        $safe = basename($filename);
        $path = "incubator/{$safe}";
        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $bytes = Storage::disk('public')->get($path);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => strlen($bytes),
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Return list of incubator photos from the last 1 hour.
     */
    public function history()
    {
        return response()->json([
            'status' => 'success',
            'ttl_seconds' => self::HISTORY_TTL_SECONDS,
            'history' => $this->pruneAndGetHistory(),
        ]);
    }

    /**
     * Get or update remote control settings for the incubator.
     */
    public function control(Request $request)
    {
        if ($request->isMethod('post') || $request->isMethod('put')) {
            $request->validate([
                'captureIntervalSeconds' => 'sometimes|required|integer|between:5,3600',
                'lampMode' => 'sometimes|required|string|in:auto,on,off',
                'tempMin' => 'sometimes|required|numeric|between:30,45',
                'tempMax' => 'sometimes|required|numeric|between:30,45',
                'motorIntervalHours' => 'sometimes|required|integer|between:1,24',
                'motorDurationSeconds' => 'sometimes|required|integer|between:1,180',
                'motorManualRequest' => 'sometimes|boolean',
                'triggerMotor' => 'sometimes|boolean',
                'triggerCapture' => 'sometimes|boolean',
                'captureManual' => 'sometimes|boolean',
                'action' => 'sometimes|string',
            ]);

            $mqttPayload = [];

            $current = Cache::lock('incubator:control-lock', 10)->block(3, function () use ($request, &$mqttPayload) {
                $current = $this->controlSettings();
                if ($request->has('lampMode')) {
                    $mode = strtolower((string) $request->input('lampMode'));
                    if (in_array($mode, ['auto', 'on', 'off'])) {
                        $current['lampMode'] = $mode;
                        $mqttPayload['lampMode'] = $mode;
                    }
                }
                if ($request->has('motorManualRequest') || $request->has('triggerMotor')) {
                    $current['motorManualRequest'] = $request->boolean('motorManualRequest') || $request->boolean('triggerMotor');
                    $current['motorCommandId'] = $current['motorManualRequest'] ? (string) Str::uuid() : null;
                    if ($current['motorManualRequest']) {
                        $mqttPayload['motorManualRequest'] = true;
                        $mqttPayload['motorCommandId'] = $current['motorCommandId'];
                    }
                }
                if ($request->has('triggerCapture') || $request->has('captureManual') || $request->input('action') === 'capture') {
                    $current['triggerCapture'] = true;
                    $current['captureCommandId'] = (string) Str::uuid();
                    $mqttPayload['action'] = 'capture';
                    $mqttPayload['triggerCapture'] = true;
                    $mqttPayload['captureCommandId'] = $current['captureCommandId'];
                }
                if ($request->has('tempMin')) {
                    $current['tempMin'] = round((float) $request->input('tempMin'), 1);
                    $mqttPayload['tempMin'] = $current['tempMin'];
                }
                if ($request->has('tempMax')) {
                    $current['tempMax'] = round((float) $request->input('tempMax'), 1);
                    $mqttPayload['tempMax'] = $current['tempMax'];
                }
                if ($request->has('motorIntervalHours')) {
                    $current['motorIntervalHours'] = (int) $request->input('motorIntervalHours');
                    $mqttPayload['motorIntervalHours'] = $current['motorIntervalHours'];
                }
                if ($request->has('motorDurationSeconds')) {
                    $current['motorDurationSeconds'] = (int) $request->input('motorDurationSeconds');
                    $mqttPayload['motorDurationSeconds'] = $current['motorDurationSeconds'];
                }
                if ($request->has('captureIntervalSeconds')) {
                    $current['captureIntervalSeconds'] = (int) $request->input('captureIntervalSeconds');
                    $mqttPayload['captureIntervalSeconds'] = $current['captureIntervalSeconds'];
                }

                Cache::forever('incubator:control', $current);

                return $current;
            });

            // Kirim notifikasi realtime via MQTT jika ada payload kontrol
            if (!empty($mqttPayload)) {
                $this->publishMqttControl($mqttPayload);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Control settings updated',
                'control' => $current,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'control' => $this->controlSettings(),
        ]);
    }

    /**
     * Publish perintah kontrol ke broker MQTT lokal (port 1883)
     */
    private function publishMqttControl(array $payload): void
    {
        try {
            $deviceKey = config('services.device.key') ?? env('DEVICE_KEY', 'EggCamSecretKey_9876543210_abcdef1234567890');
            $topic = 'incubator/' . $deviceKey . '/control';
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (function_exists('exec')) {
                $escapedJson = escapeshellarg($json);
                $escapedTopic = escapeshellarg($topic);
                @exec("mosquitto_pub -h 127.0.0.1 -p 1883 -t {$escapedTopic} -m {$escapedJson} > /dev/null 2>&1 &");
            }
        } catch (\Throwable $e) {
            // Silently fall back to cache polling
        }
    }

    public function deviceControl(Request $request)
    {
        $control = Cache::lock('incubator:control-lock', 10)->block(3, function () use ($request) {
            $current = $this->controlSettings();
            $ack = $request->query('motorAck');
            if (is_string($ack) && $ack !== '' && is_string($current['motorCommandId'])
                && hash_equals($current['motorCommandId'], $ack)) {
                $current['motorManualRequest'] = false;
                $current['motorCommandId'] = null;
                Cache::forever('incubator:control', $current);
            }

            return $current;
        });

        return response()->json(['status' => 'success', 'control' => $control]);
    }

    /**
     * Endpoint telemetri ringan via HTTP (fallback jika MQTT offline).
     */
    public function telemetry(Request $request)
    {
        $temp = $request->input('temperature') ?? $request->input('temp');
        $humidity = $request->input('humidity') ?? $request->input('hum');
        $lamp = $request->input('heater') ?? $request->input('lamp');
        $motor = $request->input('motor');
        $rssi = $request->input('rssi');

        if ($temp !== null) {
            Cache::put('incubator:temperature', round((float) $temp, 1), 3600);
        }
        if ($humidity !== null) {
            Cache::put('incubator:humidity', round((float) $humidity, 1), 3600);
        }
        if ($lamp !== null) {
            Cache::put('incubator:lamp', (bool) $lamp, 3600);
        }
        if ($motor !== null) {
            Cache::put('incubator:motor', (bool) $motor, 3600);
        }
        if ($rssi !== null) {
            Cache::put('incubator:rssi', (int) $rssi, 3600);
        }
        Cache::put('incubator:last_seen', now()->timestamp, 3600);

        return response()->json(['status' => 'success', 'timestamp' => now()->timestamp]);
    }

    /**
     * Retrieve the latest incubator snapshot, telemetry, and 1-hour history.
     */
    public function latest(Request $request)
    {
        $history = $this->pruneAndGetHistory();
        $latestEntry = $history[0] ?? null;

        $lastSeen = Cache::get('incubator:last_seen') ?? ($latestEntry['timestamp'] ?? null);
        $imageUrl = $latestEntry['url'] ?? Cache::get('incubator:latest_url');

        $control = $this->controlSettings();
        $onlineSeconds = max(60, 2 * $control['captureIntervalSeconds'] + 10);
        $ago = $lastSeen !== null ? (now()->timestamp - (int) $lastSeen) : null;
        $status = $imageUrl !== null ? ($ago !== null && $ago <= $onlineSeconds ? 'online' : 'idle') : 'no_data';
        $lastUpdated = $lastSeen ? Carbon::createFromTimestamp($lastSeen)->toIso8601String() : null;

        if ($request->boolean('raw') && $latestEntry && ! empty($latestEntry['filename'])) {
            return $this->photo($latestEntry['filename']);
        }

        return response()->json([
            'status' => $status,
            'online' => ($ago !== null && $ago <= $onlineSeconds),
            'image' => $imageUrl,
            'url' => $imageUrl,
            'total_eggs' => (int) Cache::get('incubator:total_eggs', 0),
            'detections' => Cache::get('incubator:detections', []),
            'temperature' => Cache::get('incubator:temperature'),
            'humidity' => Cache::get('incubator:humidity'),
            'lamp' => Cache::get('incubator:lamp'),
            'motor' => Cache::get('incubator:motor'),
            'rssi' => Cache::get('incubator:rssi'),
            'control' => $control,
            'history' => $history,
            'last_updated' => $lastUpdated,
            'timestamp' => $lastSeen,
            'last_seen_sec' => $ago,
        ]);
    }
}
