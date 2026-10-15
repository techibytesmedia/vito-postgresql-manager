<?php
/** Included after tests/host.php. Uses real Vito SSH/SFTP and real Linux services. */
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Actions\{Discover, Adopt, CreateLogin, ResetPassword, CreateDatabase as PgCreate, DeleteDatabase as PgDelete, DeleteLogin, Access, Service as PgService, CreateBackup, Restore, ScheduleBackup};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\{Cluster, Resource, Operation, Backup, BackupSchedule};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\{Input, Lifecycle};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Console\WorkCommand;
use Illuminate\Support\Facades\{Artisan, Storage as Files};

$server->update(['ip' => trim(file_get_contents($root.'/.work/lab-ip')), 'port' => 22287]);
Files::disk(config('core.key_pairs_disk'))->put((string) $server->id, file_get_contents($root.'/.work/lab-key'));
Files::disk(config('core.key_pairs_disk'))->put($server->id.'.pub', file_get_contents($root.'/.work/lab-key.pub'));
app(Illuminate\Contracts\Console\Kernel::class)->registerCommand(app(WorkCommand::class));
$tag = 'vpgm_'.bin2hex(random_bytes(4));
$password = "pG ' \" $ ; \\ % ! 123456";
$service = App\Models\Service::create(['server_id' => $server->id, 'name' => 'mariadb', 'type' => 'database', 'version' => '10.11', 'status' => 'ready', 'is_default' => true]);
$mariaDb = app(App\Actions\Database\CreateDatabase::class)->create($server, ['name' => $tag, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'username' => $tag, 'password' => 'Maria-only-123456']);
check($server->fresh()->database()->name === 'mariadb', 'MariaDB is Vito default and native database/login creation works');

function submitLive(string $class, array $data = [], bool $success = true): Operation {
    global $server, $owner;
    $token = (string) Illuminate\Support\Str::uuid();
    (new $class($server))->handle(requestFor(['token' => $token] + $data, $owner));
    Artisan::call('vito-pg:work');
    $op = Operation::where('token', $token)->firstOrFail();
    check($op->state === ($success ? 'complete' : 'failed'), 'Live '.$op->action.': '.$op->state.' — '.$op->result);
    return $op;
}
function probe(array $data): array {
    global $root, $server;
    $token = bin2hex(random_bytes(10));
    $script = '/tmp/vpgm-probe-'.$token.'.py'; $request = '/tmp/vpgm-probe-'.$token.'.json';
    $local = tempnam(sys_get_temp_dir(), 'vpgm-test-');
    try {
        chmod($local, 0600); file_put_contents($local, json_encode($data, JSON_THROW_ON_ERROR));
        $ssh = $server->ssh()->clearLog()->setLog(null);
        $ssh->upload($root.'/tests/integration/probe.py', $script, 'postgres', permission: '600');
        $ssh->upload($local, $request, 'postgres', permission: '600');
        return json_decode($ssh->exec('sudo -n -u postgres /usr/bin/python3 '.Input::shell($script).' '.Input::shell($request), timeout: 60), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        @unlink($local);
        $server->ssh()->clearLog()->setLog(null)->exec('sudo -n rm -f -- '.Input::shell($script).' '.Input::shell($request), timeout: 30);
    }
}
function resourceLive(string $kind, string $name): Resource {
    global $server, $c;
    return Resource::onServer($server)->where('cluster_id', $c->id)->where('kind', $kind)->where('name', $name)->where('present', true)->firstOrFail();
}
function pick($r): string { return $r->id.': '.$r->name; }
function confirmLive(string $resource): string {
    global $server, $c; return 'server #'.$server->id.' / cluster #'.$c->id.' / '.$resource;
}

submitLive(Discover::class);
submitLive(Adopt::class, ['discovered' => '1: 18/main']);
$c = Cluster::onServer($server)->where('major', '18')->where('name', 'main')->firstOrFail();
check((int) $c->port === 55487 && $c->snapshot['bind'] === 'localhost', 'Adoption records actual port and bind configuration');
submitLive(Adopt::class, ['discovered' => '1: 18/main']);
check(Cluster::onServer($server)->count() === 1, 'Repeated adoption is idempotent');
$select = ['cluster' => pick($c)];
foreach ([$tag, $tag.'_read', $tag.'_write', $tag.'_creator'] as $name) {
    submitLive(CreateLogin::class, $select + ['name' => $name, 'password' => $password]);
}
$ownerRole = resourceLive('role', $tag); $reader = resourceLive('role', $tag.'_read');
$writer = resourceLive('role', $tag.'_write'); $creator = resourceLive('role', $tag.'_creator');
submitLive(PgCreate::class, $select + ['name' => $tag, 'owner' => pick($ownerRole), 'encoding' => 'UTF8', 'locale' => 'C.utf8']);
$database = resourceLive('database', $tag);
check($server->fresh()->database()->id === $service->id && $mariaDb->fresh()->name === $database->name, 'Identical database/login names coexist in plugin and MariaDB metadata');
check(probe(['database' => $tag, 'user' => $tag, 'password' => $password, 'sql' => "CREATE TABLE before_grant (id bigserial primary key, payload text); INSERT INTO before_grant(payload) VALUES ('preserved');"])['ok'], 'Application owner authenticates with punctuation-heavy password and creates data');
check(probe(['sql' => "SELECT NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls FROM pg_roles WHERE rolname='$tag'"])['output'] === 't', 'Application login has no administrative flags');
submitLive(Access::class, $select + ['database' => pick($database), 'role' => pick($reader), 'creator' => pick($ownerRole), 'level' => 'read']);
check(probe(['database' => $tag, 'user' => $reader->name, 'password' => $password, 'sql' => 'SELECT payload FROM before_grant'])['output'] === 'preserved', 'Read grants include existing tables');
check(!probe(['database' => $tag, 'user' => $reader->name, 'password' => $password, 'sql' => "INSERT INTO before_grant(payload) VALUES ('forbidden')"])['ok'], 'Read access cannot insert into the tested table');
check(probe(['database' => $tag, 'user' => $tag, 'password' => $password, 'sql' => "CREATE SCHEMA future_owner; CREATE TABLE future_owner.objects(id bigserial, payload text); INSERT INTO future_owner.objects(payload) VALUES ('future');"])['ok'], 'Owner creates a new schema/table after grants');
check(probe(['database' => $tag, 'user' => $reader->name, 'password' => $password, 'sql' => 'SELECT payload FROM future_owner.objects'])['output'] === 'future', 'Default privileges cover future owner schemas and tables');
check(probe(['sql' => 'GRANT CREATE ON DATABASE "'.$tag.'" TO "'.$creator->name.'"'])['ok'], 'Fixture permits a second creator');
submitLive(Access::class, $select + ['database' => pick($database), 'role' => pick($writer), 'creator' => pick($creator), 'level' => 'write']);
check(probe(['database' => $tag, 'user' => $creator->name, 'password' => $password, 'sql' => 'CREATE SCHEMA second_creator; CREATE TABLE second_creator.items(id bigserial, payload text)'])['ok'], 'Second creator makes a future schema/table');
check(probe(['database' => $tag, 'user' => $writer->name, 'password' => $password, 'sql' => "INSERT INTO second_creator.items(payload) VALUES ('writer'); UPDATE second_creator.items SET payload='updated'; SELECT count(*) FROM second_creator.items"])['output'] === '1', 'Write grants cover future tables and sequence use from second creator');

$newPassword = $password.' changed';
submitLive(ResetPassword::class, $select + ['role' => pick($reader), 'password' => $newPassword]);
check(!probe(['database' => $tag, 'user' => $reader->name, 'password' => $password, 'sql' => 'SELECT 1'])['ok'] && probe(['database' => $tag, 'user' => $reader->name, 'password' => $newPassword, 'sql' => 'SELECT 1'])['ok'], 'Password reset changes actual SCRAM authentication');
submitLive(DeleteLogin::class, $select + ['role' => pick($ownerRole), 'confirmation' => confirmLive($tag)], false);
check(probe(['database' => $tag, 'sql' => 'SELECT payload FROM before_grant'])['output'] === 'preserved', 'Role dependency failure preserves data');

$edge = $tag.' " \' ; $(touch VPGM_INJECTED)';
submitLive(PgCreate::class, $select + ['name' => $edge, 'owner' => pick($ownerRole), 'encoding' => 'UTF8', 'locale' => 'C.utf8']);
$edgeDb = resourceLive('database', $edge);
check(probe(['database' => $edge, 'sql' => 'SELECT current_database()'])['output'] === $edge, 'Quoted identifier with SQL and shell metacharacters is preserved');
submitLive(PgDelete::class, $select + ['database' => pick($edgeDb), 'confirmation' => confirmLive($edge)]);
check(trim($server->ssh()->clearLog()->setLog(null)->exec('test ! -e /home/vito/VPGM_INJECTED && echo safe')) === 'safe', 'Identifier payload did not execute a shell command');

$provider = App\Models\StorageProvider::create(['user_id' => $owner->id, 'project_id' => $project->id, 'profile' => 'Lab Local', 'provider' => 'local', 'credentials' => ['path' => '/var/lib/postgresql']]);
submitLive(CreateBackup::class, $select + ['database' => pick($database), 'storage' => $provider->id.': Local']);
$backup = Backup::onServer($server)->whereNull('schedule_id')->where('state', 'complete')->latest('id')->firstOrFail();
$restoreName = $tag.'_restored';
submitLive(Restore::class, $select + ['backup' => pick($backup), 'owner' => pick($ownerRole), 'name' => $restoreName, 'encoding' => 'UTF8', 'locale' => 'C.utf8', 'confirmation' => confirmLive($restoreName)]);
check(probe(['database' => $restoreName, 'user' => $tag, 'password' => $password, 'sql' => 'SELECT payload FROM before_grant'])['output'] === 'preserved', 'Restored PostgreSQL database contains source data and belongs to selected login');
submitLive(Restore::class, $select + ['backup' => pick($backup), 'owner' => pick($ownerRole), 'name' => $restoreName, 'encoding' => 'UTF8', 'locale' => 'C.utf8', 'confirmation' => confirmLive($restoreName)], false);
check(probe(['database' => $restoreName, 'sql' => 'SELECT count(*) FROM before_grant'])['output'] === '1', 'Restore refuses an existing target without losing its data');

submitLive(PgService::class, $select + ['control' => 'stop', 'confirmation' => confirmLive('stop')]);
check($c->fresh()->snapshot['identity']['status'] === 'down', 'Cluster-specific stop and offline snapshot work');
check(count($server->features()['vito-postgresql-manager']['actions']) === 17, 'Actual offline cluster does not break Features forms');
submitLive(PgService::class, $select + ['control' => 'start', 'confirmation' => confirmLive('start')]);
submitLive(PgService::class, $select + ['control' => 'restart', 'confirmation' => confirmLive('restart')]);
check(trim($server->ssh()->clearLog()->setLog(null)->exec('sudo mariadb -N -e "SELECT 1"')) === '1', 'PostgreSQL service controls leave MariaDB running');

submitLive(ScheduleBackup::class, $select + ['database' => pick($database), 'storage' => $provider->id.': Local', 'cron' => '* * * * *', 'timezone' => 'America/Los_Angeles', 'retention' => '2', 'schedule_enabled' => 'enabled']);
// submitLive already ran the first due schedule. Advance the clock twice.
$clock = Carbon\Carbon::now();
foreach ([1, 2] as $minutes) { Carbon\Carbon::setTestNow($clock->copy()->addMinutes($minutes)); Artisan::call('vito-pg:work'); }
Carbon\Carbon::setTestNow();
$schedule = BackupSchedule::onServer($server)->firstOrFail();
check(Backup::where('schedule_id', $schedule->id)->where('state', 'complete')->count() === 2 && Backup::where('schedule_id', $schedule->id)->where('state', 'pruned')->count() >= 1, 'Schedule timezone, execution and successful-backup retention work');
check($backup->fresh()->state === 'complete', 'Retention preserves the manual backup');

// Core MariaDB backup uses Vito's own handler and record types.
$coreProvider = App\Models\StorageProvider::create(['user_id' => $owner->id, 'project_id' => $project->id, 'profile' => 'Lab MariaDB Local', 'provider' => 'local', 'credentials' => ['path' => '/home/vito/backups']]);
$coreBackup = App\Models\Backup::create(['server_id' => $server->id, 'storage_id' => $coreProvider->id, 'database_id' => $mariaDb->id, 'type' => 'database', 'interval' => 'daily', 'keep_backups' => 2]);
$coreFile = App\Models\BackupFile::create(['backup_id' => $coreBackup->id, 'name' => $tag.'_native', 'status' => 'creating']);
$server->fresh()->database()->handler()->runBackup($coreFile);
check($coreFile->fresh()->database_engine === 'mariadb' && (int) $coreFile->fresh()->size > 0, 'Vito native MariaDB backup still uses MariaDB and produces data');
check(!str_contains(json_encode(Operation::all()->toArray()), $password) && !str_contains(json_encode(Operation::all()->toArray()), 'SCRAM-SHA-256'), 'Operation payloads and results contain no password or verifier');
$logs = '';
foreach (glob($host.'/storage/app/server-logs/*') ?: [] as $path) { if (is_file($path)) { $logs .= file_get_contents($path); } }
check(!str_contains($logs, $password) && !str_contains($logs, $newPassword), 'Vito server logs do not contain plugin plaintext passwords');
Lifecycle::disable();
Artisan::call('vito-pg:work');
check(probe(['database' => $tag, 'sql' => 'SELECT count(*) FROM before_grant'])['output'] === '1' && trim($server->ssh()->clearLog()->setLog(null)->exec('sudo mariadb -N -e "SELECT 1"')) === '1', 'Disabling plugin preserves both engines and application data');
app(App\Actions\Plugins\DisablePlugin::class)->handle($plugin->fresh());
app(App\Actions\Plugins\UninstallPlugin::class)->handle($plugin->fresh());
check(probe(['database' => $tag, 'sql' => 'SELECT count(*) FROM before_grant'])['output'] === '1' && $server->fresh()->database()->name === 'mariadb' && $backup->fresh()->state === 'complete', 'Actual Vito uninstall preserves live PostgreSQL data, MariaDB management and backup metadata');
echo 'LIVE INTEGRATION COMPLETE: '.$checks.' total assertions. Disposable dataset prefix: '.$tag.PHP_EOL;
