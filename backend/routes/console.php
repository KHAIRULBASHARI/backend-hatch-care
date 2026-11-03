<?php

use App\Models\CaptureJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('egg:clean-old-captures', function () {
    $oldJobs = CaptureJob::where('created_at', '<', now()->subDays(30))->get();

    $count = 0;
    foreach ($oldJobs as $job) {
        if ($job->image_path && Storage::disk('public')->exists($job->image_path)) {
            Storage::disk('public')->delete($job->image_path);
        }
        $job->delete();
        $count++;
    }

    $this->info("Cleaned {$count} old capture jobs.");
})->purpose('Clean up capture jobs older than 30 days and their associated files.');

Schedule::command('egg:clean-old-captures')->daily();
