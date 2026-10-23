<?php
require dirname(__DIR__).'/app/core.php';
function check($condition, $label) { if (!$condition) { throw new RuntimeException('FALHOU: '.$label); } echo 'OK: '.$label."\n"; }
function rejects(callable $fn, string $label) { try { $fn(); } catch (RuntimeException $ex) { echo 'OK: '.$label."\n"; return; } throw new RuntimeException('FALHOU: '.$label); }
$key = base64_encode(random_bytes(32));
$cipher = seal('senha-de-teste', $key);
check(unseal($cipher,$key)==='senha-de-teste', 'credencial criptografada reversível');
check(strpos($cipher,'senha-de-teste')===false, 'texto da senha não persiste diretamente');
rejects(function() use ($cipher) { unseal($cipher,base64_encode(random_bytes(32))); }, 'chave incorreta rejeitada');
check(normalize_url('http://8.8.8.8:80/', true)==='http://8.8.8.8', 'normalização HTTP 80');
check(normalize_url('https://example.com:443', false)==='https://example.com', 'normalização HTTPS 443');
check(normalize_url('http://8.8.8.8:9090', true)==='http://8.8.8.8:9090', 'API na porta 9090');
check(normalize_url('https://example.com:9443', false)==='https://example.com:9443', 'HTTPS em porta personalizada');
foreach (['http://user:pass@example.com','http://example.com/api','file:///etc/passwd','http://example.com?x=1'] as $url) {
    rejects(function() use ($url) { normalize_url($url,true); }, 'URL não permitida: '.$url);
}
rejects(function() { normalize_url('http://example.com',false); }, 'HTTP exige opção explícita');
foreach (['127.0.0.1','10.0.0.235','169.254.169.254','100.64.0.1','0.0.0.0'] as $ip) { rejects(function() use ($ip) { public_target('http://'.$ip); }, 'endereço não público: '.$ip); }
$input = [
 ['username'=>'Darlam', 'expires_at'=>'2030-06-20T01:48:00Z', 'max_connections'=>0, 'active_conns'=>0,'password'=>'SHOULD_NOT_LEAK'],
 ['username'=>'vencido','expires_at'=>'2000-01-01T00:00:00Z','max_connections'=>2,'active_conns'=>1],
 ['username'=>'sem_data'], ['username'=>'data_errada','expires_at'=>'2030-02-30T00:00:00Z'],
 ['username'=>'sem_fuso','expires_at'=>'2030-01-01T00:00:00']
];
$result=summarize_users($input);
check($result['summary']['total']===5 && $result['summary']['valid']===1 && $result['summary']['expired']===1 && $result['summary']['unknown']===3, 'resumo distingue validade ausente e inválida');
$darlam = array_values(array_filter($result['users'],function($u){return $u['username']==='Darlam';}))[0];
check($darlam['max_connections']===0 && $darlam['active_conns']===0, 'zero preservado sem inventar ilimitado');
check(strpos(json_encode($result),'SHOULD_NOT_LEAK')===false,'somente campos da consulta');
check(summarize_users([])['summary']['total']===0,'lista vazia válida');
rejects(function(){summarize_users(['users'=>[]]);}, 'objeto não é lista');
rejects(function(){summarize_users([['username'=>null]]);}, 'registro incompatível rejeitado');
$server=['id'=>'test','name'=>'Teste','category'=>'Testes','username'=>'admin','url'=>'http://8.8.8.8','password'=>seal('teste',$key)];
$calls=[];
$transport=function($target,$path,$payload,$token) use (&$calls,$input) { $calls[]=[$path,$payload,$token];return $payload!==null ? ['token'=>'session-test'] : $input; };
$inventory=dragon_inventory($server,$key,$transport,['url'=>'http://8.8.8.8']);
check(count($calls)===2 && $calls[0][0]==='/api/auth/login' && $calls[1][0]==='/api/users?server_id=local', 'apenas login e leitura de usuários');
check($calls[1][2]==='session-test' && $calls[0][1]['password']==='teste','token e credenciais aplicados no contrato validado');
check(!isset($inventory['server']['password']) && !isset(public_servers([$server])[0]['password']),'credenciais fora das respostas');
rejects(function(){dragon_request([], '/api/users/create', [], 'token');},'rota de alteração bloqueada');
check(data_decode(DATA_PREFIX.'{"ok":true}')['ok'],'formato de dados PHP protegido');
rejects(function(){data_decode('{"ok":true}');},'formato sem prefixo rejeitado');
echo "TODOS OS TESTES PASSARAM\n";
