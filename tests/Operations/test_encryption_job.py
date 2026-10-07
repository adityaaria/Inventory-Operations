import datetime
import gzip
import hashlib
import importlib.util
import json
import os
import plistlib
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

ROOT=Path(__file__).resolve().parents[2]
def module(name,file):
    spec=importlib.util.spec_from_file_location(name,ROOT/file); result=importlib.util.module_from_spec(spec); spec.loader.exec_module(result); return result
secure=module('securebackup','scripts/secure-backup.py'); job=module('operationsjob','scripts/operations-job.py')

class SchedulerRenderingTest(unittest.TestCase):
    def test_render_preserves_spaced_paths_without_creating_deployment_directories(self):
        with tempfile.TemporaryDirectory() as work:
            root=Path(work)/'application with spaces'; root.mkdir()
            status=root/'uninstalled-status'
            config=root/'review.json'
            config.write_text(json.dumps({'root':str(root),'status_directory':str(status)}))
            output=root/'rendered'
            result=subprocess.run(['python3',str(ROOT/'scripts/render-scheduler.py'),'--config',str(config),'--output',str(output)],capture_output=True,check=True)
            self.assertFalse(json.loads(result.stdout)['installed'])
            self.assertFalse(status.exists())
            schedule=plistlib.loads((output/'com.inventory.operations.plist').read_bytes())
            self.assertEqual(str(config.resolve()),schedule['ProgramArguments'][-1])
            self.assertEqual(300,schedule['StartInterval'])
            self.assertNotIn('Persistent=true',(output/'inventory-operations.timer').read_text())

class EncryptedBackupTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.work=tempfile.TemporaryDirectory(); cls.keys=Path(cls.work.name)
        for name in ('recipient','other'):
            subprocess.run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','2','-subj','/CN=BackupTest','-keyout',str(cls.keys/(name+'.key')),'-out',str(cls.keys/(name+'.pem'))],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    @classmethod
    def tearDownClass(cls): cls.work.cleanup()
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.root=Path(self.tmp.name); self.archive=self.root/'inventory-fixture.sql.gz'
        with gzip.open(self.archive,'wb') as output: output.write(b'CREATE TABLE fixture (id INT); INSERT INTO fixture VALUES (1);')
        self.metadata={'format':1,'sha256':secure.ops.sha256(self.archive),'created_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
        Path(str(self.archive)+'.json').write_text(json.dumps(self.metadata))
    def tearDown(self): self.tmp.cleanup()
    def seal(self):
        return secure.seal(self.archive,self.keys/'recipient.pem',self.root/'encrypted',replica=self.root/'replica')
    def test_authenticated_roundtrip_and_replica_integrity(self):
        sealed=self.seal(); self.assertEqual(sealed['sha256'],sealed['replica_sha256']); self.assertFalse(sealed['off_host_verified'])
        decrypted=secure.unseal(sealed['replica'],self.keys/'recipient.pem',self.keys/'recipient.key',self.root/'plain')
        self.assertEqual(self.archive.read_bytes(),Path(decrypted['archive']).read_bytes())
        self.assertEqual(0o600,Path(sealed['archive']).stat().st_mode & 0o777)
        self.assertEqual(0o600,Path(decrypted['archive']).stat().st_mode & 0o777)
    def test_wrong_private_key_rejected_without_plaintext(self):
        sealed=self.seal(); plain=self.root/'plain'
        with self.assertRaises(RuntimeError): secure.unseal(sealed['archive'],self.keys/'recipient.pem',self.keys/'other.key',plain)
        self.assertEqual([],list(plain.iterdir()))
    def test_gcm_tampering_rejected_even_if_outer_checksum_rewritten(self):
        sealed=self.seal(); archive=Path(sealed['archive']); data=bytearray(archive.read_bytes()); data[-1]^=1; archive.write_bytes(data)
        meta=Path(str(archive)+'.json'); row=json.loads(meta.read_text()); row['sha256']=secure.ops.sha256(archive); meta.write_text(json.dumps(row))
        with self.assertRaises(RuntimeError): secure.unseal(archive,self.keys/'recipient.pem',self.keys/'recipient.key',self.root/'plain')
        self.assertEqual([],list((self.root/'plain').iterdir()))
    def test_bad_outer_checksum_refused_before_openssl(self):
        sealed=self.seal(); archive=Path(sealed['archive']); archive.write_bytes(b'corrupt')
        with patch.object(secure,'run') as runner:
            with self.assertRaises(ValueError): secure.unseal(archive,self.keys/'recipient.pem',self.keys/'recipient.key',self.root/'plain')
            runner.assert_not_called()
    def test_existing_replica_never_overwritten(self):
        sealed=self.seal(); original=Path(sealed['replica']).read_bytes()
        with self.assertRaises(ValueError): secure.publish_pair(sealed['archive'],secure.verify(sealed['archive']),self.root/'replica')
        self.assertEqual(original,Path(sealed['replica']).read_bytes())
    def test_nonempty_decryption_target_refused(self):
        sealed=self.seal(); plain=self.root/'plain'; plain.mkdir(mode=0o700); (plain/'existing').write_text('preserve')
        with self.assertRaises(ValueError): secure.unseal(sealed['archive'],self.keys/'recipient.pem',self.keys/'recipient.key',plain)
        self.assertEqual('preserve',(plain/'existing').read_text())
    def test_bundle_path_traversal_rejected(self):
        import io
        import tarfile
        data=io.BytesIO()
        with tarfile.open(fileobj=data,mode='w') as tar:
            member=tarfile.TarInfo('../outside'); member.size=1; tar.addfile(member,io.BytesIO(b'x'))
        data.seek(0)
        with tarfile.open(fileobj=data) as tar:
            with self.assertRaises(ValueError): secure.safe_members(tar)
    def test_bundle_symlink_rejected(self):
        import io
        import tarfile
        data=io.BytesIO()
        with tarfile.open(fileobj=data,mode='w') as tar:
            for name in ('backup.sql.gz','backup.sql.gz.json'):
                member=tarfile.TarInfo(name); member.type=tarfile.SYMTYPE; member.linkname='/etc/passwd'; tar.addfile(member)
        data.seek(0)
        with tarfile.open(fileobj=data) as tar:
            with self.assertRaises(ValueError): secure.safe_members(tar)
    def config(self):
        replica=self.root/'replica'; replica.mkdir(mode=0o700,exist_ok=True)
        return {'root':str(ROOT),'project':'inventory-e2e-job','status_directory':str(self.root/'status'),'backup_directory':str(self.root/'backups'),'encrypted_directory':str(self.root/'encrypted'),'replica_directory':str(replica),'recipient_certificate':str(self.keys/'recipient.pem'),'timezone':'Asia/Jakarta','daily_time':'00:00','keep':7}
    def test_job_creates_replica_and_next_run_does_not_repeat_backup(self):
        config=self.config(); dumps=[]
        def fake_run(command,root,allowed=(0,)):
            if 'backup' in command: dumps.append(command); return {'archive':str(self.archive)}
            return {'alerts':[]} if 'monitor.py' in command[1] else {'status':'pruned'}
        with patch.object(job,'run',side_effect=fake_run):
            first=job.execute(config,force=True); second=job.execute(config)
        self.assertEqual('healthy',first['status']); self.assertTrue(first['backup_completed']); self.assertEqual(1,len(dumps)); self.assertFalse(second['backup_completed'])
        self.assertEqual(0o600,(self.root/'status/state.json').stat().st_mode & 0o777)
    def test_replica_loss_alert_is_deduplicated(self):
        config=self.config()
        def fake_run(command,root,allowed=(0,)):
            return {'archive':str(self.archive)} if 'backup' in command else {'alerts':[]}
        with patch.object(job,'run',side_effect=fake_run):
            job.execute(config,force=True)
            for path in (self.root/'replica').iterdir(): path.unlink()
            second=job.execute(config); third=job.execute(config)
        self.assertIn('encrypted_replica_missing_or_corrupt',second['alerts']); self.assertEqual(second['alerts'],third['alerts'])
        self.assertEqual(2,len((self.root/'status/alerts.jsonl').read_text().splitlines()))
    def test_missing_replica_mount_fails_without_dump(self):
        config=self.config(); config['replica_mount']=str(self.root/'not-a-mount')
        with patch.object(job,'run',return_value={'alerts':[]}) as runner:
            result=job.execute(config,force=True)
            self.assertIn('backup_pipeline_failed',result['alerts'])
            self.assertFalse(any('backup' in call.args[0] for call in runner.call_args_list))
    def test_overlapping_job_returns_busy_without_dump(self):
        import fcntl
        config=self.config(); directory=secure.private_directory(config['status_directory'])
        with (directory/'job.lock').open('w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            with patch.object(job,'run') as runner:
                result=job.execute(config,force=True); self.assertEqual('busy',result['status']); runner.assert_not_called()

class ScheduleTimezoneTest(unittest.TestCase):
    def test_before_jakarta_backup_time_is_not_due(self):
        now=datetime.datetime(2026,10,7,1,14,tzinfo=ZoneInfo('Asia/Jakarta')); self.assertFalse(job.due(now,None,1,15))
    def test_missed_jakarta_schedule_catches_up(self):
        now=datetime.datetime(2026,10,7,9,0,tzinfo=ZoneInfo('Asia/Jakarta')); self.assertTrue(job.due(now,'2026-10-06',1,15))
    def test_successful_day_is_not_repeated(self):
        now=datetime.datetime(2026,10,7,9,0,tzinfo=ZoneInfo('Asia/Jakarta')); self.assertFalse(job.due(now,'2026-10-07',1,15))
