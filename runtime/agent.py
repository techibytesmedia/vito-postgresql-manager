#!/usr/bin/env python3
"""Ephemeral PostgreSQL worker. Run as postgres; JSON request file, JSON reply only.

Never emit subprocess output: PostgreSQL errors can echo a password verifier or
SQL. All commands use argv arrays and all SQL uses independent identifier/literal
quoting. No network credentials, package installation, or default engine lookup.
"""
import hashlib
import json
import os
import re
import subprocess
import sys
from pathlib import Path


class OperationError(Exception):
    pass


def unix_owner(uid):
    import pwd
    return pwd.getpwuid(int(uid)).pw_name


def identifier(value):
    if not isinstance(value, str) or not value or len(value.encode('utf-8')) > 63:
        raise OperationError('Identifiers must contain 1–63 UTF-8 bytes.')
    if any(ord(c) < 32 or ord(c) == 127 for c in value):
        raise OperationError('Control characters are not permitted in identifiers.')
    return '"' + value.replace('"', '""') + '"'


def literal(value):
    if '\x00' in str(value):
        raise OperationError('NUL is not permitted.')
    return "E'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"


def run(args, data=None, timeout=120):
    env = {'PATH': '/usr/bin:/bin', 'HOME': '/var/lib/postgresql', 'LANG': 'C.UTF-8'}
    try:
        result = subprocess.run(args, input=data, text=True, capture_output=True,
                                timeout=timeout, env=env, check=False)
    except subprocess.TimeoutExpired:
        raise OperationError('PostgreSQL operation timed out; refresh status before retrying.') from None
    if result.returncode:
        raise OperationError('PostgreSQL rejected the operation. Check cluster status, permissions, locale, active connections, and object dependencies.')
    return result.stdout


class Agent:
    def __init__(self, request):
        self.r = request
        self.major = str(request.get('major', ''))
        self.cluster = str(request.get('cluster', ''))
        self.port = int(request.get('port', 0))

    def discover(self, inspect=False):
        try:
            clusters = json.loads(run(['/usr/bin/pg_lsclusters', '--json']))
        except (FileNotFoundError, json.JSONDecodeError):
            raise OperationError('No Debian/Ubuntu PostgreSQL cluster tooling found. Install PostgreSQL separately, then refresh.') from None
        found = [{'major': str(c['version']), 'cluster': c['cluster'], 'port': int(c['port']),
                 'status': c.get('status', 'online' if c.get('running') else 'down'),
                 'owner': c.get('owner') or unix_owner(c['owneruid']),
                 'configured_bind': c.get('config', {}).get('listen_addresses', 'default; verify by adopting')} for c in clusters]
        if inspect:
            for cluster in found:
                cluster['bind'] = 'unavailable (offline or administrative access denied)'
                if cluster['status'].split(',')[0] == 'online' and cluster['owner'] == 'postgres':
                    try:
                        client = Agent(cluster)
                        info = client.query("SELECT json_build_object('bind', current_setting('listen_addresses'), 'config', current_setting('config_file'))")
                        if info['config'] == f"/etc/postgresql/{cluster['major']}/{cluster['cluster']}/postgresql.conf":
                            cluster['bind'] = info['bind']
                    except Exception:
                        pass
        return found

    def target(self, online=True):
        if not re.fullmatch(r'[1-9][0-9]*', self.major) or not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_-]*', self.cluster):
            raise OperationError('Invalid cluster identity.')
        found = next((c for c in self.discover() if c['major'] == self.major and c['cluster'] == self.cluster), None)
        if not found or found['port'] != self.port or found['owner'] != 'postgres':
            raise OperationError('Cluster identity, owner, or port changed. Discover and adopt the cluster again.')
        if online and found['status'].split(',')[0] != 'online':
            raise OperationError('Selected cluster is offline. Start it using the service control action.')
        if online:
            info = self.query("SELECT json_build_object('version',current_setting('server_version_num'),'config',current_setting('config_file'))")
            expected = f'/etc/postgresql/{self.major}/{self.cluster}/postgresql.conf'
            if int(info['version']) // 10000 != int(self.major) or info['config'] != expected:
                raise OperationError('Connected cluster does not match the adopted cluster.')
        return found

    def sql(self, sql, db='postgres'):
        identifier(db)
        args = [f'/usr/lib/postgresql/{self.major}/bin/psql', '--no-psqlrc', '--no-password',
                '-h', '/var/run/postgresql', '-p', str(self.port), '-U', 'postgres',
                '--dbname=' + db, '-v', 'ON_ERROR_STOP=1', '-v', 'VERBOSITY=terse', '-Atq']
        return run(args, "SET statement_timeout='60s'; SET lock_timeout='5s';\n" + sql)

    def query(self, sql, db='postgres'):
        output = self.sql(sql, db).strip()
        return json.loads(output) if output else None

    def databases(self):
        return self.query("""SELECT COALESCE(json_agg(x),'[]'::json) FROM (
            SELECT d.oid, datname AS name, pg_get_userbyid(datdba) AS owner,
            pg_encoding_to_char(encoding) AS encoding, datcollate AS locale
            FROM pg_database d WHERE NOT datistemplate AND datname <> 'postgres' AND d.oid >= 16384 ORDER BY datname) x""")

    def roles(self):
        return self.query("""SELECT COALESCE(json_agg(x),'[]'::json) FROM (
            SELECT oid, rolname AS name, rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS privileged
            FROM pg_roles WHERE rolcanlogin AND oid >= 16384 AND rolname !~ '^pg_' AND rolname <> 'postgres' ORDER BY rolname) x""")

    def safe_role(self, name, mutate=False):
        identifier(name)
        row = self.query('SELECT row_to_json(x) FROM (SELECT oid, rolcanlogin, rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls AS privileged FROM pg_roles WHERE rolname=' + literal(name) + ') x')
        if not row or int(row['oid']) < 16384 or name.startswith('pg_') or name == 'postgres' or not row['rolcanlogin'] or (mutate and row['privileged']):
            raise OperationError('Select an existing, non-system application login without administrative privileges.')
        if mutate:
            memberships = self.query('SELECT count(*) FROM pg_auth_members WHERE member=' + str(row['oid']))
            if memberships:
                raise OperationError('This login has role memberships. Review them outside the plugin before changing it.')
        return row

    def safe_db(self, name):
        identifier(name)
        if name in ('postgres', 'template0', 'template1') or name.startswith('pg_'):
            raise OperationError('System databases are protected.')
        row = next((d for d in self.databases() if d['name'] == name), None)
        if row is None:
            raise OperationError('Select an existing non-system application database.')
        return row

    def snapshot(self):
        found = self.target(False)
        result = {'identity': found}
        if found['status'].split(',')[0] != 'online':
            return result
        self.target()
        result.update(self.query("SELECT json_build_object('server_version',version(),'bind',current_setting('listen_addresses'),'socket',current_setting('unix_socket_directories'),'port',current_setting('port'),'hba', (SELECT COALESCE(json_agg(x),'[]'::json) FROM (SELECT type,database,user_name,address,auth_method,error FROM pg_hba_file_rules) x))"))
        result['databases'] = self.databases()
        result['roles'] = self.roles()
        result['locales'] = run(['/usr/bin/locale', '-a']).splitlines()
        return result

    def create_role(self):
        name = self.r['name']
        identifier(name)
        if name == 'postgres' or name.startswith('pg_'):
            raise OperationError('System role names are protected.')
        verifier = self.r['verifier']
        if not re.fullmatch(r'SCRAM-SHA-256\$4096:[A-Za-z0-9+/=]+\$[A-Za-z0-9+/=]+:[A-Za-z0-9+/=]+', verifier):
            raise OperationError('Invalid password verifier.')
        if self.r['operation'] == 'reset_password':
            self.safe_role(name, True)
            prefix = 'ALTER ROLE '
        else:
            prefix = 'CREATE ROLE '
        self.sql("SET log_statement='none'; SET log_min_duration_statement=-1; SET log_min_error_statement='panic'; SET log_duration=off; SET password_encryption='scram-sha-256'; " + prefix + identifier(name) + (' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS' if prefix.startswith('CREATE') else '') + ' PASSWORD ' + literal(verifier))
        return {'message': 'Login password set. Passwords are not retained by Vito.'}

    def create_database(self):
        owner = self.r['owner']
        self.safe_role(owner, True)
        name = self.r['name']
        if name in ('postgres', 'template0', 'template1') or name.startswith('pg_'):
            raise OperationError('System database names are protected.')
        locale = self.r.get('locale', 'C.UTF-8')
        if locale not in run(['/usr/bin/locale', '-a']).splitlines():
            raise OperationError('Select an installed locale from the refreshed cluster status.')
        encoding = self.r.get('encoding', 'UTF8')
        if encoding not in ('UTF8', 'LATIN1'):
            raise OperationError('Supported encodings are UTF8 and LATIN1; choose a compatible locale.')
        self.sql('CREATE DATABASE ' + identifier(name) + ' OWNER ' + identifier(owner) + ' TEMPLATE template0 ENCODING ' + literal(encoding) + ' LC_COLLATE ' + literal(locale) + ' LC_CTYPE ' + literal(locale))
        return {'message': 'Database created.'}

    def access(self):
        db = self.safe_db(self.r['database'])
        role = self.r['role']
        creator = self.r['creator']
        self.safe_role(role, True)
        self.safe_role(creator, True)
        self.safe_role(db['owner'], True)
        level = self.r['level']
        if level not in ('read', 'write'):
            raise OperationError('Choose read or write access.')
        tables = 'SELECT' if level == 'read' else 'SELECT, INSERT, UPDATE, DELETE'
        sequences = 'SELECT' if level == 'read' else 'USAGE, SELECT, UPDATE'
        who = identifier(role)
        schemas = self.query("SELECT COALESCE(json_agg(nspname),'[]'::json) FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname <> 'information_schema'", db['name'])
        statements = ['BEGIN', 'GRANT CONNECT ON DATABASE ' + identifier(db['name']) + ' TO ' + who]
        for schema in schemas:
            s = identifier(schema)
            statements += ['GRANT USAGE ON SCHEMA ' + s + ' TO ' + who,
                           'GRANT ' + tables + ' ON ALL TABLES IN SCHEMA ' + s + ' TO ' + who,
                           'GRANT ' + sequences + ' ON ALL SEQUENCES IN SCHEMA ' + s + ' TO ' + who]
        for author in sorted({creator, db['owner']}):
            prefix = 'ALTER DEFAULT PRIVILEGES FOR ROLE ' + identifier(author) + ' GRANT '
            statements += [prefix + tables + ' ON TABLES TO ' + who,
                           prefix + sequences + ' ON SEQUENCES TO ' + who,
                           prefix + 'USAGE ON SCHEMAS TO ' + who]
        statements.append('COMMIT')
        self.sql(';\n'.join(statements), db['name'])
        return {'message': 'Additive grants applied to existing schemas, tables and sequences, plus future objects created by the owner and selected creator. Existing grants were preserved.'}

    def artifact(self):
        # All artifacts are rooted in the configured provider path, then a fixed
        # plugin prefix and numeric scope. Reject symlink escapes at every level.
        base = Path(self.r['base'])
        if not base.is_absolute() or '..' in base.parts:
            raise OperationError('Storage must use an absolute directory without parent traversal.')
        ids = [str(self.r[k]) for k in ('project_id', 'server_id', 'cluster_id')]
        if not all(re.fullmatch(r'[1-9][0-9]*', x) for x in ids):
            raise OperationError('Invalid artifact scope.')
        token = self.r['artifact']
        if not re.fullmatch(r'[a-f0-9]{32}', token):
            raise OperationError('Invalid artifact identity.')
        path = base.joinpath('vito-postgresql', *ids, token + '.dump')
        for part in [path, *path.parents]:
            if part.is_symlink():
                raise OperationError('Symlinked backup paths are not supported.')
        return path

    def backup(self):
        name = self.safe_db(self.r['database'])['name']
        path = self.artifact()
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        if path.exists():
            raise OperationError('Artifact already exists; refresh history before retrying.')
        try:
            run([f'/usr/lib/postgresql/{self.major}/bin/pg_dump', '-h', '/var/run/postgresql', '-p', str(self.port), '-U', 'postgres', '--no-password', '--format=custom', '--dbname=' + name, '--file=' + str(path)], timeout=1800)
            os.chmod(path, 0o600)
            with path.open('rb') as stream:
                checksum = hashlib.file_digest(stream, 'sha256').hexdigest()
            return {'path': str(path), 'size': path.stat().st_size, 'sha256': checksum, 'format': 'custom', 'engine': 'postgresql', 'tool_major': self.major}
        except Exception:
            path.unlink(missing_ok=True)
            raise

    def restore(self):
        path = self.artifact()
        with path.open('rb') as stream:
            if stream.read(5) != b'PGDMP':
                raise OperationError('Only PostgreSQL custom-format archives can be restored.')
            stream.seek(0)
            if hashlib.file_digest(stream, 'sha256').hexdigest() != self.r['sha256']:
                raise OperationError('Backup checksum does not match; restore refused.')
        listing = run([f'/usr/lib/postgresql/{self.major}/bin/pg_restore', '--list', str(path)])
        match = re.search(r'Dumped from database version: (\d+)', listing)
        if not match or int(match[1]) > int(self.major):
            raise OperationError('Backup server version is incompatible with the target cluster.')
        self.create_database()  # Refuses an existing target. Never drops one.
        run([f'/usr/lib/postgresql/{self.major}/bin/pg_restore', '-h', '/var/run/postgresql', '-p', str(self.port), '-U', 'postgres', '--no-password', '--no-owner', '--no-privileges', '--exit-on-error', '--single-transaction', '--role=' + self.r['owner'], '--dbname=' + self.r['name'], str(path)], timeout=1800)
        return {'message': 'Restored into the new database. Ownership uses the selected login; source ACLs were not imported.'}

    def execute(self):
        op = self.r['operation']
        if op == 'discover':
            return self.discover(inspect=True)
        if op in ('snapshot', 'adopt'):
            if op == 'adopt':
                self.target()
            return self.snapshot()
        if op in ('artifact_delete', 'artifact_prepare'):
            path = self.artifact()
            if op == 'artifact_delete':
                path.unlink(missing_ok=True)
            else:
                path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            return {'path': str(path)}
        self.target()
        if op in ('create_role', 'reset_password'):
            return self.create_role()
        if op == 'create_database':
            return self.create_database()
        if op == 'delete_database':
            self.safe_db(self.r['database'])
            self.sql('DROP DATABASE ' + identifier(self.r['database']))
        elif op == 'delete_role':
            row = self.safe_role(self.r['role'], True)
            if self.query('SELECT count(*) FROM pg_shdepend WHERE refclassid=\'pg_authid\'::regclass AND refobjid=' + str(row['oid'])):
                raise OperationError('Login has ownership or grant dependencies. Transfer ownership and review grants explicitly before deleting it. No objects were dropped.')
            self.sql('DROP ROLE ' + identifier(self.r['role']))
        elif op == 'ownership':
            self.safe_db(self.r['database'])
            self.safe_role(self.r['owner'], True)
            self.sql('ALTER DATABASE ' + identifier(self.r['database']) + ' OWNER TO ' + identifier(self.r['owner']))
        elif op == 'access':
            return self.access()
        elif op == 'backup':
            return self.backup()
        elif op == 'restore':
            return self.restore()
        else:
            raise OperationError('Unknown PostgreSQL operation.')
        return {'message': 'Operation completed.'}


def main():
    os.umask(0o077)
    try:
        with open(sys.argv[1], encoding='utf-8') as stream:
            request = json.load(stream)
        result = Agent(request).execute()
        print(json.dumps({'ok': True, 'result': result}, ensure_ascii=True))
    except OperationError as error:
        print(json.dumps({'ok': False, 'error': str(error)}))
    except Exception:
        # Never serialize traceback/arguments or raw server/SQL errors.
        print(json.dumps({'ok': False, 'error': 'Cluster operation failed. Verify PostgreSQL tooling, storage permissions and connectivity; refresh status before retrying.'}))


if __name__ == '__main__':
    main()
