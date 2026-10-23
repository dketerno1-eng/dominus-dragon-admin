"""Run with local PHP server on 8765. Uses throwaway local settings."""
from http.cookiejar import CookieJar
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
import atexit,json,re,subprocess,socket,threading,time,shutil,tempfile,urllib.request,urllib.parse,urllib.error

source=Path(__file__).resolve().parents[1]
local_php=source.parent/'runtime-php/usr/bin/php8.3'
php=local_php if local_php.exists() else Path(shutil.which('php') or 'php')
extension=source.parent/'runtime-php/usr/lib/php/20230831/curl.so'
php_flags=['-n','-d','extension='+str(extension)] if extension.exists() else []
temporary=tempfile.TemporaryDirectory();atexit.register(temporary.cleanup)
root=Path(temporary.name)/'app'
shutil.copytree(source,root,ignore=shutil.ignore_patterns('settings.php','login.php','*-lock.php','install-key.php'))
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
status,html=post({'action':'install','csrf':csrf,'install_key':'installation-fixture','password':'Teste-local-2026','confirm':'Teste-local-2026'})
assert status==200 and 'CENTRAL DE CONSULTA' in html,(status,html[:1000])
csrf=re.search(r'id="csrf" value="([^"]+)',html)[1]
status,raw=post({'action':'save','csrf':csrf,'name':'Oracle • Testes','category':'Testes','username':'admin','password':'api-fixture-secret','url':'http://8.8.8.8:9090','allow_http':'1'})
value=json.loads(raw);assert status==200 and value['ok'],raw
sid=value['saved_id'];assert 'password' not in value['servers'][0]
assert 'api-fixture-secret' not in (root/'private/settings.php').read_text()
print('OK: instalação, sessão, salvar máquina, credencial cifrada e resposta sem senha')
status,raw=post({'action':'save','csrf':csrf,'id':sid,'name':'Oracle • Nova categoria','category':'Clientes','username':'admin','password':'','url':'http://8.8.8.8:9090','allow_http':'1'})
assert json.loads(raw)['servers'][0]['category']=='Clientes'
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
print('INTEGRAÇÃO LOCAL CONCLUÍDA')
