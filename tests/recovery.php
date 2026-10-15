<?php
/** Two separate requests: rejected 0.1.1 install, then update/install/enable on Vito 4.1.0.
 * Only GitHub metadata/download are replaced with a local archive; all Vito actions are real.
 * Run exclusively against a disposable Vito checkout. See docs/TESTING.md.
 */
$host = realpath($argv[1] ?? '') ?: throw new RuntimeException('Pass a disposable Vito checkout.');
$archive = realpath($argv[2] ?? '') ?: throw new RuntimeException('Pass the source archive for this phase.');
$run = $argv[3] ?? '';
$phase = $argv[4] ?? '';
if (!preg_match('/^[a-f0-9]{12}$/D', $run) || !in_array($phase, ['rejected', 'recovered'], true)) {
    throw new RuntimeException('Pass a fresh 12-character hex run ID and rejected/recovered phase.');
}
$db = 'vpgm-recovery-'.$run.'.sqlite';
foreach (['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'storage/app/temp'] as $dir) { @mkdir($host.'/'.$dir, 0777, true); }
if ($phase === 'rejected') {
    if (file_exists($host.'/storage/'.$db)) { throw new RuntimeException('Use a fresh run ID.'); }
    touch($host.'/storage/'.$db);
} elseif (!is_file($host.'/storage/'.$db)) { throw new RuntimeException('Run the rejected phase first.'); }
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)), 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'QUEUE_CONNECTION' => 'sync'] as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $value; }
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL); exit(1); });
Illuminate\Support\Facades\Event::forget(App\Events\SocketEvent::class);
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
    $checks++; echo 'PASS '.$message.PHP_EOL;
}
check(config('app.version') === '4.1.0', 'Recovery fixture is the real Vito 4.1.0 release');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$version = $phase === 'rejected' ? 'v0.1.1' : 'v0.1.2';
app()->instance(App\Actions\Plugins\Github\GetReleaseInfo::class, new class($version) extends App\Actions\Plugins\Github\GetReleaseInfo {
    public function __construct(private string $tag) {}
    public function handle(string $username, string $repo, string $version = 'latest'): ?App\DTOs\GitHub\ReleaseDto {
        $now = now();
        return new App\DTOs\GitHub\ReleaseDto(
            'https://api.github.com/repos/'.$username.'/'.$repo, $this->tag, $this->tag, false, false,
            $now, $now, $now, new App\DTOs\GitHub\AuthorDto($username, 'https://github.com/'.$username, 'Organization'),
            'https://example.test/source.tar', 'https://example.test/source.zip', 'Local installer regression fixture'
        );
    }
});
app()->instance(App\Actions\Plugins\Github\DownloadRelease::class, new class($archive) extends App\Actions\Plugins\Github\DownloadRelease {
    public function __construct(private string $archive) {}
    public function handle(App\DTOs\GitHub\ReleaseDto $release, string $location): void {
        Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($location));
        if (!copy($this->archive, $location)) { throw new RuntimeException('Unable to copy local fixture archive.'); }
    }
});
$url = 'https://github.com/techibytesmedia/vito-postgresql-manager';
if ($phase === 'rejected') {
    $blocked = false;
    try { app(App\Actions\Plugins\Github\InstallGithubPlugin::class)->handle($url); }
    catch (Exception $error) { $blocked = str_contains($error->getMessage(), 'Unable to install'); }
    check($blocked, 'Real 0.1.1 archive reproduces the installation failure');
    $plugin = App\Models\Plugin::where('repo', $url)->sole();
    check(!$plugin->is_installed && !$plugin->is_enabled && $plugin->version === 'v0.1.1', 'Failed installation preserves the Vito plugin row');
    check(str_contains($plugin->errors()->sole()->error_message, 'This build targets the reviewed Vito 4.1.1 APIs'), 'Original version guard caused the failure');
    check(!Illuminate\Support\Facades\Schema::hasTable('vpgm_settings'), 'Rejected 0.1.1 installation created no metadata');
} else {
    $plugin = App\Models\Plugin::where('repo', $url)->sole();
    check(!$plugin->is_installed && !$plugin->is_enabled, 'Recovery begins with the failed installation');
    app(App\Actions\Plugins\Github\CheckForUpdates::class)->handle();
    check($plugin->fresh()->updates_available, 'Check for updates includes a failed installation');
    app(App\Actions\Plugins\Github\UpdateGithubPlugin::class)->handle($plugin);
    check($plugin->fresh()->version === 'v0.1.2' && !$plugin->fresh()->is_installed, 'Vito updater replaces the source without marking it installed');
    check(App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Plugin::VERSION === '0.1.2', 'New request loads the updated implementation');
    app(App\Actions\Plugins\InstallPlugin::class)->handle($plugin);
    check($plugin->fresh()->is_installed && !$plugin->fresh()->is_enabled, 'Retry Install succeeds on Vito 4.1.0');
    app(App\Actions\Plugins\EnablePlugin::class)->handle($plugin);
    app(App\Actions\Plugins\BootPlugins::class)->handle();
    check($plugin->fresh()->is_enabled && App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\Lifecycle::enabled(), 'Recovered plugin enables successfully');
    check(count(config('server.features.vito-postgresql-manager.actions')) === 17, 'Recovered installation registers all 17 actions');
    check(App\Models\Plugin::where('repo', $url)->count() === 1, 'Recovery keeps one plugin record');
}
echo "Completed $checks recovery assertions ($phase), Vito ".config('app.version').', PHP '.PHP_VERSION.PHP_EOL;
