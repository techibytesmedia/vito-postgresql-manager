#!/usr/bin/env python3
"""Real PostgreSQL checks. Run only in the isolated, disposable test sandbox.

Requires PostgreSQL 18, a postgres Unix identity, a private /scratch filesystem,
and /var/run/postgresql mapped to /scratch/socket. Never use a live cluster.
The optional VPGM_BASELINE_AGENT points to a read-only v0.1.0 worker copy.
"""
import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BIN = '/usr/lib/postgresql/18/bin/'
PORT = 55489


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


worker = load(ROOT / 'runtime/agent.py', 'security_worker')


class SecurityRegression(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.environ.get('VPGM_SECURITY_SANDBOX') != '1' or os.getuid() == 0:
            raise RuntimeError('Requires the isolated unprivileged security-test sandbox.')
        if not Path('/scratch/home').is_dir() or not Path('/scratch/socket').is_dir():
            raise RuntimeError('Private scratch and socket directories required.')
        cls.data = '/scratch/pgdata'
        initialized = subprocess.run([BIN + 'initdb', '-D', cls.data, '--username=postgres',
                                      '--auth-local=peer', '--auth-host=reject', '--locale=C.UTF-8'],
                                     capture_output=True, text=True, timeout=30)
        if initialized.returncode:
            raise RuntimeError('Disposable initdb failed: ' + initialized.stderr)
        options = ("-c listen_addresses='' -c unix_socket_directories=/var/run/postgresql "
                   f'-p {PORT} -c shared_buffers=16MB -c max_connections=12 '
                   '-c max_wal_size=128MB -c min_wal_size=32MB -c unix_socket_permissions=0700')
        subprocess.run([BIN + 'pg_ctl', '-D', cls.data, '-l', '/scratch/postgres.log',
                        '-o', options, '-w', 'start'],
                       check=True, capture_output=True, text=True, timeout=30)
        cls.admin = cls.agent()
        cls.admin.sql('CREATE ROLE fixture_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS; '
                      'CREATE ROLE fixture_reader LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS')
        cls.databases = set()

    @classmethod
    def tearDownClass(cls):
        subprocess.run([BIN + 'pg_ctl', '-D', cls.data, '-m', 'fast', '-w', 'stop'],
                       check=True, capture_output=True, text=True, timeout=30)

    @classmethod
    def agent(cls, **values):
        return worker.Agent({'major': '18', 'cluster': 'main', 'port': PORT, **values})

    def database(self, name):
        if name not in self.databases:
            self.admin.sql('CREATE DATABASE ' + worker.identifier(name) + ' OWNER fixture_owner TEMPLATE template0')
            self.databases.add(name)
        return name

    def baseline(self):
        path = os.environ.get('VPGM_BASELINE_AGENT')
        if not path:
            self.skipTest('Provide a v0.1.0 worker copy for the negative-control checks.')
        module = load(path, 'baseline_worker')
        return module.Agent({'major': '18', 'cluster': 'main', 'port': PORT})

    def poison_database(self):
        name = self.database('schema_shadow')
        # The owner is an ordinary application login. Its function only reports
        # the caller identity: it never changes privileges or other resources.
        self.admin.sql("SET ROLE fixture_owner; CREATE OR REPLACE FUNCTION public.json_agg(name) "
                       "RETURNS json LANGUAGE sql AS $$ SELECT pg_catalog.json_build_array(current_user) $$", name)
        self.admin.sql('ALTER DATABASE ' + worker.identifier(name) + ' SET search_path = public, pg_catalog')
        return name

    def test_legacy_database_name_changes_the_selected_database(self):
        name = self.database('dbname=postgres')
        actual = self.baseline().query('SELECT to_json(current_database())', name)
        self.assertEqual(actual, 'postgres')
        self.assertNotEqual(actual, name)

    def test_legacy_schema_function_runs_as_the_administrative_login(self):
        name = self.poison_database()
        result = self.baseline().query(
            "SELECT COALESCE(json_agg(nspname),'[]'::json) FROM pg_namespace WHERE nspname='public'", name)
        self.assertEqual(result, ['postgres'])

    def test_psql_keeps_special_database_names_literal(self):
        names = ['dbname=postgres', 'postgresql:///postgres', 'postgres:///postgres',
                 "x' host=/missing dbname=postgres", 'x\\y=z', 'with spaces',
                 '東京=demo', '-d plain;$(id)']
        for name in names:
            with self.subTest(name=name):
                self.database(name)
                result = self.admin.query(
                    "SELECT json_build_object('database',current_database(),'port',current_setting('port'),"
                    "'login',current_user,'search_path',current_setting('search_path'))", name)
                self.assertEqual(result, {'database': name, 'port': str(PORT),
                                          'login': 'postgres', 'search_path': 'pg_catalog'})

    def test_backup_and_restore_use_the_literal_source_and_new_target(self):
        source = self.database('dbname=postgres')
        self.admin.sql("SET ROLE fixture_owner; CREATE TABLE public.regression_marker(message text); "
                       "INSERT INTO public.regression_marker VALUES ('literal source archive')", source)
        with tempfile.TemporaryDirectory(dir='/scratch') as directory:
            artifact = {'base': directory, 'project_id': 1, 'server_id': 1,
                        'cluster_id': 1, 'artifact': 'a' * 32}
            backup = self.agent(database=source, **artifact).backup()
            target = 'host=/missing dbname=postgres'
            locales = worker.run(['/usr/bin/locale', '-a']).splitlines()
            locale = next(value for value in locales if value.lower() in ('c.utf8', 'c.utf-8'))
            result = self.agent(name=target, owner='fixture_owner', encoding='UTF8',
                                locale=locale, sha256=backup['sha256'], **artifact).restore()
            self.assertIn('Restored', result['message'])
            self.assertEqual(self.admin.query('SELECT to_json(current_database())', target), target)
            self.assertEqual(self.admin.query('SELECT to_json(message) FROM public.regression_marker', target),
                             'literal source archive')
            with self.assertRaises(worker.OperationError):
                self.agent(name=target, owner='fixture_owner', encoding='UTF8', locale=locale,
                           sha256=backup['sha256'], **artifact).restore()

    def test_privileged_queries_ignore_database_search_path_and_shadow_functions(self):
        name = self.poison_database()
        result = self.admin.query(
            "SELECT COALESCE(json_agg(nspname),'[]'::json) FROM pg_namespace WHERE nspname='public'", name)
        self.assertEqual(result, ['public'])
        self.assertEqual(self.admin.query("SELECT to_json(current_setting('search_path'))", name), 'pg_catalog')

    def test_access_grants_work_with_untrusted_schema_functions_present(self):
        name = self.poison_database()
        self.admin.sql("SET ROLE fixture_owner; CREATE TABLE public.app_data(value text); "
                       "INSERT INTO public.app_data VALUES ('allowed read')", name)
        result = self.agent(database=name, role='fixture_reader', creator='fixture_owner', level='read').access()
        self.assertIn('Additive grants', result['message'])
        self.assertEqual(self.admin.sql('SET ROLE fixture_reader; SELECT value FROM public.app_data', name).strip(),
                         'allowed read')
        flags = self.admin.query("SELECT json_build_array(rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls) "
                                 "FROM pg_roles WHERE rolname='fixture_owner'")
        self.assertEqual(flags, [False] * 5)


if __name__ == '__main__':
    unittest.main(verbosity=2)
