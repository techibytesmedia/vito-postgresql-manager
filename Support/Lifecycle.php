<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support;

use App\DTOs\{DynamicField, DynamicForm};
use App\Models\Server;
use App\Plugins\{RegisterCommand, RegisterServerFeature, RegisterServerFeatureAction};
use App\ServerFeatures\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class Lifecycle
{
    public const SCHEMA = 1;

    private static function requirements(): void
    {
        if (PHP_VERSION_ID < 80400) { throw new RuntimeException('PHP 8.4 or later is required.'); }
        $apis = [
            RegisterServerFeature::class => ['make', 'label', 'description', 'register'],
            RegisterServerFeatureAction::class => ['make', 'label', 'handler', 'register'],
            RegisterCommand::class => ['make', 'register', 'get'],
            Action::class => ['__construct'],
            DynamicField::class => ['make', 'alert', 'text', 'password', 'select', 'label', 'description', 'default', 'options'],
            DynamicForm::class => ['make'],
            Server::class => ['features', 'ssh'],
        ];
        foreach ($apis as $class => $methods) {
            foreach ($methods as $method) {
                if (!method_exists($class, $method)) {
                    throw new RuntimeException('Incompatible Vito plugin API: missing '.$class.'::'.$method.'().');
                }
            }
        }
    }
    public static function ready(): bool
    {
        return Schema::hasTable('vpgm_settings') && (int) DB::table('vpgm_settings')->where('id', 1)->value('schema_version') === self::SCHEMA;
    }
    public static function enabled(): bool
    {
        return self::ready() && (bool) DB::table('vpgm_settings')->where('id', 1)->value('enabled');
    }
    public static function migrate(): void
    {
        self::requirements();
        $lock = Cache::lock('vpgm-lifecycle', 4000);
        if (!$lock->get()) { throw new RuntimeException('PostgreSQL worker is active; wait for it before installing or migrating.'); }
        try {
            if (Schema::hasTable('vpgm_settings') && (int) DB::table('vpgm_settings')->where('id', 1)->value('schema_version') > self::SCHEMA) {
                throw new RuntimeException('Metadata is newer than this plugin. Restore the matching plugin version.');
            }
            (require dirname(__DIR__).'/database/migrations/001_metadata.php')->up();
            DB::table('vpgm_settings')->updateOrInsert(['id' => 1], ['schema_version' => self::SCHEMA, 'enabled' => false]);
        } finally { $lock->release(); }
    }
    public static function enable(): void
    {
        self::requirements();
        if (!self::ready()) { self::migrate(); }
        DB::table('vpgm_settings')->where('id', 1)->update(['enabled' => true]);
    }
    public static function disable(): void
    {
        // Remains usable even if a later migration failed.
        if (Schema::hasTable('vpgm_settings')) { DB::table('vpgm_settings')->where('id', 1)->update(['enabled' => false]); }
        if (Schema::hasTable('vpgm_operations')) { DB::table('vpgm_operations')->where('state', 'pending')->update(['state' => 'cancelled', 'result' => 'Plugin disabled before execution.', 'finished_at' => now()]); }
    }
    public static function uninstall(): void
    {
        self::disable();
        $lock = Cache::lock('vpgm-lifecycle', 4000);
        if (!$lock->get()) { throw new RuntimeException('A PostgreSQL operation is running. Wait for completion before uninstalling. Do not force uninstall.'); }
        try {
            // There are no serialized plugin jobs in Laravel/Redis queues. The
            // durable work table is cancelled and preserved along with metadata.
        } finally { $lock->release(); }
    }
}
