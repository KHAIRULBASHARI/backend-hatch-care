<?php

namespace App\Console\Commands;

use App\Models\CaptureJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanOldEggCaptures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'egg:clean-old-captures';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up capture jobs older than 30 days and their associated files.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
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

        return Command::SUCCESS;
    }
}
