<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Actions;

use App\DTOs\{DynamicField as Field, DynamicForm};
use App\Models\StorageProvider;
use App\ServerFeatures\Action;
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Models\{Backup, BackupSchedule, Cluster, Operation, Resource, ServerState};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Services\{Manager, Remote, RemoteFailure, Storage};
use App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support\{Input, Lifecycle};
use Cron\CronExpression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

abstract class FeatureAction extends Action
{
    protected const OP = '';
    public const ACTIONS = [
        'status' => ['Status, inventory & operation history', Status::class],
        'discover' => ['Discover installed clusters', Discover::class],
        'adopt' => ['Adopt an existing cluster', Adopt::class],
        'refresh' => ['Refresh cluster status & inventory', Refresh::class],
        'create-login' => ['Create application login', CreateLogin::class],
        'reset-password' => ['Reset login password', ResetPassword::class],
        'delete-login' => ['Delete application login', DeleteLogin::class],
        'create-database' => ['Create PostgreSQL database', CreateDatabase::class],
        'delete-database' => ['Delete PostgreSQL database', DeleteDatabase::class],
        'manage-database' => ['Enable access management for a database', ManageDatabase::class],
        'ownership' => ['Change database owner', Ownership::class],
        'access' => ['Grant read or write access', Access::class],
        'connection' => ['Show application connection details', Connection::class],
        'service' => ['Start, stop or restart selected cluster', Service::class],
        'backup' => ['Back up PostgreSQL database', CreateBackup::class],
        'restore' => ['Restore backup into a NEW database', Restore::class],
        'schedule' => ['Configure scheduled backup & retention', ScheduleBackup::class],
    ];

    public function name(): string { return static::OP; }
    public function active(): bool { return Lifecycle::enabled(); }
    private function choice(string $name, string $label, array $options): Field
    {
        return Field::make($name)->select()->label($label)->options($options);
    }
    private function inventory(string $kind): array
    {
        $clusters = Cluster::onServer($this->server)->get()->keyBy('id');
        return Resource::onServer($this->server)->where('kind', $kind)->where('present', true)->orderBy('name')->get()->map(function ($r) use ($clusters) {
            $c = $clusters->get($r->cluster_id);
            return $r->id.': '.$r->name.' ['.$c?->major.'/'.$c?->name.']';
        })->all();
    }

    public function form(): ?DynamicForm
    {
        $fields = [Field::make('target_info')->alert()->label('Server: '.$this->server->name.' (#'.$this->server->id.')')->description('PostgreSQL actions affect only the explicitly selected cluster. MariaDB stays on Vito’s existing database screens.')];
        if (!Lifecycle::ready()) {
            $fields[] = Field::make('schema')->alert()->label('Metadata upgrade required')->description('Disable and re-enable this plugin to migrate its metadata. No SSH is performed while rendering this page.');
            return DynamicForm::make($fields);
        }
        if (static::OP === 'status') { return DynamicForm::make([...$fields, ...$this->statusFields()]); }
        $op = static::OP;
        $fields[] = Field::make('token')->text()->label('Request token (leave unchanged)')->default((string) Str::uuid())->description('Prevents a repeated submission from executing twice. Reopen this dialog for a new request.');
        if ($op === 'discover') { return DynamicForm::make($fields); }
        if ($op === 'adopt') {
            $discovery = ServerState::onServer($this->server)->first()?->discovery ?? [];
            $options = [];
            foreach ($discovery as $i => $c) { $options[] = ($i + 1).': '.$c['major'].'/'.$c['cluster'].' :'.$c['port'].' ('.$c['status'].', bind: '.($c['bind'] ?? $c['configured_bind'] ?? 'unknown').')'; }
            $fields[] = $this->choice('discovered', 'Discovered cluster (run Discover first)', $options);
            return DynamicForm::make($fields);
        }
        $fields[] = $this->choice('cluster', 'Target PostgreSQL cluster', Cluster::onServer($this->server)->get()->map(fn ($c) => $c->id.': '.$c->major.'/'.$c->name.' :'.$c->port)->all());
        if (in_array($op, ['delete_database', 'manage_database', 'ownership', 'access', 'connection', 'backup', 'schedule'], true)) {
            $fields[] = $this->choice('database', 'Database (must belong to the selected cluster)', $this->inventory('database'));
        }
        if (in_array($op, ['reset_password', 'delete_role', 'access', 'connection'], true)) {
            $fields[] = $this->choice('role', 'Application login (must belong to the selected cluster)', $this->inventory('role'));
        }
        if (in_array($op, ['create_database', 'ownership', 'restore'], true)) {
            $fields[] = $this->choice('owner', 'Application owner (must belong to the selected cluster)', $this->inventory('role'));
        }
        if (in_array($op, ['create_role', 'create_database', 'restore'], true)) {
            $fields[] = Field::make('name')->text()->label($op === 'create_role' ? 'New login name' : 'New database name')->description('1–63 UTF-8 bytes; system names are protected. Restore requires a name that does not already exist.');
        }
        if (in_array($op, ['create_role', 'reset_password'], true)) {
            $fields[] = Field::make('password')->password()->label('New password')->description('12–1024 printable ASCII characters. Sent once; never retained. New logins have no administrative privileges.');
        }
        if (in_array($op, ['create_database', 'restore'], true)) {
            $fields[] = $this->choice('encoding', 'PostgreSQL encoding', ['UTF8', 'LATIN1'])->default('UTF8');
            $fields[] = Field::make('locale')->text()->label('Installed locale')->default('C.utf8')->description('Use an exact installed locale from Status. Encoding and locale must be compatible. Databases use template0.');
        }
        if ($op === 'access') {
            $fields[] = $this->choice('creator', 'Role that creates future objects', $this->inventory('role'));
            $fields[] = $this->choice('level', 'Additive application access', ['read', 'write'])->default('read');
            $fields[] = Field::make('access_info')->alert()->label('Scope of grants')->description('Requires explicit access management. Read: schema usage, table SELECT, sequence SELECT. Write adds table INSERT/UPDATE/DELETE and sequence USAGE/UPDATE, without DDL. Defaults apply to the database owner and selected creator, including new schemas. Repeat for every additional creator. This action adds grants; it does not downgrade existing access or restrict PUBLIC grants.');
        }
        if ($op === 'ownership') {
            $fields[] = Field::make('ownership_info')->alert()->label('Database ownership only')->description('Existing schema, table, sequence and routine owners remain unchanged. Their dependencies must be resolved explicitly before deleting a login.');
        }
        if ($op === 'service') { $fields[] = $this->choice('control', 'Cluster-specific service operation', ['start', 'stop', 'restart']); }
        if (in_array($op, ['backup', 'schedule'], true)) {
            $providers = auth()->user() ? StorageProvider::getByProjectId($this->server->project_id, auth()->user())->whereIn('provider', ['local', 's3'])->get() : collect();
            $fields[] = $this->choice('storage', 'Existing Vito storage provider', $providers->map(fn ($s) => $s->id.': '.$s->profile.' ('.$s->provider.')')->all());
        }
        if ($op === 'restore') {
            $fields[] = $this->choice('backup', 'Completed PostgreSQL backup from this server', Backup::onServer($this->server)->where('state', 'complete')->orderByDesc('id')->get()->map(fn ($b) => $b->id.': '.$b->database_name.' [cluster #'.$b->cluster_id.', PG '.$b->major.', '.$b->created_at.']')->all());
            $fields[] = Field::make('restore_info')->alert()->label('Restore runs SQL from this backup')->description('Restore only trusted plugin backups. Creates a NEW database; restores without source ownership or ACLs. A failed restore may leave the new database for inspection. Existing databases are never replaced.');
        }
        if ($op === 'schedule') {
            $fields[] = Field::make('cron')->text()->label('Cron schedule')->default('0 2 * * *');
            $fields[] = Field::make('timezone')->text()->label('IANA timezone')->default(auth()->user()?->timezone ?: 'UTC');
            $fields[] = Field::make('retention')->text()->label('Keep this schedule’s most recent successful backups')->default('7');
            $fields[] = $this->choice('schedule_enabled', 'Schedule state', ['enabled', 'disabled'])->default('enabled');
        }
        if (in_array($op, ['delete_database', 'delete_role', 'service', 'restore', 'ownership', 'manage_database'], true)) {
            $impact = match ($op) {
                'delete_database', 'delete_role' => 'Deletion permanently removes the selected resource.',
                'service' => 'Stop and restart interrupt connections to this cluster.',
                'restore' => 'Restore creates a NEW database and preserves existing databases.',
                'ownership' => 'This changes the database owner while preserving existing schema and object owners.',
                default => 'This enables explicit access management without changing existing grants.',
            };
            $fields[] = Field::make('confirmation')->text()->label('Confirmation')->description('Type exactly: server #'.$this->server->id.' / cluster #ID / RESOURCE. Replace ID with the selected cluster ID and RESOURCE with the database/login name, service operation, or NEW restore database name. '.$impact);
        }
        return DynamicForm::make($fields);
    }

    private function statusFields(): array
    {
        $fields = [Field::make('queue_info')->alert()->label('Operation queue')->description('Work runs through Vito’s scheduler once per minute, one operation at a time. Reload the Features page, then open this dialog to see new results and selection lists. Inventory is cached until an explicit cluster refresh. '.(Lifecycle::enabled() ? 'Plugin enabled.' : 'Plugin disabled.'))];
        foreach (Cluster::onServer($this->server)->get() as $c) {
            $snapshot = $c->snapshot ?? [];
            $fields[] = Field::make('cluster_'.$c->id)->alert()->label('#'.$c->id.' — '.$c->major.'/'.$c->name.' :'.$c->port.' — '.($snapshot['identity']['status'] ?? 'unknown'))->description('Refreshed '.$c->updated_at.'. Bind: '.($snapshot['bind'] ?? 'unknown while offline').'. Local sockets: '.($snapshot['socket'] ?? 'unknown').'. '.($snapshot['server_version'] ?? ''));
            $fields[] = Field::make('locales_'.$c->id)->alert()->label('Installed locales — cluster #'.$c->id)->description(implode(', ', $snapshot['locales'] ?? []));
            foreach ($snapshot['hba'] ?? [] as $i => $rule) {
                $fields[] = Field::make('hba_'.$c->id.'_'.$i)->alert()->label('Authentication rule '.($i + 1).' — cluster #'.$c->id)->description(($rule['type'] ?? 'invalid').' | databases: '.implode(', ', $rule['database'] ?? []).' | users: '.implode(', ', $rule['user_name'] ?? []).' | address: '.($rule['address'] ?? 'local socket').' | method: '.($rule['auth_method'] ?? 'invalid').($rule['error'] ? ' | configuration error' : ''));
            }
        }
        foreach (Resource::onServer($this->server)->where('present', true)->orderBy('cluster_id')->orderBy('kind')->get() as $r) {
            $details = $r->kind === 'database'
                ? ($r->managed ? 'Access management enabled. ' : 'Imported; grants preserved. ').'Owner: '.($r->metadata['owner'] ?? 'unknown').'. Encoding: '.($r->metadata['encoding'] ?? 'unknown').'. Locale: '.($r->metadata['locale'] ?? 'unknown').'.'
                : 'Administrative role flags: '.(($r->metadata['privileged'] ?? false) ? 'present (protected from changes)' : 'none').'.';
            $fields[] = Field::make('resource_'.$r->id)->alert()->label(ucfirst($r->kind).' #'.$r->id.': '.$r->name.' (cluster #'.$r->cluster_id.')')->description($details);
        }
        foreach (Operation::onServer($this->server)->orderByDesc('id')->limit(25)->get() as $o) {
            $fields[] = Field::make('operation_'.$o->id)->alert()->label('Operation #'.$o->id.' — '.$o->action.' — '.$o->state)->description('Cluster #'.($o->cluster_id ?: 'discovery').', '.$o->updated_at.': '.$o->result);
        }
        foreach (Backup::onServer($this->server)->orderByDesc('id')->limit(25)->get() as $b) {
            $fields[] = Field::make('backup_'.$b->id)->alert()->label('Backup #'.$b->id.' — '.$b->database_name.' — '.$b->state)->description('Cluster #'.$b->cluster_id.', PostgreSQL '.$b->major.', custom format, '.$b->created_at.'. '.$b->result);
        }
        foreach (BackupSchedule::onServer($this->server)->get() as $s) {
            $fields[] = Field::make('schedule_'.$s->id)->alert()->label('Backup schedule #'.$s->id.' — '.($s->enabled ? 'enabled' : 'disabled'))->description('Cluster #'.$s->cluster_id.', database resource #'.$s->resource_id.', '.$s->cron.' in '.$s->timezone.', keep '.$s->retention.' successful archives. Last slot: '.($s->last_slot ?? 'none'));
        }
        return $fields;
    }

    public function handle(Request $request): void
    {
        Gate::authorize('update', $this->server);
        if (!Lifecycle::enabled()) { $request->session()->flash('error', 'Plugin disabled or metadata upgrade required. Disable and re-enable it from Admin > Plugins.'); return; }
        $op = static::OP;
        if ($op === 'status') { return; }
        if (in_array($op, ['delete_database', 'delete_role', 'service', 'restore', 'ownership'], true)) { Gate::authorize('delete', $this->server); }
        $request->validate(['token' => ['required', 'uuid']]);
        $lock = Cache::lock('vpgm-lifecycle', 4000);
        if (!$lock->get()) { throw ValidationException::withMessages(['token' => 'A PostgreSQL operation is running. Wait for completion, then retry.']); }
        try {
            if (!Lifecycle::enabled()) { $request->session()->flash('error', 'Plugin disabled.'); return; }
            if (Operation::where('token', $request->string('token')->toString())->exists()) { $request->session()->flash('success', 'This request was already submitted. See operation history.'); return; }
            $payload = [];
            $cluster = null;
            $manager = app(Manager::class);
            if ($op === 'adopt') {
                $discovered = ServerState::onServer($this->server)->first()?->discovery ?? [];
                $item = $discovered[Input::id($request->input('discovered')) - 1] ?? null;
                if (!$item) { throw ValidationException::withMessages(['discovered' => 'Run Discover and choose a current cluster.']); }
                $payload = ['major' => $item['major'], 'cluster' => $item['cluster'], 'port' => $item['port']];
            } elseif ($op !== 'discover') {
                $cluster = Cluster::onServer($this->server)->find(Input::id($request->input('cluster')));
                if (!$cluster) { throw ValidationException::withMessages(['cluster' => 'Choose an adopted cluster on this server.']); }
                foreach (['database' => 'database', 'role' => 'role', 'owner' => 'role', 'creator' => 'role'] as $field => $kind) {
                    if ($request->filled($field)) {
                        try {
                            $r = $manager->resource($this->server, $cluster, Input::id($request->input($field)), $kind, $field === 'database' && in_array($op, ['access', 'ownership'], true));
                        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                            throw ValidationException::withMessages([$field => 'Choose an existing '.$field.' from the selected cluster. For access or ownership changes, enable access management for that database first.']);
                        }
                        $payload[$field.'_id'] = $r->id;
                        $resources[$field] = $r;
                    }
                }
                $required = match ($op) {
                    'delete_database', 'manage_database', 'backup', 'schedule' => ['database'],
                    'delete_role', 'reset_password' => ['role'],
                    'create_database', 'restore' => ['owner'], 'ownership' => ['database', 'owner'],
                    'access' => ['database', 'role', 'creator'], 'connection' => ['database', 'role'], default => [],
                };
                foreach ($required as $field) { if (!isset($payload[$field.'_id'])) { throw ValidationException::withMessages([$field => 'Select a '.$field.' from this cluster.']); } }
            }
            if (in_array($op, ['create_role', 'create_database', 'restore'], true)) { $payload['name'] = Input::name($request->string('name')->toString()); }
            if (in_array($op, ['create_database', 'restore'], true)) {
                $payload += $request->validate(['encoding' => 'required|in:UTF8,LATIN1', 'locale' => 'required|string|max:128']);
            }
            if ($op === 'access') { $payload += $request->validate(['level' => 'required|in:read,write']); }
            if ($op === 'service') { $payload += $request->validate(['control' => 'required|in:start,stop,restart']); }
            if (in_array($op, ['backup', 'schedule'], true)) {
                $provider = app(Storage::class)->provider($this->server, $request->user(), Input::id($request->input('storage')));
                $payload['storage_id'] = $provider->id;
            }
            if ($op === 'restore') {
                $backup = Backup::onServer($this->server)->where('state', 'complete')->findOrFail(Input::id($request->input('backup')));
                $payload['backup_id'] = $backup->id;
            }
            if (in_array($op, ['delete_database', 'delete_role', 'service', 'restore', 'ownership', 'manage_database'], true)) {
                $resourceName = $op === 'service' ? $payload['control'] : ($op === 'restore' ? $payload['name'] : ($resources['database']->name ?? $resources['role']->name));
                $expected = 'server #'.$this->server->id.' / cluster #'.$cluster->id.' / '.$resourceName;
                if ($request->input('confirmation') !== $expected) { throw ValidationException::withMessages(['confirmation' => 'Type exactly: '.$expected]); }
            }
            if ($op === 'connection') {
                $s = $cluster->snapshot ?? [];
                $request->session()->flash('success', 'Server '.$this->server->name.', PostgreSQL '.$cluster->major.'/'.$cluster->name.': database='.$resources['database']->name.', user='.$resources['role']->name.', port='.$cluster->port.', listen_addresses='.($s['bind'] ?? 'unknown — refresh status').', unix_socket_directories='.($s['socket'] ?? 'unknown').'. For an application on this managed server choose a listed local address/socket permitted by the HBA rules shown in Status. Password is the value you supplied; it is not retained.');
                return;
            }
            if ($op === 'schedule') {
                $data = $request->validate(['cron' => 'required|string|max:100', 'timezone' => 'required|timezone', 'retention' => 'required|integer|min:1|max:365', 'schedule_enabled' => 'required|in:enabled,disabled']);
                if (!CronExpression::isValidExpression($data['cron'])) { throw ValidationException::withMessages(['cron' => 'Enter a valid five-field cron expression.']); }
                BackupSchedule::updateOrCreate(['project_id' => $this->server->project_id, 'server_id' => $this->server->id, 'cluster_id' => $cluster->id, 'resource_id' => $payload['database_id']],
                    ['storage_id' => $payload['storage_id'], 'user_id' => $request->user()->id, 'cron' => $data['cron'], 'timezone' => $data['timezone'], 'retention' => (int) $data['retention'], 'enabled' => $data['schedule_enabled'] === 'enabled']);
                $payload = []; // Audit no provider credentials.
            }
            $operation = Operation::create(['token' => $request->input('token'), 'project_id' => $this->server->project_id, 'server_id' => $this->server->id,
                'cluster_id' => $cluster?->id, 'user_id' => $request->user()->id, 'action' => $op, 'state' => $op === 'schedule' ? 'complete' : 'pending', 'payload' => $payload,
                'result' => $op === 'schedule' ? 'Schedule configuration saved.' : 'Queued; reload Features after the next scheduler tick, then open Status.']);
            if (in_array($op, ['create_role', 'reset_password'], true)) { $this->password($request, $operation, $cluster, $resources['role'] ?? null); }
            $request->session()->flash($operation->state === 'failed' ? 'error' : 'success', 'PostgreSQL operation #'.$operation->id.' '.$operation->state.'. '.$operation->result);
        } finally { $lock->release(); }
    }

    private function password(Request $request, Operation $operation, Cluster $cluster, ?Resource $role): void
    {
        try {
            $verifier = Input::verifier($request->string('password')->toString());
        } catch (ValidationException $e) { $operation->update(['state' => 'failed', 'result' => 'Password validation failed.']); throw $e; }
        $request->request->remove('password');
        $operation->update(['state' => 'running', 'started_at' => now()]);
        try {
            $payload = ['operation' => static::OP, 'name' => $role?->name ?? $operation->payload['name'], 'verifier' => $verifier] + app(Manager::class)->target($cluster);
            $result = app(Remote::class)->call($this->server, $payload);
            app(Manager::class)->sync($this->server, $cluster);
            $operation->update(['state' => 'complete', 'result' => $result['message'], 'finished_at' => now()]);
        } catch (Throwable $e) {
            $operation->update(['state' => 'failed', 'result' => $e instanceof RemoteFailure ? $e->getMessage() : 'Login operation failed. Refresh inventory before retrying.', 'finished_at' => now()]);
        } finally { unset($verifier, $payload); }
    }
}
