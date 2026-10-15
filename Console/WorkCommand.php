<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Console;

use App\Models\Server;
use App\Models\User;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Jobs\RunOperation;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\{BackupSchedule, Operation};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\Lifecycle;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class WorkCommand extends Command
{
    protected $signature = 'vito-pg:work {--migrate : Apply metadata schema while disabled}';
    protected $description = 'Process PostgreSQL Manager schedules and its durable operation queue';

    public function handle(): int
    {
        if ($this->option('migrate')) { Lifecycle::migrate(); $this->info('Metadata migrated. Enable the plugin to accept new work.'); return self::SUCCESS; }
        if (!Lifecycle::enabled()) { return self::SUCCESS; }
        $lock = Cache::lock('vpgm-lifecycle', 4000);
        if (!$lock->get()) { return self::SUCCESS; }
        try {
            if (!Lifecycle::enabled()) { return self::SUCCESS; }
            // No blind retry after a crashed worker: the remote operation may
            // have succeeded. Preserve the audit record and require a refresh.
            Operation::where('state', 'running')->where('started_at', '<', now()->subMinutes(65))->update(['state' => 'failed', 'result' => 'Worker was interrupted. Refresh PostgreSQL inventory before retrying.', 'finished_at' => now()]);
            $this->schedule();
            $operation = Operation::where('state', 'pending')->orderBy('id')->first();
            if ($operation && Lifecycle::enabled()) { app(RunOperation::class)->handle($operation); }
        } finally { $lock->release(); }
        return self::SUCCESS;
    }

    private function schedule(): void
    {
        foreach (BackupSchedule::where('enabled', true)->get() as $schedule) {
            try {
                $time = now($schedule->timezone);
                $slot = $time->format('Y-m-d H:i'); // Fall-back hour runs once.
                $scheduleKey = $schedule->id.'@'.$slot;
                if (Operation::where('schedule_key', $scheduleKey)->exists() || !(new CronExpression($schedule->cron))->isDue($time)) { continue; }
                if (Operation::where('cluster_id', $schedule->cluster_id)->whereIn('state', ['pending', 'running'])->where('action', 'backup')->get()->contains(fn ($op) => (int) ($op->payload['database_id'] ?? 0) === (int) $schedule->resource_id)) { continue; }
                Operation::create(['token' => (string) Str::uuid(), 'schedule_key' => $scheduleKey, 'project_id' => $schedule->project_id, 'server_id' => $schedule->server_id,
                    'cluster_id' => $schedule->cluster_id, 'user_id' => $schedule->user_id, 'action' => 'backup', 'state' => 'pending',
                    'payload' => ['database_id' => $schedule->resource_id, 'storage_id' => $schedule->storage_id, 'schedule_id' => $schedule->id]]);
                $schedule->update(['last_slot' => $slot]);
            } catch (Throwable) {
                // Invalid schedules are visible in the native status dialog.
                $schedule->update(['enabled' => false]);
            }
        }
    }
}
