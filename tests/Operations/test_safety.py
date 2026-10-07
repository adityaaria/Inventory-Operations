import datetime
import gzip
import importlib.util
import json
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path)
    module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module); return module
ops=load('dbops','scripts/db-operations.py')
monitor=load('monitor','scripts/monitor.py')

class OperationalSafetyTest(unittest.TestCase):
    def test_restore_refuses_application_project(self):
        with self.assertRaises(ValueError): ops.assert_restore_target('inventory-operations','inventory_order_management_restore',0)
    def test_restore_refuses_application_database(self):
        with self.assertRaises(ValueError): ops.assert_restore_target('inventory-restore-drill','inventory_order_management',0)
    def test_restore_refuses_nonempty_database(self):
        with self.assertRaises(ValueError): ops.assert_restore_target('inventory-restore-drill','inventory_order_management_restore',1)
    def test_disposable_empty_target_allowed(self):
        ops.assert_restore_target('inventory-restore-drill','inventory_order_management_restore',0)
    def archive(self,directory,created=None):
        p=Path(directory)/'inventory-20261007.sql.gz'
        with gzip.open(p,'wb') as f: f.write(b'CREATE TABLE example (id INT);')
        Path(str(p)+'.json').write_text(json.dumps({'format':1,'sha256':ops.sha256(p),'created_utc':created or datetime.datetime.now(datetime.timezone.utc).isoformat()}))
        return p
    def test_checksum_mismatch_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.archive(d); p.write_bytes(p.read_bytes()+b'bad')
            with self.assertRaises(ValueError): ops.verify_archive(p)
    def test_valid_archive_verified(self):
        with tempfile.TemporaryDirectory() as d: self.assertEqual(1,ops.verify_archive(self.archive(d))['format'])
    def test_gzip_integrity_checked_even_with_matching_digest(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.archive(d); p.write_bytes(b'not gzip'); m=Path(str(p)+'.json'); row=json.loads(m.read_text()); row['sha256']=ops.sha256(p); m.write_text(json.dumps(row))
            with self.assertRaises(OSError): ops.verify_archive(p)
    def test_missing_backup_alert(self):
        with tempfile.TemporaryDirectory() as d: self.assertEqual('backup_missing',monitor.backup_alert(d,3600,time.time()))
    def test_stale_backup_alert(self):
        with tempfile.TemporaryDirectory() as d:
            self.archive(d,'2020-01-01T00:00:00Z'); self.assertEqual('backup_stale',monitor.backup_alert(d,3600,time.time()))
    def test_recent_backup_healthy(self):
        with tempfile.TemporaryDirectory() as d:
            self.archive(d); self.assertIsNone(monitor.backup_alert(d,3600,time.time()))
    def test_corrupt_backup_alert(self):
        with tempfile.TemporaryDirectory() as d:
            p=self.archive(d); p.write_bytes(b'corrupt'); self.assertEqual('backup_corrupt',monitor.backup_alert(d,3600,time.time()))
    def test_prune_dry_run_preserves_backups(self):
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as d:
            a=self.archive(d); b=Path(d)/'inventory-20261008.sql.gz'; b.write_bytes(a.read_bytes()); Path(str(b)+'.json').write_bytes(Path(str(a)+'.json').read_bytes())
            with patch('builtins.print'): ops.prune(SimpleNamespace(directory=d,keep=1,apply=False))
            self.assertTrue(a.exists()); self.assertTrue(b.exists())
    def test_prune_never_removes_good_backup_when_newest_corrupt(self):
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as d:
            a=self.archive(d); b=Path(d)/'inventory-20261008.sql.gz'; b.write_bytes(b'bad'); Path(str(b)+'.json').write_text('{"format":1,"sha256":"bad"}')
            with self.assertRaises(ValueError): ops.prune(SimpleNamespace(directory=d,keep=1,apply=True))
            self.assertTrue(a.exists())

    def assert_load_refused(self,arguments):
        import subprocess
        import sys
        with tempfile.TemporaryDirectory() as d:
            report=Path(d)/'result.json'
            result=subprocess.run([sys.executable,str(ROOT/'tests/HTTP/load.py'),'--docker','/does-not-exist','--output',str(report)]+arguments,capture_output=True)
            self.assertEqual(2,result.returncode)
            self.assertFalse(report.exists())
    def test_load_refuses_main_application(self):
        self.assert_load_refused(['--project','inventory-operations'])
    def test_load_refuses_external_target(self):
        self.assert_load_refused(['--project','inventory-load-drill','--url','https://example.com'])
    def test_load_refuses_non_http_scheme(self):
        self.assert_load_refused(['--project','inventory-load-drill','--url','file://localhost:18086'])
    def test_load_refuses_unbounded_users(self):
        self.assert_load_refused(['--project','inventory-load-drill','--users','1000'])

    def backup_args(self,directory):
        from types import SimpleNamespace
        return SimpleNamespace(directory=directory,docker='docker',file=['compose.yaml'],env_file=None,project='inventory-e2e-drill')
    def test_dump_failure_preserves_previous_backup_and_removes_partial(self):
        with tempfile.TemporaryDirectory() as d:
            old=Path(d)/'inventory-stamp-42.sql.gz'; old.write_bytes(b'previous good archive'); manifest=Path(str(old)+'.json'); manifest.write_text('previous manifest')
            with patch.object(ops,'database_info',return_value='fixture'),patch.object(ops.time,'strftime',return_value='stamp'),patch.object(ops.time,'time_ns',return_value=42),patch.object(ops,'run',side_effect=RuntimeError('dump failed')):
                with self.assertRaises(RuntimeError): ops.backup(self.backup_args(d))
            self.assertEqual(b'previous good archive',old.read_bytes()); self.assertEqual('previous manifest',manifest.read_text())
            self.assertFalse(list(Path(d).glob('.dump-*')))
    def test_filename_collision_preserves_existing_backup(self):
        with tempfile.TemporaryDirectory() as d:
            old=Path(d)/'inventory-stamp-42.sql.gz'; old.write_bytes(b'previous good archive')
            def write_dump(command,**kwargs): kwargs['stdout'].write(b'SELECT 1;')
            with patch.object(ops,'database_info',return_value='fixture'),patch.object(ops.time,'strftime',return_value='stamp'),patch.object(ops.time,'time_ns',return_value=42),patch.object(ops,'run',side_effect=write_dump):
                with self.assertRaises(FileExistsError): ops.backup(self.backup_args(d))
            self.assertEqual(b'previous good archive',old.read_bytes()); self.assertFalse(list(Path(d).glob('.dump-*')))
