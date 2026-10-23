import hashlib
import hmac
import importlib.util
import json
from pathlib import Path
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('reader', Path(__file__).resolve().parents[1]/'agent/reader.py')
r = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(r)

class InventoryTests(unittest.TestCase):
    def test_linux_filters_and_never_exposes_hashes(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            (p/'passwd').write_text('root:x:0:0:root:/root:/bin/bash\nalice:x:1000:1000::/home/alice:/bin/false\nbob:x:1001:1001::/home/bob:/bin/bash\nnobody:x:65534:65534::/:/bin/false\n')
            (p/'shadow').write_text('alice:$6$SECRET_HASH:1:0:99999:7::1:\nbob:!:1:0:99999:7:::\n')
            (p/'defs').write_text('UID_MIN 1000\n')
            users, warnings = r.linux_users(p/'passwd', p/'shadow', p/'defs')
            self.assertEqual([u['username'] for u in users], ['alice', 'bob'])
            self.assertEqual(users[0]['status'], 'vencido no Linux')
            self.assertEqual(users[1]['status'], 'senha bloqueada no Linux')
            self.assertIsNone(users[0]['active_conns'])
            self.assertNotIn('SECRET', json.dumps(users))
    def test_missing_shadow_not_assumed_valid(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d); (p/'passwd').write_text('alice:x:1000:1000::/:/bin/bash\n')
            rows, warnings = r.linux_users(p/'passwd', p/'absent', p/'absent')
            self.assertEqual(rows[0]['status'], 'não informado')
            self.assertTrue(any('Sem acesso' in w for w in warnings))
    def test_dragon_strips_sensitive_and_preserves_unknown(self):
        class Response:
            def __init__(self, data): self.data = data
            def __enter__(self): return self
            def __exit__(self,*a): pass
            def read(self, _): return json.dumps(self.data).encode()
        class Opener:
            def __init__(self): self.paths=[]
            def open(self, req, timeout):
                self.paths.append((req.get_method(), req.full_url))
                return Response({'token':'session'}) if req.get_method()=='POST' else Response([{'username':'alice','password':'secret','totp_secret':'private','max_connections':2,'expires_at':'2099-01-01T00:00:00Z'}])
        opener=Opener()
        with patch.object(r.urllib.request,'build_opener',return_value=opener):
            users,_=r.dragon_users({'dragon_url':'http://127.0.0.1:9090'})
        self.assertEqual(len(opener.paths),2)
        self.assertTrue(opener.paths[0][1].endswith('/api/auth/login'))
        self.assertEqual(opener.paths[1][0],'GET')
        self.assertNotIn('secret',json.dumps(users))
        self.assertIsNone(users[0]['active_conns'])
    def test_invalid_source_and_remote_dragon_rejected(self):
        with self.assertRaises(r.ReadError):r.snapshot({'source':'invented'})
        with self.assertRaises(r.ReadError):r.dragon_users({'dragon_url':'http://evil.example'})

class AuthTests(unittest.TestCase):
    secret = 'ab'*32
    def headers(self, stamp=None, nonce='12'*16, path='/v1/users'):
        stamp = str(stamp or int(time.time()))
        sig = hmac.new(bytes.fromhex(self.secret), '\n'.join([stamp,nonce,'GET',path]).encode(), hashlib.sha256).hexdigest()
        return {'X-Dominus-Time':stamp,'X-Dominus-Nonce':nonce,'X-Dominus-Signature':sig}
    def test_valid_then_replay_rejected(self):
        auth = r.Auth(self.secret); headers=self.headers()
        self.assertTrue(auth.verify(headers,'/v1/users'))
        self.assertFalse(auth.verify(headers,'/v1/users'))
    def test_expired_and_tampered_rejected(self):
        auth = r.Auth(self.secret)
        self.assertFalse(auth.verify(self.headers(int(time.time())-1000),'/v1/users'))
        self.assertFalse(auth.verify(self.headers(),'/v1/other'))
    def test_concurrent_nonce_one_success(self):
        auth=r.Auth(self.secret); headers=self.headers(); results=[]
        threads=[threading.Thread(target=lambda:results.append(auth.verify(headers,'/v1/users'))) for _ in range(20)]
        for t in threads:t.start()
        for t in threads:t.join()
        self.assertEqual(sum(results),1)
    def test_http_read_route_and_mutations_blocked(self):
        server = r.Server(('127.0.0.1',0),r.Handler,{'source':'linux','secret':self.secret})
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        base='http://127.0.0.1:'+str(server.server_port)
        try:
            with patch.object(r,'snapshot',return_value={'ok':True,'users':[]}):
                req=urllib.request.Request(base+'/v1/users',headers=self.headers())
                with urllib.request.urlopen(req) as resp:self.assertTrue(json.load(resp)['ok'])
            for method,path,expected in [('GET','/v1/users',401),('POST','/v1/users',405),('DELETE','/v1/users',405),('GET','/v1/command',404)]:
                with self.assertRaises(urllib.error.HTTPError) as ctx:
                    urllib.request.urlopen(urllib.request.Request(base+path,method=method))
                self.assertEqual(ctx.exception.code,expected)
        finally:server.shutdown();server.server_close();thread.join()

if __name__=='__main__':unittest.main()
