"""SQL integration. Requires DOMINUS_TEST_MYSQL_PORT and a disposable MySQL user with CREATE DATABASE. Use --legacy to test migration."""
from http.cookiejar import CookieJar
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
import atexit,json,os,sys,secrets,re,subprocess,socket,threading,time,shutil,tempfile,urllib.request,urllib.parse,urllib.error

source=Path(__file__).resolve().parents[1]
local_php=source.parent/'runtime-php/usr/bin/php8.3'
php=local_php if local_php.exists() else Path(shutil.which('php') or 'php')
extension=source.parent/'runtime-php/usr/lib/php/20230831/curl.so'
php_flags=['-n']+sum((['-d','extension='+str(extension.parent/name)] for name in ['pdo.so','mysqlnd.so','pdo_mysql.so','curl.so']),[]) if extension.exists() else []
temporary=tempfile.TemporaryDirectory();atexit.register(temporary.cleanup)
root=Path(temporary.name)/'app'
shutil.copytree(source,root,ignore=shutil.ignore_patterns('settings.php','login.php','db.php','db-pending.php','*-lock.php','install-key.php'))
db_host=os.environ.get('DOMINUS_TEST_MYSQL_HOST','127.0.0.1')
db_port=int(os.environ['DOMINUS_TEST_MYSQL_PORT'])
db_user=os.environ.get('DOMINUS_TEST_MYSQL_USER','root')
db_password=os.environ.get('DOMINUS_TEST_MYSQL_PASSWORD','')
db_name='dominus_test_'+secrets.token_hex(8)
def php_run(code,payload=None):
 result=subprocess.run([str(php)]+php_flags+['-r',code],cwd=root,input=json.dumps(payload or {}),capture_output=True,text=True)
 if result.returncode:raise RuntimeError(result.stdout+result.stderr)
 return result.stdout
def sql(query,params=None):
 code="$c=json_decode(file_get_contents('php://stdin'),true);$db=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$s=$db->prepare($c['query']);$s->execute($c['params']);echo json_encode($s->columnCount() ? $s->fetchAll(PDO::FETCH_ASSOC) : []);"
 return json.loads(php_run(code,{'host':db_host,'port':db_port,'user':db_user,'password':db_password,'query':query,'params':params or []}))
sql('CREATE DATABASE `'+db_name+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
atexit.register(lambda:sql('DROP DATABASE `'+db_name+'`'))
legacy='--legacy' in sys.argv
if legacy:
 php_run("require 'app/core.php';$key=base64_encode(random_bytes(32));file_data_update('settings',function()use($key){return ['version'=>'1.0.0','password_hash'=>password_hash('Teste-local-2026',PASSWORD_DEFAULT),'key'=>$key,'servers'=>[['id'=>'aaaaaaaaaaaaaaaaaaaaaaaa','name'=>'VPS anterior','category'=>'Legado','url'=>'http://1.1.1.1:9090','username'=>'admin','password'=>seal('legacy-password',$key),'created_at'=>'2026-01-01T00:00:00Z']]];});")
else:
 # Verify the separately supplied SQL can be imported before browser setup.
 # sql() opens a connection each time: use explicit qualified connection for schema import.
 php_run("$c=json_decode(file_get_contents('php://stdin'),true);$db=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);foreach(explode(';',file_get_contents('database/schema.sql'))as $statement){if(trim($statement)!=='')$db->exec($statement);}",{'host':db_host,'port':db_port,'name':db_name,'user':db_user,'password':db_password})
probe=socket.socket();probe.bind(('127.0.0.1',0));port=probe.getsockname()[1];probe.close()
base='http://127.0.0.1:'+str(port)+'/'
process=subprocess.Popen([str(php)]+php_flags+['-d','session.save_path='+temporary.name,'-S','127.0.0.1:'+str(port),'-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
atexit.register(process.terminate)
for _ in range(40):
 try:
  connection=socket.create_connection(('127.0.0.1',port),.2);connection.close();break
 except OSError:time.sleep(.1)
else:raise RuntimeError('PHP não iniciou')
(root/'private/install-key.php').write_text("<?php return 'installation-fixture';")
jar=CookieJar(); web=urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar))
def page():
 with web.open(base) as r:return r.read().decode()
def post(data):
 try:
  with web.open(base,data=urllib.parse.urlencode(data).encode()) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
html=page();csrf=re.search(r'name="csrf" value="([^"]+)',html)[1]
status,html=post({'action':'install','csrf':csrf,'install_key':'installation-fixture','password':'Teste-local-2026','confirm':'Teste-local-2026','db_host':db_host,'db_port':str(db_port),'db_name':db_name,'db_user':db_user,'db_password':db_password})
assert status==200 and 'CENTRAL DE CONSULTA' in html,(status,html[:1000])
csrf=re.search(r'id="csrf" value="([^"]+)',html)[1]
status,raw=post({'action':'save','csrf':csrf,'name':'Oracle • Testes','category':'Testes','username':'admin','password':'api-fixture-secret','url':'http://8.8.8.8:9090','allow_http':'1'})
value=json.loads(raw);assert status==200 and value['ok'],raw
sid=value['saved_id'];assert 'password' not in value['servers'][0]
assert (root/'private/db.php').exists()
assert 'api-fixture-secret' not in (root/'private/db.php').read_text()
rows=sql('SELECT api_password_encrypted FROM `'+db_name+'`.dc_machines WHERE id=?',[sid])
assert rows and 'api-fixture-secret' not in rows[0]['api_password_encrypted']
restored=json.loads(php_run("require 'app/core.php';$s=settings();echo json_encode(array_map(function($v)use($s){return ['id'=>$v['id'],'password'=>unseal($v['password'],$s['key'])];},$s['servers']));"))
assert any(v['id']==sid and v['password']=='api-fixture-secret' for v in restored)
if legacy:
 assert any(v['id']=='aaaaaaaaaaaaaaaaaaaaaaaa' and v['password']=='legacy-password' for v in restored)
 assert (root/'private/settings.php').exists()
 print('OK: migração preserva máquinas, chave e senha anterior')
else:
 assert not (root/'private/settings.php').exists()
print('OK: senha persistida no MySQL e reutilizada por novo processo PHP')
print('OK: instalação, sessão, salvar máquina, credencial cifrada e resposta sem senha')
status,raw=post({'action':'save','csrf':csrf,'id':sid,'name':'Oracle • Nova categoria','category':'Clientes','username':'admin','password':'','url':'http://8.8.8.8:9090','allow_http':'1'})
assert any(v['id']==sid and v['category']=='Clientes' for v in json.loads(raw)['servers'])
status,raw=post({'action':'save','csrf':csrf,'id':sid,'name':'Oracle','category':'Testes','username':'admin','password':'','url':'http://1.1.1.1:9090','allow_http':'1'})
assert status==400 and 'informe novamente' in json.loads(raw)['message']
status,raw=post({'action':'remove','csrf':'invalid','id':sid});assert status==400
print('OK: editar mantém senha; mudança de destino exige senha; CSRF rejeitado')
status,_=post({'action':'logout','csrf':csrf});assert status==200
status,raw=post({'action':'query','csrf':csrf,'id':sid});assert status==400 or status==401
html=page();csrf=re.search(r'name="csrf" value="([^"]+)',html)[1]
status,html=post({'action':'login','csrf':csrf,'password':'incorrect'});assert 'incorreta' in html
status,html=post({'action':'login','csrf':csrf,'password':'Teste-local-2026'});assert 'CENTRAL DE CONSULTA' in html
print('OK: logout, consulta sem sessão bloqueada, login incorreto e login correto')

class FakeDragon(BaseHTTPRequestHandler):
 def log_message(self,*a):pass
 def send(self,obj,code=200):
  raw=json.dumps(obj).encode();self.send_response(code);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
 def do_POST(self):
  assert self.path=='/api/auth/login'
  payload=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
  assert payload=={'username':'admin','password':'fixture-password'}
  self.send({'token':'fixture-token'})
 def do_GET(self):
  assert self.path=='/api/users?server_id=local' and self.headers['X-Session-Token']=='fixture-token'
  self.send([{'username':'Darlam','expires_at':'2030-06-20T01:48:00Z','max_connections':0,'active_conns':0,'password':'NEVER_TRANSMIT'}])
server=ThreadingHTTPServer(('127.0.0.1',0),FakeDragon);thread=threading.Thread(target=server.serve_forever);thread.start()
try:
 code="""require 'app/core.php'; $key=base64_encode(random_bytes(32)); $server=['id'=>'fake','name'=>'Local','category'=>'Teste','url'=>'http://127.0.0.1:PORT','username'=>'admin','password'=>seal('fixture-password',$key)]; echo json_encode(dragon_inventory($server,$key,null,['url'=>$server['url'],'host'=>'127.0.0.1','ip'=>'127.0.0.1','port'=>PORT]));""".replace('PORT',str(server.server_port))
 result=subprocess.run([str(php)]+php_flags+['-r',code],cwd=root,capture_output=True,text=True,check=True)
 data=json.loads(result.stdout);assert data['users'][0]['username']=='Darlam' and data['summary']['valid']==1
 assert data['users'][0]['max_connections']==0 and 'NEVER_TRANSMIT' not in result.stdout
 print('OK: cURL real → API simulada, login/token/lista e filtragem dos dados')
finally:server.shutdown();server.server_close();thread.join()
csrf=re.search(r'id="csrf" value="([^"]+)',page())[1]
status,raw=post({'action':'remove','csrf':csrf,'id':sid});assert status==200
assert not sql('SELECT id FROM `'+db_name+'`.dc_machines WHERE id=?',[sid])
print('OK: remoção local persistida no SQL')
print('INTEGRAÇÃO SQL CONCLUÍDA: '+('MIGRAÇÃO' if legacy else 'INSTALAÇÃO NOVA'))
