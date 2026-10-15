<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

abstract class Record extends Model
{
    protected $guarded = [];
    protected $casts = ['snapshot' => 'array', 'discovery' => 'array', 'payload' => 'array', 'metadata' => 'array', 'managed' => 'boolean', 'present' => 'boolean', 'enabled' => 'boolean'];

    public static function onServer(Server $server): Builder
    {
        return static::query()->where('project_id', $server->project_id)->where('server_id', $server->id);
    }
}
