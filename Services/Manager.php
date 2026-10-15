<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services;

use App\Models\Server;
use App\Models\User;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\{Backup, BackupSchedule, Cluster, Operation, Resource, ServerState};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\Lifecycle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class Manager
{
    public function __construct(private Remote $remote, private Storage $storage) {}

    public function target(Cluster $cluster): array
    {
        return ['major' => $cluster->major, 'cluster' => $cluster->name, 'port' => $cluster->port];
    }

    public function sync(Server $server, Cluster $cluster, ?array $snapshot = null): void
    {
        $snapshot ??= $this->remote->call($server, ['operation' => 'snapshot'] + $this->target($cluster));
        $cluster->update(['snapshot' => $snapshot]);
        if (!array_key_exists('databases', $snapshot)) { return; } // Offline: preserve inventory.
        foreach (['database' => 'databases', 'role' => 'roles'] as $kind => $key) {
            Resource::onServer($server)->where('cluster_id', $cluster->id)->where('kind', $kind)->update(['present' => false]);
            foreach ($snapshot[$key] as $item) {
                $existing = Resource::onServer($server)->where('cluster_id', $cluster->id)->where('kind', $kind)->where('name', $item['name'])->first();
                $changes = ['present' => true, 'metadata' => $item];
                if ($existing && (string) ($existing->metadata['oid'] ?? '') !== (string) $item['oid']) { $changes['managed'] = false; }
                Resource::updateOrCreate(['project_id' => $server->project_id, 'server_id' => $server->id,
                    'cluster_id' => $cluster->id, 'kind' => $kind, 'name' => $item['name']], $changes);
            }
        }
    }

    public function resource(Server $server, Cluster $cluster, int $id, string $kind, bool $managed = false): Resource
    {
        $q = Resource::onServer($server)->where('cluster_id', $cluster->id)->where('kind', $kind)->where('present', true);
        if ($managed) { $q->where('managed', true); }
        return $q->findOrFail($id);
    }

    public function run(Operation $operation): array
    {
        $server = Server::findOrFail($operation->server_id);
        if ((int) $server->project_id !== (int) $operation->project_id) { throw new RuntimeException('Server project changed.'); }
        $user = User::findOrFail($operation->user_id);
        Gate::forUser($user)->authorize('update', $server);
        if (in_array($operation->action, ['delete_database', 'delete_role', 'service', 'restore', 'ownership'], true)) { Gate::forUser($user)->authorize('delete', $server); }
        $p = $operation->payload;
        if ($operation->action === 'discover') {
            $discovery = $this->remote->call($server, ['operation' => 'discover']);
            ServerState::updateOrCreate(['project_id' => $server->project_id, 'server_id' => $server->id], ['discovery' => $discovery]);
            return ['message' => count($discovery) ? 'Clusters discovered. Reload Features, then open Adopt an existing cluster.' : 'No clusters found. Install PostgreSQL separately, then discover again.'];
        }
        if ($operation->action === 'adopt') {
            $discovered = ServerState::onServer($server)->firstOrFail()->discovery;
            $target = collect($discovered)->first(fn ($c) => $c['major'] === $p['major'] && $c['cluster'] === $p['cluster'] && $c['port'] === $p['port']);
            if (!$target) { throw new RuntimeException('Discover the cluster again.'); }
            $snapshot = $this->remote->call($server, ['operation' => 'adopt'] + $p);
            $cluster = Cluster::updateOrCreate(['project_id' => $server->project_id, 'server_id' => $server->id, 'major' => $p['major'], 'name' => $p['cluster']], ['port' => $p['port']]);
            $this->sync($server, $cluster, $snapshot);
            return ['message' => 'Existing cluster adopted; no installation or authentication settings were changed.'];
        }
        $cluster = Cluster::onServer($server)->findOrFail($operation->cluster_id);
        $target = $this->target($cluster);
        $action = $operation->action;
        if ($action === 'refresh') { $this->sync($server, $cluster); return ['message' => 'Status and inventory refreshed.']; }
        if ($action === 'service') {
            $result = $this->remote->service($server, $target, $p['control']);
            $this->sync($server, $cluster);
            return $result;
        }
        if ($action === 'backup') { return $this->backup($server, $user, $cluster, $p); }
        if ($action === 'restore') { return $this->restore($server, $user, $cluster, $p); }
        $args = ['operation' => $action] + $target;
        if (isset($p['database_id'])) { $args['database'] = $this->resource($server, $cluster, $p['database_id'], 'database', in_array($action, ['access', 'ownership'], true))->name; }
        if (isset($p['role_id'])) { $args['role'] = $this->resource($server, $cluster, $p['role_id'], 'role')->name; }
        if (isset($p['owner_id'])) { $args['owner'] = $this->resource($server, $cluster, $p['owner_id'], 'role')->name; }
        if (isset($p['creator_id'])) { $args['creator'] = $this->resource($server, $cluster, $p['creator_id'], 'role')->name; }
        if ($action === 'manage_database') {
            $this->resource($server, $cluster, $p['database_id'], 'database')->update(['managed' => true]);
            return ['message' => 'Database marked for explicit access management; no PostgreSQL grants changed.'];
        }
        foreach (['name', 'locale', 'encoding', 'level'] as $key) { if (isset($p[$key])) { $args[$key] = $p[$key]; } }
        $result = $this->remote->call($server, $args);
        $this->sync($server, $cluster);
        if ($action === 'create_database') { Resource::onServer($server)->where('cluster_id', $cluster->id)->where('kind', 'database')->where('name', $p['name'])->update(['managed' => true]); }
        return $result;
    }

    private function backup(Server $server, User $user, Cluster $cluster, array $p): array
    {
        $database = $this->resource($server, $cluster, $p['database_id'], 'database');
        $provider = $this->storage->provider($server, $user, $p['storage_id']);
        $backup = Backup::create(['project_id' => $server->project_id, 'server_id' => $server->id, 'cluster_id' => $cluster->id,
            'resource_id' => $database->id, 'database_name' => $database->name, 'storage_id' => $provider->id,
            'schedule_id' => $p['schedule_id'] ?? null, 'artifact' => bin2hex(random_bytes(16)),
            'engine' => 'postgresql', 'format' => 'custom', 'major' => $cluster->major, 'state' => 'running']);
        $location = $this->storage->location($backup, $provider);
        try {
            $meta = $this->remote->call($server, ['operation' => 'backup', 'database' => $database->name] + $this->target($cluster) + $this->storage->artifactPayload($backup, $location));
            $backup->update(['metadata' => $meta + ['location' => $location, 'server_version' => $cluster->snapshot['server_version'] ?? null]]);
            $this->storage->upload($server, $backup, $provider, $location);
            $backup->update(['state' => 'complete', 'result' => 'PostgreSQL custom archive saved; checksum verified.']);
            if ($provider->provider === 's3') { $this->remote->call($server, ['operation' => 'artifact_delete'] + $this->storage->artifactPayload($backup, $location)); }
        } catch (Throwable $e) {
            $backup->update(['state' => 'failed', 'result' => 'Backup failed. Verify cluster, storage permissions, and provider connectivity.']);
            // A partial/offloaded artifact is always safe to retry deleting by its own identity.
            try { $this->remote->call($server, ['operation' => 'artifact_delete'] + $this->storage->artifactPayload($backup, $location)); } catch (Throwable) {}
            throw $e;
        }
        if (isset($p['schedule_id'])) {
            $schedule = BackupSchedule::onServer($server)->where('cluster_id', $cluster->id)->findOrFail($p['schedule_id']);
            $old = Backup::onServer($server)->where('cluster_id', $cluster->id)->where('schedule_id', $schedule->id)->where('state', 'complete')->orderByDesc('id')->skip($schedule->retention)->take(100)->get();
            foreach ($old as $item) {
                $oldProvider = $this->storage->provider($server, $user, $item->storage_id);
                $this->storage->delete($server, $item, $oldProvider);
                $item->update(['state' => 'pruned', 'result' => 'Removed by this schedule’s retention policy.']);
            }
        }
        return ['message' => 'Backup #'.$backup->id.' completed.'];
    }

    private function restore(Server $server, User $user, Cluster $cluster, array $p): array
    {
        $backup = Backup::onServer($server)->where('state', 'complete')->findOrFail($p['backup_id']);
        if ($backup->engine !== 'postgresql' || $backup->format !== 'custom' || (int) $backup->major > (int) $cluster->major) { throw new RuntimeException('Incompatible PostgreSQL backup.'); }
        $provider = $this->storage->provider($server, $user, $backup->storage_id);
        $location = $backup->metadata['location'];
        $this->storage->assertLocation($backup, $provider, $location);
        $owner = $this->resource($server, $cluster, $p['owner_id'], 'role');
        try {
            $this->storage->download($server, $backup, $provider, $location);
            $result = $this->remote->call($server, ['operation' => 'restore', 'name' => $p['name'], 'owner' => $owner->name,
                'encoding' => $p['encoding'], 'locale' => $p['locale'], 'sha256' => $backup->metadata['sha256']]
                + $this->target($cluster) + $this->storage->artifactPayload($backup, $location));
        } finally {
            if ($provider->provider === 's3') { try { $this->remote->call($server, ['operation' => 'artifact_delete'] + $this->storage->artifactPayload($backup, $location)); } catch (Throwable) {} }
            $this->sync($server, $cluster);
        }
        Resource::onServer($server)->where('cluster_id', $cluster->id)->where('kind', 'database')->where('name', $p['name'])->update(['managed' => true]);
        return $result;
    }
}
