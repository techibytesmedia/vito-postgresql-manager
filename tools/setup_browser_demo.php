<?php
/** Configure only the disposable, pinned checkout for a persistent local UI demo. */
$root = dirname(__DIR__);
$host = realpath($root.'/.work/vito-8598e4dc0bce12d2b2f915766a100b445b7d9e22');
if (!$host || !is_file($host.'/vendor/autoload.php')) { throw new RuntimeException('The pinned disposable Vito checkout and its dependencies are required.'); }
if (!is_file($root.'/.work/lab-key') || !is_file($root.'/.work/lab-ip')) { throw new RuntimeException('Start the disposable integration lab first.'); }
foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/app/temp'] as $dir) { @mkdir($host.'/'.$dir, 0777, true); }
$oldEnv = is_file($host.'/.env') ? file_get_contents($host.'/.env') : '';
$key = preg_match('/^APP_KEY=(.+)$/m', $oldEnv, $m) ? trim($m[1]) : 'base64:'.base64_encode(random_bytes(32));
if ($key === '') { $key = 'base64:'.base64_encode(random_bytes(32)); }
$settings = ['APP_NAME' => 'Vito Local Plugin Demo', 'APP_ENV' => 'local', 'APP_KEY' => $key, 'APP_DEBUG' => 'true',
    'APP_URL' => 'http://127.0.0.1:8787', 'FORCE_HTTPS' => 'false', 'APP_DEMO' => 'false',
    'WS_URL' => 'http://127.0.0.1:8788', 'WS_PORT' => '8788', 'WS_ALLOWED_ORIGINS' => 'http://127.0.0.1:8787',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'vpgm-browser.sqlite', 'CACHE_DRIVER' => 'file',
    'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'log', 'LOG_CHANNEL' => 'single'];
$envText = '';
foreach ($settings as $name => $value) {
    $envText .= $name.'='.(str_contains($value, ' ') ? '"'.$value.'"' : $value).PHP_EOL;
    putenv($name.'='.$value); $_ENV[$name] = $value;
}
file_put_contents($host.'/.env', $envText);
if (!is_file($host.'/storage/vpgm-browser.sqlite')) { touch($host.'/storage/vpgm-browser.sqlite'); }
// Prepopulate Vito's normal key files using the disposable lab key; avoid its
// Linux-only key-generation shell commands on this Windows development host.
file_put_contents($host.'/storage/ssh-private.pem', file_get_contents($root.'/.work/lab-key'));
file_put_contents($host.'/storage/ssh-public.key', file_get_contents($root.'/.work/lab-key.pub'));
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); });
Illuminate\Support\Facades\Event::forget(App\Events\SocketEvent::class);
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$user = App\Models\User::firstOrNew(['email' => 'demo@vito.local']);
$user->fill(['name' => 'Local Demo', 'password' => bcrypt('VitoLocalDemo!2026'), 'timezone' => 'America/Los_Angeles', 'is_admin' => true]);
$user->save();
$project = App\Models\Project::firstOrCreate(['name' => 'PostgreSQL Plugin Demo']);
Illuminate\Support\Facades\DB::table('user_project')->updateOrInsert(['user_id' => $user->id, 'project_id' => $project->id], ['role' => 'owner']);
$user->update(['current_project_id' => $project->id]);
$server = App\Models\Server::updateOrCreate(['project_id' => $project->id, 'name' => 'Local Ubuntu Lab'],
    ['user_id' => $user->id, 'ip' => trim(file_get_contents($root.'/.work/lab-ip')), 'port' => 22287, 'os' => 'ubuntu_24', 'provider' => 'custom', 'ssh_user' => 'vito', 'status' => 'ready', 'progress' => 100]);
Illuminate\Support\Facades\Storage::disk(config('core.key_pairs_disk'))->put((string) $server->id, file_get_contents($root.'/.work/lab-key'));
Illuminate\Support\Facades\Storage::disk(config('core.key_pairs_disk'))->put($server->id.'.pub', file_get_contents($root.'/.work/lab-key.pub'));
App\Models\Service::updateOrCreate(['server_id' => $server->id, 'name' => 'mariadb'], ['type' => 'database', 'version' => '10.11', 'status' => 'ready', 'is_default' => true]);
App\Models\StorageProvider::updateOrCreate(['user_id' => $user->id, 'project_id' => $project->id, 'profile' => 'PostgreSQL lab backups'], ['provider' => 'local', 'credentials' => ['path' => '/var/lib/postgresql']]);
$class = App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Plugin::class;
$destination = $host.'/app/'.str_replace('\\', '/', substr($class, 4, -7));
$record = App\Models\Plugin::where('namespace', $class)->first();
if ($record?->is_enabled) { app(App\Actions\Plugins\DisablePlugin::class)->handle($record); }
app(App\Actions\Plugins\Github\ExtractPlugin::class)->handle($argv[1] ?? $root.'/dist/browser-demo.zip', $destination);
app(App\Actions\Plugins\DiscoverPlugins::class)->handle();
$record = App\Models\Plugin::where('namespace', $class)->firstOrFail();
if (!$record->is_installed) { app(App\Actions\Plugins\InstallPlugin::class)->handle($record); }
app(App\Actions\Plugins\EnablePlugin::class)->handle($record);
Illuminate\Support\Facades\Cache::forget('active-plugins');
echo json_encode(['url' => 'http://127.0.0.1:8787/servers/'.$server->id.'/features', 'email' => $user->email,
    'server_id' => $server->id, 'database' => 'storage/vpgm-browser.sqlite', 'plugin' => 'Installed and enabled; discover/adopt through the browser.'], JSON_PRETTY_PRINT).PHP_EOL;
