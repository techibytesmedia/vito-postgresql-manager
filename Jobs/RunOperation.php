<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Jobs;

use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\Operation;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services\{Manager, RemoteFailure};
use Throwable;

/** Durable plugin-owned queue consumer; deliberately never serialized to Redis. */
final class RunOperation
{
    public function handle(Operation $operation): void
    {
        $operation->update(['state' => 'running', 'started_at' => now(), 'result' => 'Running via the selected server’s Vito SSH connection.']);
        try {
            $result = app(Manager::class)->run($operation);
            $operation->update(['state' => 'complete', 'result' => $result['message'] ?? 'Completed.', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $message = $e instanceof RemoteFailure ? $e->getMessage() : 'Operation failed. Verify authorization, scoped selections, cluster status, and storage settings. Refresh before retrying.';
            $operation->update(['state' => 'failed', 'result' => $message, 'finished_at' => now()]);
            // No raw exception reporting: providers and SSH exceptions may contain secrets.
        }
    }
}
