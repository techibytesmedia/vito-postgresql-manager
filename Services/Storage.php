<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services;

use App\Models\Server;
use App\Models\StorageProvider;
use App\Models\User;
use App\StorageProviders\S3;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\Backup;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\Input;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

final class Storage
{
    public function provider(Server $server, User $user, int $id): StorageProvider
    {
        $provider = StorageProvider::getByProjectId($server->project_id, $user)->findOrFail($id);
        Gate::forUser($user)->authorize('update', $provider);
        if (!in_array($provider->provider, ['local', 's3'], true)) {
            throw new RuntimeException('This build supports Vito Local and S3 providers.');
        }
        return $provider;
    }

    public function location(Backup $backup, StorageProvider $provider): array
    {
        $scope = $backup->project_id.'/'.$backup->server_id.'/'.$backup->cluster_id.'/'.$backup->artifact.'.dump';
        $credentials = $provider->credentials;
        $base = $provider->provider === 'local' ? ($credentials['path'] ?? '') : '/var/lib/postgresql';
        if (!str_starts_with($base, '/') || preg_match('#(?:^|/)\.\.(?:/|$)|[\x00-\x1f]#', $base)) {
            throw new RuntimeException('Local backup storage requires an absolute POSIX path without parent traversal.');
        }
        $prefix = trim($credentials['path'] ?? '', '/');
        if ($provider->provider === 's3' && $prefix !== '' && !preg_match('#^[A-Za-z0-9_./-]+$#', $prefix)) {
            throw new RuntimeException('Use a simple S3 storage prefix.');
        }
        return [
            'base' => rtrim($base, '/'), 'key' => ($prefix !== '' ? $prefix.'/' : '').'vito-postgresql/'.$scope,
            'path' => rtrim($base, '/').'/vito-postgresql/'.$scope,
            'provider' => $provider->provider, 'bucket' => $provider->provider === 's3' ? $credentials['bucket'] : null,
        ];
    }

    public function s3(StorageProvider $provider): \Aws\S3\S3Client
    {
        $handler = $provider->provider();
        if (!$handler instanceof S3) { throw new RuntimeException('Expected Vito S3 provider.'); }
        // Reuse Vito's encrypted credentials and official SDK configuration, but
        // avoid core SSH storage templates which put credentials into commands.
        $handler->buildClientConfig();
        return $handler->getClient();
    }

    public function upload(Server $server, Backup $backup, StorageProvider $provider, array $location): void
    {
        if ($provider->provider !== 's3') { return; }
        $local = tempnam(sys_get_temp_dir(), 'vpgm-dump-');
        $remote = '/tmp/vpgm-export-'.$backup->artifact;
        try {
            chmod($local, 0600);
            $ssh = $server->ssh()->clearLog()->setLog(null);
            $ssh->exec('sudo -n install -m 600 -o '.Input::shell($server->getSshUser()).' '.Input::shell($location['path']).' '.Input::shell($remote), timeout: 120);
            $ssh->download($local, $remote);
            if (hash_file('sha256', $local) !== $backup->metadata['sha256']) { throw new RuntimeException('Backup transfer checksum failed.'); }
            $this->s3($provider)->putObject(['Bucket' => $location['bucket'], 'Key' => $location['key'], 'SourceFile' => $local]);
        } finally {
            @unlink($local);
            try { $server->ssh()->clearLog()->setLog(null)->exec('rm -f -- '.Input::shell($remote), timeout: 30); } catch (Throwable) {}
        }
    }

    public function download(Server $server, Backup $backup, StorageProvider $provider, array $location): void
    {
        if ($provider->provider !== 's3') { return; }
        $local = tempnam(sys_get_temp_dir(), 'vpgm-dump-');
        try {
            chmod($local, 0600);
            $this->s3($provider)->getObject(['Bucket' => $location['bucket'], 'Key' => $location['key'], 'SaveAs' => $local]);
            if (hash_file('sha256', $local) !== $backup->metadata['sha256']) { throw new RuntimeException('Backup checksum failed.'); }
            app(Remote::class)->call($server, $this->artifactPayload($backup, $location) + ['operation' => 'artifact_prepare']);
            $server->ssh()->clearLog()->setLog(null)->upload($local, $location['path'], 'postgres', permission: '600');
        } finally { @unlink($local); }
    }

    public function artifactPayload(Backup $backup, array $location): array
    {
        return ['base' => $location['base'], 'project_id' => $backup->project_id, 'server_id' => $backup->server_id,
            'cluster_id' => $backup->cluster_id, 'artifact' => $backup->artifact];
    }

    public function delete(Server $server, Backup $backup, StorageProvider $provider): void
    {
        $location = $backup->metadata['location'];
        $this->assertLocation($backup, $provider, $location);
        if ($provider->provider === 's3') {
            $this->s3($provider)->deleteObject(['Bucket' => $location['bucket'], 'Key' => $location['key']]);
        } else {
            app(Remote::class)->call($server, $this->artifactPayload($backup, $location) + ['operation' => 'artifact_delete']);
        }
    }

    public function assertLocation(Backup $backup, StorageProvider $provider, array $location): void
    {
        if ($location !== $this->location($backup, $provider)) {
            throw new RuntimeException('Storage settings changed since this backup. Restore the original provider path/bucket before restore or retention.');
        }
    }
}
