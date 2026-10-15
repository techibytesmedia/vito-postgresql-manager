<?php
/** Real pinned Vito application tests, using its installer and policies. */
$root = dirname(__DIR__);
$host = realpath($argv[1] ?? '') ?: throw new RuntimeException('Pass the path to the pinned Vito checkout with vendor installed.');
$dbName = 'vpgm-test-'.bin2hex(random_bytes(6)).'.sqlite';
foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/app/temp'] as $dir) { @mkdir($host.'/'.$dir, 0777, true); }
touch($host.'/storage/'.$dbName);
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbName, 'QUEUE_CONNECTION' => 'sync'] as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $value; }
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); });
Illuminate\Support\Facades\Event::forget(App\Events\SocketEvent::class); // No websocket service in this test.
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $fn, string $class, string $message): void {
    try { $fn(); } catch (Throwable $e) { check($e instanceof $class, $message.' ('.get_class($e).')'); return; }
    check(false, $message);
}

$archive = $argv[2] ?? $root.'/dist/development.zip';
$pluginClass = App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Plugin::class;
$destination = $host.'/app/'.str_replace('\\', '/', substr($pluginClass, 4, -7));
app(App\Actions\Plugins\Github\ExtractPlugin::class)->handle($archive, $destination);
check(is_file($destination.'/Plugin.php') && is_file($destination.'/runtime/agent.py'), 'Vito extracts the GitHub-style source archive with runtime files');
app(App\Actions\Plugins\DiscoverPlugins::class)->handle();
$plugin = App\Models\Plugin::where('namespace', App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Plugin::class)->firstOrFail();
app(App\Actions\Plugins\InstallPlugin::class)->handle($plugin);
check($plugin->fresh()->is_installed && !$plugin->fresh()->is_enabled, 'Vito installation creates schema and leaves plugin disabled');
app(App\Actions\Plugins\EnablePlugin::class)->handle($plugin);
check($plugin->fresh()->is_enabled, 'Vito enable lifecycle succeeds');
app(App\Actions\Plugins\BootPlugins::class)->handle();
check(count(config('server.features.vito-postgresql-manager.actions')) === 17, 'All 17 native actions register through Vito');
check(collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn ($e) => str_contains($e->command ?? '', 'vito-pg:work')), 'Plugin worker registered with Laravel scheduler');

use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\{Cluster, Resource, Operation, BackupSchedule};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\{Input, Lifecycle};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Actions\{CreateDatabase, DeleteDatabase};

$owner = App\Models\User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('only-test-password'), 'timezone' => 'UTC']);
$other = App\Models\User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => bcrypt('only-test-password'), 'timezone' => 'UTC']);
$project = App\Models\Project::create(['name' => 'Plugin tests']);
$project2 = App\Models\Project::create(['name' => 'Other project']);
Illuminate\Support\Facades\DB::table('user_project')->insert(['user_id' => $owner->id, 'project_id' => $project->id, 'role' => 'owner']);
$server = App\Models\Server::create(['project_id' => $project->id, 'user_id' => $owner->id, 'name' => 'Disposable', 'ip' => '127.0.0.1', 'port' => 22, 'os' => 'ubuntu_24', 'provider' => 'custom', 'ssh_user' => 'vito', 'status' => 'ready']);
$server2 = App\Models\Server::create(['project_id' => $project2->id, 'user_id' => $other->id, 'name' => 'Other', 'ip' => '127.0.0.2', 'port' => 22, 'os' => 'ubuntu_24', 'provider' => 'custom', 'ssh_user' => 'vito', 'status' => 'ready']);
auth()->login($owner);
$session = app('session')->driver();
function requestFor(array $payload, $user): Illuminate\Http\Request {
    global $session;
    $r = Illuminate\Http\Request::create('/', 'POST', $payload);
    $r->setUserResolver(fn () => $user); $r->setLaravelSession($session); return $r;
}
$c = Cluster::create(['project_id' => $project->id, 'server_id' => $server->id, 'major' => '18', 'name' => 'main', 'port' => 5432, 'snapshot' => ['identity' => ['status' => 'down']]]);
$foreign = Cluster::create(['project_id' => $project2->id, 'server_id' => $server2->id, 'major' => '18', 'name' => 'main', 'port' => 5432]);
$role = Resource::create(['project_id' => $project->id, 'server_id' => $server->id, 'cluster_id' => $c->id, 'kind' => 'role', 'name' => 'same-name', 'metadata' => []]);
$db = Resource::create(['project_id' => $project->id, 'server_id' => $server->id, 'cluster_id' => $c->id, 'kind' => 'database', 'name' => 'same-name', 'metadata' => []]);
$foreignRole = Resource::create(['project_id' => $project2->id, 'server_id' => $server2->id, 'cluster_id' => $foreign->id, 'kind' => 'role', 'name' => 'same-name', 'metadata' => []]);
foreach ($server->features()['vito-postgresql-manager']['actions'] as $slug => $action) {
    check($action['active'] === true, 'Native menu action is enabled: '.$slug);
    check(collect($action['form'])->every(fn ($f) => in_array($f['type'], ['text', 'password', 'select', 'alert'], true)), 'Supported native controls in '.$slug);
}
check(true, 'Offline cluster form generation succeeds without SSH');
$payload = ['token' => (string) Illuminate\Support\Str::uuid(), 'cluster' => $c->id.': main', 'owner' => $role->id.': same-name', 'name' => 'edge-";$(touch nope)', 'encoding' => 'UTF8', 'locale' => 'C.utf8'];
(new CreateDatabase($server))->handle(requestFor($payload, $owner));
(new CreateDatabase($server))->handle(requestFor($payload, $owner));
check(Operation::where('token', $payload['token'])->count() === 1, 'Duplicate submissions produce one durable operation');
rejects(fn () => (new CreateDatabase($server))->handle(requestFor([...$payload, 'token' => (string) Illuminate\Support\Str::uuid(), 'cluster' => $foreign->id.': other'], $owner)), Illuminate\Validation\ValidationException::class, 'Tampered foreign-project cluster rejected');
rejects(fn () => (new CreateDatabase($server))->handle(requestFor([...$payload, 'token' => (string) Illuminate\Support\Str::uuid(), 'owner' => $foreignRole->id.': other'], $owner)), Illuminate\Validation\ValidationException::class, 'Tampered foreign-project role rejected');
auth()->login($other);
rejects(fn () => (new CreateDatabase($server))->handle(requestFor($payload, $other)), Illuminate\Auth\Access\AuthorizationException::class, 'Unauthorized server access rejected by real Vito policy');
auth()->login($owner);
rejects(fn () => (new DeleteDatabase($server))->handle(requestFor(['token' => (string) Illuminate\Support\Str::uuid(), 'cluster' => $c->id.': main', 'database' => $db->id.': same-name', 'confirmation' => 'yes'], $owner)), Illuminate\Validation\ValidationException::class, 'Destructive operation requires exact server/cluster/name confirmation');
check(Input::name(str_repeat('é', 31)) === str_repeat('é', 31), 'UTF-8 byte limit permits 62-byte identifier');
rejects(fn () => Input::name(str_repeat('é', 32)), Illuminate\Validation\ValidationException::class, 'UTF-8 byte limit rejects 64-byte identifier');
rejects(fn () => Input::name("bad\nname"), Illuminate\Validation\ValidationException::class, 'Control characters rejected');
$secret = "pass ' \" $ ; \\ % ! 1234";
check(!str_contains(Input::verifier($secret), $secret), 'SCRAM verifier never includes plaintext');
check(Input::shell("a'b") === "'a'\"'\"'b'", 'POSIX shell quoting independent of Windows host');
$privateProvider = App\Models\StorageProvider::create(['user_id' => $other->id, 'project_id' => $project2->id, 'profile' => 'Other private storage', 'provider' => 'local', 'credentials' => ['path' => '/tmp']]);
rejects(fn () => app(App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services\Storage::class)->provider($server, $owner, $privateProvider->id), Illuminate\Database\Eloquent\ModelNotFoundException::class, 'Foreign project/user storage cannot be selected');
$record = Operation::where('token', $payload['token'])->firstOrFail();
$record->update(['user_id' => $other->id]);
rejects(fn () => app(App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services\Manager::class)->run($record), Illuminate\Auth\Access\AuthorizationException::class, 'Execution rechecks current user authorization before SSH');
$record->update(['user_id' => $owner->id]);
$dst = BackupSchedule::create(['project_id' => $project->id, 'server_id' => $server->id, 'cluster_id' => $c->id, 'resource_id' => $db->id,
    'storage_id' => $privateProvider->id, 'user_id' => $owner->id, 'cron' => '* * * * *', 'timezone' => 'America/Los_Angeles', 'retention' => 2, 'enabled' => true]);
$method = new ReflectionMethod(App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Console\WorkCommand::class, 'schedule');
foreach (['2026-11-01T01:00:00-07:00', '2026-11-01T01:01:00-07:00', '2026-11-01T01:00:00-08:00', '2026-11-01T01:01:00-08:00'] as $instant) {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse($instant));
    // Cancel pending test records so each schedule calculation can be examined.
    Operation::where('action', 'backup')->update(['state' => 'failed']);
    $method->invoke(app(App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Console\WorkCommand::class));
}
Carbon\Carbon::setTestNow();
check(Operation::whereNotNull('schedule_key')->count() === 2, 'Repeated DST minutes are durably deduplicated for every-minute schedules');
$dst->delete();
$snapshot = $c->snapshot;
app(App\Actions\Plugins\DisablePlugin::class)->handle($plugin->fresh());
check(!Lifecycle::enabled() && Operation::where('state', 'pending')->count() === 0, 'Disable cancels pending work and preserves metadata');
check($c->fresh()->snapshot === $snapshot, 'Disable preserves inventory');
$guard = Illuminate\Support\Facades\Cache::lock('vpgm-lifecycle', 4000); $guard->get();
rejects(fn () => app(App\Actions\Plugins\UninstallPlugin::class)->handle($plugin->fresh()), Exception::class, 'Vito uninstall refuses while a worker is active');
$guard->release();
app(App\Actions\Plugins\UninstallPlugin::class)->handle($plugin->fresh());
check(!is_dir($destination) && Cluster::find($c->id) !== null, 'Real Vito uninstall removes code and preserves metadata');
app(App\Actions\Plugins\Github\ExtractPlugin::class)->handle($archive, $destination);
app(App\Actions\Plugins\DiscoverPlugins::class)->handle();
$plugin = App\Models\Plugin::where('namespace', App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Plugin::class)->firstOrFail();
app(App\Actions\Plugins\InstallPlugin::class)->handle($plugin);
app(App\Actions\Plugins\EnablePlugin::class)->handle($plugin);
check(Lifecycle::enabled() && Cluster::find($c->id) !== null, 'Real reinstall and enable preserve previous adoption');
Lifecycle::disable(); Lifecycle::migrate(); Lifecycle::enable();
check(Lifecycle::enabled() && Cluster::find($c->id) !== null, 'Documented explicit migration path preserves metadata');
echo "Completed $checks host assertions on Vito ".config('app.version').', PHP '.PHP_VERSION.PHP_EOL;
echo 'Isolated database: '.$host.'/storage/'.$dbName.PHP_EOL;
if (($argv[3] ?? '') === '--integration') { require __DIR__.'/integration/scenario.php'; }
