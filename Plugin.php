<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterCommand;
use App\Plugins\RegisterServerFeature;
use App\Plugins\RegisterServerFeatureAction;
use Illuminate\Console\Scheduling\Schedule;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Actions\FeatureAction;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Console\WorkCommand;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\Lifecycle;

class Plugin extends AbstractPlugin
{
    public const VERSION = '0.1.3';
    public const FEATURE = 'vito-postgresql-manager';
    protected string $name = 'PostgreSQL Manager';
    protected string $description = 'Manage explicitly adopted PostgreSQL clusters alongside Vito’s existing MariaDB management.';

    public function boot(): void
    {
        // Registration only: no migrations, SSH, discovery, or server mutations.
        $actions = config('server.features.'.self::FEATURE.'.actions', []);
        // Laravel's config cache includes the feature and actions from an earlier boot.
        $ownsFeature = ($actions['status']['handler'] ?? null) === FeatureAction::ACTIONS['status'][1];
        if (!$ownsFeature) {
            RegisterServerFeature::make(self::FEATURE)->label($this->name)->description($this->description)->register();
        }
        foreach (FeatureAction::ACTIONS as $slug => [$label, $handler]) {
            if (($actions[$slug]['handler'] ?? null) !== $handler) {
                RegisterServerFeatureAction::make(self::FEATURE, $slug)->label($label)->handler($handler)->register();
            }
        }
        if (!in_array(WorkCommand::class, RegisterCommand::get(), true)) {
            RegisterCommand::make(WorkCommand::class)->register();
        }
        app(Schedule::class)->command('vito-pg:work')->everyMinute()->withoutOverlapping(65)->runInBackground();
    }

    public function install(): void { Lifecycle::migrate(); }
    public function enable(): void { Lifecycle::enable(); }
    public function disable(): void { Lifecycle::disable(); }
    public function uninstall(): void { Lifecycle::uninstall(); }
}
