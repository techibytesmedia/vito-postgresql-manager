<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vpgm_settings')) {
            Schema::create('vpgm_settings', function (Blueprint $t) {
                $t->id(); $t->unsignedInteger('schema_version')->default(0); $t->boolean('enabled')->default(false);
            });
        }
        if (!Schema::hasTable('vpgm_servers')) {
            Schema::create('vpgm_servers', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('server_id');
                $t->json('discovery')->nullable(); $t->timestamps(); $t->unique(['project_id', 'server_id']);
            });
        }
        if (!Schema::hasTable('vpgm_clusters')) {
            Schema::create('vpgm_clusters', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('server_id');
                $t->string('major', 4); $t->string('name', 63); $t->unsignedInteger('port');
                $t->json('snapshot')->nullable(); $t->timestamps(); $t->unique(['project_id', 'server_id', 'major', 'name'], 'vpgm_cluster_identity');
            });
        }
        if (!Schema::hasTable('vpgm_resources')) {
            Schema::create('vpgm_resources', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('cluster_id');
                $t->string('kind', 16); $t->string('name', 63); $t->boolean('managed')->default(false); $t->boolean('present')->default(true);
                $t->json('metadata'); $t->timestamps(); $t->unique(['cluster_id', 'kind', 'name'], 'vpgm_resource_identity');
            });
        }
        if (!Schema::hasTable('vpgm_operations')) {
            Schema::create('vpgm_operations', function (Blueprint $t) {
                $t->id(); $t->uuid('token')->unique(); $t->string('schedule_key')->nullable()->unique(); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('server_id');
                $t->unsignedBigInteger('cluster_id')->nullable(); $t->unsignedBigInteger('user_id');
                $t->string('action', 40); $t->string('state', 16)->default('pending'); $t->json('payload');
                $t->text('result')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable();
                $t->timestamps(); $t->index(['state', 'id']);
            });
        }
        if (!Schema::hasTable('vpgm_schedules')) {
            Schema::create('vpgm_schedules', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('cluster_id');
                $t->unsignedBigInteger('resource_id'); $t->unsignedBigInteger('storage_id'); $t->unsignedBigInteger('user_id');
                $t->string('cron'); $t->string('timezone'); $t->unsignedInteger('retention'); $t->boolean('enabled')->default(true);
                $t->string('last_slot')->nullable(); $t->timestamps();
                $t->unique(['cluster_id', 'resource_id'], 'vpgm_schedule_target');
            });
        }
        if (!Schema::hasTable('vpgm_backups')) {
            Schema::create('vpgm_backups', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('cluster_id');
                $t->unsignedBigInteger('resource_id'); $t->unsignedBigInteger('storage_id'); $t->unsignedBigInteger('schedule_id')->nullable();
                $t->string('artifact', 32)->unique(); $t->string('database_name', 63); $t->string('engine')->default('postgresql');
                $t->string('format')->default('custom'); $t->string('major', 4); $t->string('state', 16);
                $t->json('metadata')->nullable(); $t->text('result')->nullable(); $t->timestamps();
            });
        }
    }
    public function down(): void { /* Metadata and artifacts deliberately survive uninstall. */ }
};
