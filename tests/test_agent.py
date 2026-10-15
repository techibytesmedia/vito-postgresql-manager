import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch, Mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('worker', ROOT / 'runtime/agent.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class WorkerTests(unittest.TestCase):
    def agent(self, **args):
        return worker.Agent({'operation': 'snapshot', 'major': '18', 'cluster': 'main', 'port': 5432, **args})

    def test_identifier_quotes_sql_and_shell_payload_as_one_identifier(self):
        self.assertEqual(worker.identifier('x";$(id)'), '"x"";$(id)"')

    def test_utf8_byte_limit(self):
        self.assertEqual(worker.identifier('é' * 31), '"' + 'é' * 31 + '"')
        with self.assertRaises(worker.OperationError):
            worker.identifier('é' * 32)

    def test_control_characters_rejected(self):
        for value in ('', 'x\x00y', 'x\ny', 'x\ty', 'x\x7fy'):
            with self.subTest(value=value), self.assertRaises(worker.OperationError):
                worker.identifier(value)

    def test_literal_escaping_is_distinct_from_identifier_escaping(self):
        self.assertEqual(worker.literal("x'\\y"), "E'x''\\\\y'")

    def test_nul_literal_rejected(self):
        with self.assertRaises(worker.OperationError):
            worker.literal('x\0')

    @patch.object(worker, 'unix_owner', return_value='postgres')
    @patch.object(worker, 'run')
    def test_actual_pgdg_json_shape(self, run, owner):
        run.return_value = json.dumps([{'version': '18', 'cluster': 'main', 'port': '5432', 'owneruid': 104, 'running': 1, 'config': {}}])
        found = self.agent().discover()[0]
        self.assertEqual((found['owner'], found['status'], found['port']), ('postgres', 'online', 5432))

    @patch.object(worker, 'unix_owner', return_value='postgres')
    @patch.object(worker, 'run')
    def test_offline_discovery(self, run, owner):
        run.return_value = json.dumps([{'version': '18', 'cluster': 'main', 'port': '5432', 'owneruid': 104, 'running': 0}])
        self.assertEqual(self.agent().discover()[0]['status'], 'down')

    def test_missing_cluster_tooling_is_actionable(self):
        with patch.object(worker, 'run', side_effect=FileNotFoundError), self.assertRaisesRegex(worker.OperationError, 'Install PostgreSQL separately'):
            self.agent().discover()

    def test_cluster_target_cannot_use_another_port(self):
        a = self.agent()
        a.discover = Mock(return_value=[{'major': '18', 'cluster': 'main', 'port': 5433, 'owner': 'postgres', 'status': 'online'}])
        with self.assertRaisesRegex(worker.OperationError, 'identity, owner, or port changed'):
            a.target()

    def test_online_target_verifies_config_path(self):
        a = self.agent()
        a.discover = Mock(return_value=[{'major': '18', 'cluster': 'main', 'port': 5432, 'owner': 'postgres', 'status': 'online'}])
        a.query = Mock(return_value={'version': '180006', 'config': '/etc/postgresql/18/other/postgresql.conf'})
        with self.assertRaisesRegex(worker.OperationError, 'does not match'):
            a.target()

    def test_offline_target_has_understandable_error(self):
        a = self.agent()
        a.discover = Mock(return_value=[{'major': '18', 'cluster': 'main', 'port': 5432, 'owner': 'postgres', 'status': 'down'}])
        with self.assertRaisesRegex(worker.OperationError, 'offline'):
            a.target()
        self.assertEqual(a.target(False)['status'], 'down')

    def test_role_oid_is_string_in_postgresql_json(self):
        a = self.agent()
        a.query = Mock(side_effect=[{'oid': '16400', 'rolcanlogin': True, 'privileged': False}, 0])
        self.assertEqual(a.safe_role('app', True)['oid'], '16400')

    def test_system_privileged_and_membership_roles_are_protected(self):
        for row in ({'oid': '10', 'rolcanlogin': True, 'privileged': True},
                    {'oid': '16400', 'rolcanlogin': True, 'privileged': True},
                    {'oid': '16400', 'rolcanlogin': False, 'privileged': False}):
            a = self.agent()
            a.query = Mock(return_value=row)
            with self.assertRaises(worker.OperationError):
                a.safe_role('app', True)
        a.query = Mock(side_effect=[{'oid': '16400', 'rolcanlogin': True, 'privileged': False}, 1])
        with self.assertRaisesRegex(worker.OperationError, 'memberships'):
            a.safe_role('app', True)

    def test_system_databases_are_protected_even_with_forged_inventory(self):
        a = self.agent()
        a.databases = Mock(return_value=[{'name': n} for n in ['postgres', 'template0', 'template1', 'pg_reserved']])
        for name in ('postgres', 'template0', 'template1', 'pg_reserved'):
            with self.assertRaisesRegex(worker.OperationError, 'protected'):
                a.safe_db(name)

    def test_access_uses_transactions_existing_objects_and_each_creator(self):
        a = self.agent(database='app', role='reader', creator='migrator', level='read')
        a.safe_db = Mock(return_value={'name': 'app', 'owner': 'owner'})
        a.safe_role = Mock()
        a.query = Mock(return_value=['public', 'custom"schema'])
        a.sql = Mock()
        a.access()
        sql, database = a.sql.call_args.args
        self.assertEqual(database, 'app')
        self.assertIn('BEGIN;', sql)
        self.assertIn('GRANT SELECT ON ALL TABLES IN SCHEMA "custom""schema"', sql)
        self.assertIn('FOR ROLE "owner" GRANT SELECT ON TABLES', sql)
        self.assertIn('FOR ROLE "migrator" GRANT USAGE ON SCHEMAS', sql)
        self.assertNotIn('INSERT', sql)
        self.assertNotIn('REVOKE', sql)
        self.assertTrue(sql.endswith('COMMIT'))

    def test_write_access_grants_sequences(self):
        a = self.agent(database='app', role='writer', creator='owner', level='write')
        a.safe_db = Mock(return_value={'name': 'app', 'owner': 'owner'})
        a.safe_role = Mock(); a.query = Mock(return_value=['public']); a.sql = Mock()
        a.access()
        self.assertIn('USAGE, SELECT, UPDATE ON ALL SEQUENCES', a.sql.call_args.args[0])

    def test_sql_passes_db_as_option_value_not_command_text(self):
        with patch.object(worker, 'run', return_value='') as run:
            self.agent().sql('SELECT 1', '-d evil;$(id)')
            args, sql = run.call_args.args
            connection = next(arg for arg in args if arg.startswith('--dbname='))
            self.assertIn("dbname='-d evil;$(id)'", connection)
            self.assertIn("host='/var/run/postgresql'", connection)
            self.assertIn("port='5432'", connection)
            self.assertEqual(args[0], '/usr/lib/postgresql/18/bin/psql')
            self.assertIn('lock_timeout', sql)
            self.assertTrue(sql.startswith('SET search_path = pg_catalog;'))

    def test_connection_uses_libpq_value_escaping(self):
        self.assertEqual(worker.connection_value("x'\\y"), "'x\\'\\\\y'")
        with self.assertRaises(worker.OperationError):
            worker.connection_value('x\0')

    def test_connection_keeps_database_inside_fixed_parameters(self):
        for name in ('dbname=postgres', 'postgresql:///postgres', "x' host=wrong \\ y"):
            with self.subTest(name=name):
                connection = self.agent().connection(name)
                self.assertIn('dbname=' + worker.connection_value(name), connection)
                self.assertIn("user='postgres'", connection)
                self.assertIn("options='-csearch_path=pg_catalog'", connection)
                self.assertIn("passfile='/dev/null'", connection)

    def test_connection_rejects_invalid_names_and_ports(self):
        for name in ('', 'x\nname', 'x' * 64):
            with self.assertRaises(worker.OperationError):
                self.agent().connection(name)
        for port in (0, -1, 65536):
            with self.assertRaises(worker.OperationError):
                self.agent(port=port).connection('postgres')

    def test_subprocess_failure_redacts_output_and_arguments(self):
        with patch.object(worker.subprocess, 'run', return_value=Mock(returncode=1, stderr='SECRET SQL verifier', stdout='SECRET')):
            with self.assertRaises(worker.OperationError) as caught:
                worker.run(['/bin/tool', 'SECRET'])
        self.assertNotIn('SECRET', str(caught.exception))

    def test_timeout_is_sanitized_and_bounded(self):
        with patch.object(worker.subprocess, 'run', side_effect=subprocess.TimeoutExpired('SECRET', 30)):
            with self.assertRaisesRegex(worker.OperationError, 'timed out') as caught:
                worker.run(['/bin/tool'])
        self.assertNotIn('SECRET', str(caught.exception))

    def test_artifact_namespace_and_scope(self):
        a = self.agent(base=str(ROOT), project_id=1, server_id=2, cluster_id=3, artifact='a' * 32)
        self.assertEqual(a.artifact().as_posix(), ROOT.as_posix() + '/vito-postgresql/1/2/3/' + 'a' * 32 + '.dump')

    def test_artifact_rejects_traversal_and_forged_ids(self):
        for override in ({'base': '/tmp/../etc'}, {'artifact': '../x'}, {'server_id': '2;id'}, {'base': 'relative'}):
            a = self.agent(base='/tmp', project_id=1, server_id=2, cluster_id=3, artifact='a' * 32)
            a.r.update(override)
            with self.assertRaises(worker.OperationError):
                a.artifact()

    @unittest.skipIf(__import__('os').name == 'nt', 'POSIX symlink test')
    def test_artifact_rejects_symlink_parent(self):
        with tempfile.TemporaryDirectory() as temp:
            link = Path(temp) / 'link'; link.symlink_to(Path(temp), target_is_directory=True)
            a = self.agent(base=str(link), project_id=1, server_id=2, cluster_id=3, artifact='a' * 32)
            with self.assertRaisesRegex(worker.OperationError, 'Symlinked'):
                a.artifact()


class PackagingTests(unittest.TestCase):
    def test_namespace_normalization_matches_vito(self):
        spec = importlib.util.spec_from_file_location('packaging', ROOT / 'tools/prepare_release.py')
        p = importlib.util.module_from_spec(spec); spec.loader.exec_module(p)
        self.assertEqual(p.psr('my-user_name'), 'MyUserName')
        self.assertEqual(p.psr('keepCASE-plugin'), 'KeepCASEPlugin')
        self.assertEqual(p.archive_path(Path('/tmp/plugin-0.1.0-dev')), Path('/tmp/plugin-0.1.0-dev.zip'))
        with self.assertRaises(ValueError):
            p.psr('1-invalid')


if __name__ == '__main__':
    unittest.main()
