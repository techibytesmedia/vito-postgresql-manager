<?php
/** Seed/check application fixture data only in the named disposable UI lab. */
$root = dirname(__DIR__);
$host = $root.'/.work/vito-8598e4dc0bce12d2b2f915766a100b445b7d9e22';
require $host.'/vendor/autoload.php';
$app = require $host.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); });
if (basename(config('database.connections.sqlite.database')) !== 'vpgm-browser.sqlite') { throw new RuntimeException('This command is only for the browser-demo database.'); }
$server = App\Models\Server::where('name', 'Local Ubuntu Lab')->firstOrFail();
if ($server->ip !== trim(file_get_contents($root.'/.work/lab-ip')) || $server->port !== 22287) { throw new RuntimeException('Disposable lab identity mismatch.'); }
function queryDemo($server, string $db, string $sql): string {
    $quote = fn ($v) => "'".str_replace("'", "'\"'\"'", $v)."'";
    return trim($server->ssh()->clearLog()->setLog(null)->exec('sudo -n -u postgres /usr/lib/postgresql/18/bin/psql -XAtq --no-password -h /var/run/postgresql -p 55487 --dbname='.$quote($db).' -v ON_ERROR_STOP=1 --command='.$quote($sql), timeout: 30));
}
if (($argv[1] ?? '') === 'seed') {
    queryDemo($server, 'browser_demo', 'SET ROLE "browser_demo_owner"; CREATE TABLE IF NOT EXISTS demo_messages(id integer PRIMARY KEY, message text NOT NULL); INSERT INTO demo_messages VALUES (1, \'Created during the local browser demo\') ON CONFLICT (id) DO NOTHING;');
    echo 'Seeded one application-owned demo row for the browser backup/restore test.'.PHP_EOL;
} else {
    $source = queryDemo($server, 'browser_demo', 'SELECT message FROM demo_messages ORDER BY id');
    $restored = queryDemo($server, 'browser_demo_restored', 'SELECT message FROM demo_messages ORDER BY id');
    if ($source !== $restored || $source === '') { throw new RuntimeException('Restored data differs from source.'); }
    echo json_encode(['restored_data_matches' => true, 'message' => $restored, 'default_engine' => $server->fresh()->database()->name], JSON_PRETTY_PRINT).PHP_EOL;
}
