<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services;

use App\Models\Server;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\Input;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Remote
{
    public function call(Server $server, #[\SensitiveParameter] array $payload): array
    {
        $token = str_replace('-', '', (string) Str::uuid());
        $dir = '/tmp/vito-pgm-'.$token;
        $local = tempnam(sys_get_temp_dir(), 'vpgm-');
        $ssh = $server->ssh()->clearLog()->setLog(null);
        try {
            chmod($local, 0600);
            file_put_contents($local, json_encode($payload, JSON_THROW_ON_ERROR));
            $ssh->exec('sudo -n install -d -m 700 -o postgres -g postgres '.Input::shell($dir), timeout: 30);
            $ssh->upload(dirname(__DIR__).'/runtime/agent.py', $dir.'/agent.py', 'postgres', permission: '600');
            $ssh->upload($local, $dir.'/request.json', 'postgres', permission: '600');
            $output = $ssh->exec('sudo -n -u postgres /usr/bin/python3 '.Input::shell($dir.'/agent.py').' '.Input::shell($dir.'/request.json'), timeout: 1900);
            $reply = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (!($reply['ok'] ?? false)) { throw new RemoteFailure($reply['error'] ?? 'PostgreSQL operation failed.'); }
            return $reply['result'];
        } catch (RemoteFailure $e) { throw $e; }
        catch (Throwable) { throw new RemoteFailure('SSH operation failed or timed out. Verify Vito’s existing SSH connection, noninteractive sudo, Python 3, and cluster tools; refresh before retrying.'); }
        finally {
            if (is_file($local)) { unlink($local); }
            try { $ssh->exec('sudo -n rm -f -- '.Input::shell($dir.'/request.json').' '.Input::shell($dir.'/agent.py').'; sudo -n rmdir -- '.Input::shell($dir), timeout: 30); } catch (Throwable) { /* See cleanup command and troubleshooting. */ }
            $ssh->disconnect();
        }
    }

    public function service(Server $server, array $target, string $action): array
    {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) { throw new RuntimeException('Invalid service action.'); }
        // Discover without an online connection so an offline cluster can start.
        $found = $this->call($server, ['operation' => 'discover']);
        $match = collect($found)->first(fn ($c) => $c['major'] === $target['major'] && $c['cluster'] === $target['cluster'] && (int) $c['port'] === (int) $target['port'] && $c['owner'] === 'postgres');
        if (!$match) { throw new RemoteFailure('Cluster identity changed. Discover and adopt it again.'); }
        $server->ssh()->clearLog()->setLog(null)->exec('sudo -n /usr/bin/pg_ctlcluster '.Input::shell($target['major']).' '.Input::shell($target['cluster']).' '.Input::shell($action), timeout: 120);
        return ['message' => 'Selected cluster '.$action.' completed.'];
    }
}
