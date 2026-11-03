<?php

namespace App\Http\Controllers;

use App\Models\CaptureJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeviceController extends Controller
{
    /**
     * Device polling endpoint.
     */
    public function poll(Request $request)
    {
        // Record poll time and RSSI
        Cache::put('device:last_seen', now()->timestamp, 3600);
        if ($request->hasHeader('X-RSSI')) {
            Cache::put('device:rssi', (int) $request->header('X-RSSI'), 3600);
        }

        // Expire stale taken jobs: taken_at < now()->subSeconds(60)
        CaptureJob::where('status', 'taken')
            ->where('taken_at', '<', now()->subSeconds(60))
            ->update(['status' => 'failed']);

        // Select pending job atomically with DB::transaction + lockForUpdate
        $job = DB::transaction(function () {
            $pendingJob = CaptureJob::where('status', 'pending')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            if ($pendingJob) {
                $pendingJob->status = 'taken';
                $pendingJob->taken_at = now();
                $pendingJob->save();
            }

            return $pendingJob;
        });

        if (! $job) {
            return response('NONE')->header('Content-Type', 'text/plain');
        }

        return response("job={$job->id};exp={$job->exposure};gain={$job->gain};q={$job->quality};delay={$job->settle_ms}")
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Device upload endpoint.
     */
    public function upload(Request $request)
    {
        $jobId = $request->query('job');
        $job = CaptureJob::findOrFail($jobId);

        if ($job->status !== 'taken') {
            abort(409, 'Job is not in taken status.');
        }

        if ($request->boolean('fail')) {
            $job->update(['status' => 'failed']);

            return response('OK');
        }

        $content = $request->getContent();
        $length = strlen($content);

        if ($length < 1024 || $length > 5 * 1024 * 1024) {
            abort(422, 'Invalid file size.');
        }

        if (! str_starts_with($content, "\xFF\xD8") || substr($content, -2) !== "\xFF\xD9") {
            abort(422, 'Invalid JPEG content.');
        }

        $path = "eggs/job-{$job->id}.jpg";
        Storage::disk('public')->put($path, $content);

        $job->update([
            'status' => 'done',
            'image_path' => $path,
        ]);

        return response('OK');
    }
}
