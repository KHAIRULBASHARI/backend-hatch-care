<?php

namespace App\Http\Controllers;

use App\Models\CaptureJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EggController extends Controller
{
    /**
     * Request a new capture job.
     */
    public function request(Request $request)
    {
        $validated = $request->validate([
            'exposure' => 'nullable|integer|between:0,1200',
            'gain' => 'nullable|integer|between:0,30',
            'quality' => 'nullable|integer|between:4,15',
            'settle_ms' => 'nullable|integer|between:100,3000',
        ]);

        $jobData = array_filter($validated, fn ($val) => $val !== null);

        $job = CaptureJob::create($jobData);

        // Broadcast perintah via MQTT realtime ke broker lokal port 1883
        $this->publishMqttJob($job);

        return response()->json(['job' => $job->id]);
    }

    /**
     * Publish perintah capture candling ke broker MQTT lokal (port 1883)
     */
    private function publishMqttJob(CaptureJob $job): void
    {
        try {
            $deviceKey = config('services.device.key') ?? env('DEVICE_KEY', 'EggCamSecretKey_9876543210_abcdef1234567890');
            $topic = 'candling/' . $deviceKey . '/job';
            $payload = [
                'job' => $job->id,
                'exp' => $job->exposure ?? 500,
                'gain' => $job->gain ?? 0,
                'q' => $job->quality ?? 4,
                'delay' => $job->settle_ms ?? 250,
            ];
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (function_exists('exec')) {
                $escapedJson = escapeshellarg($json);
                $escapedTopic = escapeshellarg($topic);
                @exec("mosquitto_pub -h 127.0.0.1 -p 1883 -t {$escapedTopic} -m {$escapedJson} > /dev/null 2>&1 &");
            }

            // Tandai status taken seketika agar web tracker langsung mendeteksi proses foto
            $job->update([
                'status' => 'taken',
                'taken_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish MQTT candling job: ' . $e->getMessage());
        }
    }

    public function upload(Request $request)
    {
        $started = hrtime(true);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120']]);

        $file = $request->file('image');
        $content = file_get_contents($file->getRealPath());
        $metrics = ['image_bytes' => strlen($content)];
        $modelStarted = hrtime(true);

        $apiUrl = config('services.candling.api_url');
        if (! $apiUrl) {
            return response()->json(['message' => 'CANDLING_API_URL belum dikonfigurasi.', 'metrics' => $metrics], 500);
        }

        try {
            $response = Http::timeout(15)
                ->attach('image', $content, 'candling.'.$file->guessExtension())
                ->post($apiUrl);
            $metrics['model_request_ms'] = round((hrtime(true) - $modelStarted) / 1_000_000, 2);
            $prediction = $response->json('data');
            if (! $response->successful() || ! is_array($prediction) || empty($prediction['label'])) {
                return response()->json(['message' => 'Model candling tidak dapat memproses gambar.', 'metrics' => $metrics], 502);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal memanggil API Candling NN: '.$e->getMessage());
            $metrics['model_request_ms'] = round((hrtime(true) - $modelStarted) / 1_000_000, 2);

            return response()->json(['message' => 'Layanan model candling tidak tersedia.', 'metrics' => $metrics], 502);
        }

        $image = 'data:'.$file->getMimeType().';base64,'.base64_encode($content);
        $llmAnalysis = null;
        $llmStatus = 'not_configured';
        $metrics['llm_request_ms'] = null;
        if (($key = config('services.candling.llm_key')) && ($llmUrl = config('services.candling.llm_url'))) {
            $llmPrediction = $prediction;
            unset($llmPrediction['visualisasi']);
            $llmStarted = hrtime(true);
            try {
                $llmResponse = Http::timeout(45)->withToken($key)->post($llmUrl, [
                    'model' => config('services.candling.llm_model'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Jelaskan hasil candling telur dengan bahasa Indonesia yang sederhana. Berikan ringkasan, kondisi visual, dan saran tindakan. Sampaikan jika hasil tidak pasti.'],
                        ['role' => 'user', 'content' => [
                            ['type' => 'text', 'text' => 'Hasil model: '.json_encode($llmPrediction, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)],
                            ['type' => 'image_url', 'image_url' => ['url' => $image]],
                        ]],
                    ],
                    'max_tokens' => 500,
                ]);
                $llmAnalysis = $llmResponse->successful() ? $llmResponse->json('choices.0.message.content') : null;
                $llmStatus = is_string($llmAnalysis) && $llmAnalysis !== '' ? 'success' : 'failed';
            } catch (\Throwable $e) {
                Log::warning('Gagal memanggil LLM candling: '.$e->getMessage());
                $llmStatus = 'failed';
            }
            $metrics['llm_request_ms'] = round((hrtime(true) - $llmStarted) / 1_000_000, 2);
        }
        $metrics['server_processing_ms'] = round((hrtime(true) - $started) / 1_000_000, 2);
        $prediction['_metrics'] = $metrics;
        $prediction['_llm_status'] = $llmStatus;
        $job = CaptureJob::create([
            'status' => 'done',
            'image_base64' => $image,
            'llm_analysis' => $llmAnalysis,
            'prediction_label' => $prediction['label'],
            'confidence' => $prediction['keyakinan'] ?? null,
            'prediction_result' => $prediction,
        ]);

        return response()->json([
            'id' => $job->id,
            'url' => $job->image_base64,
            'prediction_label' => $job->prediction_label,
            'confidence' => $job->confidence,
            'prediction_result' => $prediction,
            'llm_analysis' => $llmAnalysis,
            'llm_status' => $llmStatus,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Show job status and image URL.
     */
    public function show(CaptureJob $job)
    {
        return response()->json([
            'status' => $job->status,
            'url' => $job->image_base64 ?: ($job->image_path ? Storage::disk('public')->url($job->image_path) : null),
            'prediction_label' => $job->prediction_label,
            'confidence' => $job->confidence,
            'prediction_result' => $job->prediction_result,
            'metrics' => $job->prediction_result['_metrics'] ?? null,
            'llm_analysis' => $job->llm_analysis,
        ]);
    }

    /**
     * List last 20 done jobs.
     */
    public function history()
    {
        $jobs = CaptureJob::where('status', 'done')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($j) => [
                'id' => $j->id,
                'url' => $j->image_base64 ?: ($j->image_path ? Storage::disk('public')->url($j->image_path) : null),
                'prediction_label' => $j->prediction_label,
                'confidence' => $j->confidence,
                'at' => $j->updated_at->toIso8601String(),
            ]);

        return response()->json($jobs);
    }

    /**
     * Return device status.
     */
    public function status()
    {
        $last = Cache::get('device:last_seen');
        $ago = $last !== null ? (now()->timestamp - (int) $last) : null;

        return response()->json([
            'online' => ($ago !== null && $ago < 25),
            'last_seen_sec' => $ago,
            'rssi' => Cache::get('device:rssi'),
        ]);
    }

    /**
     * Show dashboard view.
     */
    public function dashboard()
    {
        return view('egg.dashboard');
    }
}
