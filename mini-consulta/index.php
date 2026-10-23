<?php
declare(strict_types=1);
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
ini_set('display_errors', '0'); ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('dominus_mini');
session_set_cookie_params(['secure'=>$https, 'httponly'=>true, 'samesite'=>'Strict', 'path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])), '/').'/']);
if (!session_start()) { http_response_code(503); exit('O PHP não conseguiu iniciar a sessão. Confira a configuração de sessões no cPanel.'); }
require __DIR__.'/app/core.php';
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(24));
$installed = sql_configured();
$legacy = !$installed && is_file(private_dir().'/settings.php');
$requirements = [];
foreach (['curl_init'=>'cURL', 'openssl_encrypt'=>'OpenSSL', 'password_hash'=>'password_hash'] as $fn=>$label) { if (!function_exists($fn)) { $requirements[] = $label; } }
if (!class_exists('PDO') || !in_array('mysql',PDO::getAvailableDrivers(),true)) { $requirements[] = 'PDO MySQL (pdo_mysql)'; }
if (!is_writable(private_dir())) { $requirements[] = 'permissão de gravação na pasta private'; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        csrf_check();
        if ($requirements) { throw new RuntimeException('Requisito ausente: '.implode(', ', $requirements)); }
        if ($action === 'install' && !$installed) {
            if (!is_file(private_dir().'/install-key.php')) { throw new RuntimeException('Use o ZIP preparado com chave de instalação.'); }
            $expected = require private_dir().'/install-key.php';
            if (!hash_equals($expected, trim((string)($_POST['install_key'] ?? '')))) { throw new RuntimeException('Chave de instalação incorreta.'); }
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 8 || strlen($password) > 256 || $password !== (string)($_POST['confirm'] ?? '')) { throw new RuntimeException('Use senha com pelo menos 8 caracteres e confirme.'); }
            install_sql($_POST,$password);
            @unlink(private_dir().'/install-key.php');
            session_regenerate_id(true); $_SESSION['auth'] = true; $_SESSION['seen'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: ./'); exit;
        }
        if (!$installed) { throw new RuntimeException('Conclua a instalação.'); }
        if ($action === 'login') {
            if (!login_attempt((string)($_POST['password'] ?? ''))) { throw new RuntimeException('Senha do mini painel incorreta.'); }
            session_regenerate_id(true); $_SESSION['auth'] = true; $_SESSION['seen'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: ./'); exit;
        }
        if (!authenticated()) { json_reply(401, ['ok'=>false, 'message'=>'Entre novamente no painel.']); }
        if ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
        if ($action === 'save') {
            $id = (string)($_POST['id'] ?? '');
            if ($id !== '' && !preg_match('/^[a-f0-9]{24}$/D', $id)) { throw new RuntimeException('Máquina inválida.'); }
            $name = text_field($_POST, 'name', 1, 80); $category = text_field($_POST, 'category', 1, 50);
            $username = text_field($_POST, 'username', 1, 128);
            $url = normalize_url(text_field($_POST, 'url', 7, 300), isset($_POST['allow_http']));
            public_target($url); // validate before saving, no network request to the API yet
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) > 256) { throw new RuntimeException('Senha da API acima do limite.'); }
            $savedId = $id ?: bin2hex(random_bytes(12));
            $settings = data_update('settings', function($s) use ($id,$savedId,$name,$category,$url,$username,$password) {
                $index = null;
                foreach ($s['servers'] as $i=>$server) { if ($server['id'] === $id) { $index = $i; break; } }
                if ($id !== '' && $index === null) { throw new RuntimeException('Máquina não encontrada.'); }
                if ($index === null && count($s['servers']) >= 100) { throw new RuntimeException('Limite de 100 máquinas nesta versão.'); }
                if ($password === '' && $index === null) { throw new RuntimeException('Informe a senha da API.'); }
                $old = $index !== null ? $s['servers'][$index] : null;
                if ($old && $password === '' && ($old['url'] !== $url || $old['username'] !== $username)) { throw new RuntimeException('Ao mudar endereço ou usuário, informe novamente a senha da API.'); }
                $server = ['id'=>$savedId, 'name'=>$name, 'category'=>$category, 'url'=>$url, 'username'=>$username,
                    'password'=>$password !== '' ? seal($password,$s['key']) : $old['password'], 'created_at'=>$old['created_at'] ?? gmdate('c')];
                if ($index === null) { $s['servers'][] = $server; } else { $s['servers'][$index] = $server; }
                return $s;
            });
            json_reply(200, ['ok'=>true, 'servers'=>public_servers($settings['servers']), 'saved_id'=>$savedId]);
        }
        if ($action === 'remove') {
            $id = (string)($_POST['id'] ?? '');
            $settings = data_update('settings', function($s) use ($id) { $s['servers'] = array_values(array_filter($s['servers'], function($v) use ($id) { return $v['id'] !== $id; })); return $s; });
            json_reply(200, ['ok'=>true, 'servers'=>public_servers($settings['servers'])]);
        }
        if ($action === 'query') {
            $id = (string)($_POST['id'] ?? ''); $s = settings(); $server = null;
            foreach ($s['servers'] as $candidate) { if ($candidate['id'] === $id) { $server = $candidate; break; } }
            if (!$server) { throw new RuntimeException('Máquina não encontrada.'); }
            if (time() - (int)($_SESSION['queries'][$id] ?? 0) < 3) { json_reply(429, ['ok'=>false, 'message'=>'Aguarde 3 segundos antes de consultar novamente esta máquina.']); }
            $_SESSION['queries'][$id] = time(); session_write_close();
            $result = dragon_inventory($server, $s['key']);
            json_reply(200, ['ok'=>true] + $result);
        }
        throw new RuntimeException('Ação não permitida.');
    } catch (Throwable $ex) {
        $message = $ex instanceof RuntimeException ? $ex->getMessage() : 'Falha interna. Confira a configuração e os requisitos do PHP.';
        if (in_array($action, ['save','remove','query'], true)) { json_reply(400, ['ok'=>false, 'message'=>$message]); }
        $error = $message;
    }
}
$logged = $installed && authenticated();
try { $servers = $logged ? public_servers(settings()['servers']) : []; }
catch (Throwable $ex) { http_response_code(503); exit(e($ex instanceof RuntimeException ? $ex->getMessage() : 'Não foi possível ler as tabelas MySQL. Confira private/db.php e restaure o banco do painel.')); }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dominus • Mini Consulta</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main><header><a href="./" class="brand"><span class="mark">D</span><span>DOMINUS<small>MINI CONSULTA</small></span></a><span class="badge"><i></i> Somente leitura</span></header>
<?php if (!$logged): ?>
<section class="auth"><div class="eyebrow"><?= $installed ? 'ACESSO AO PAINEL' : 'PRIMEIRO ACESSO' ?></div><h1>Suas máquinas.<br>Seus usuários.</h1><p><?= $installed ? 'Entre para consultar os cadastros do Dragon.' : 'Configure o banco MySQL da Alphimedia e o acesso ao mini painel.' ?></p>
<?php if ($requirements): ?><p class="notice error">Requisito ausente: <?=e(implode(', ', $requirements))?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?=e($error)?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=$installed?'login':'install'?>">
<?php if (!$installed): ?><?php if ($legacy): ?><p class="notice">Versão anterior encontrada. Informe a senha atual do mini painel para importar as máquinas e credenciais para o MySQL.</p><?php endif; ?><label>Chave de instalação<input name="install_key" required autocomplete="off" placeholder="Veja CHAVE_INSTALACAO.txt"></label><?php endif; ?>
<label><?=$installed?'Senha do mini painel':($legacy?'Senha atual do mini painel':'Crie a senha do mini painel')?><input name="password" type="password" required minlength="<?=$installed?1:8?>" maxlength="256" autocomplete="<?=$installed?'current-password':'new-password'?>"></label>
<?php if (!$installed): ?><label>Confirme a senha<input name="confirm" type="password" required minlength="8" maxlength="256" autocomplete="new-password"></label><?php endif; ?>
<?php if (!$installed): ?>
<div class="db-fields"><span class="eyebrow">BANCO MYSQL • CPANEL</span>
<label>Host do MySQL<input name="db_host" value="localhost" required maxlength="253" autocomplete="off"></label>
<label>Porta do MySQL<input name="db_port" type="number" value="3306" min="1" max="65535" required></label>
<label>Nome completo do banco<input name="db_name" required maxlength="128" placeholder="contacpanel_dominus" autocomplete="off"></label>
<label>Usuário completo do banco<input name="db_user" required maxlength="128" placeholder="contacpanel_usuario" autocomplete="off"></label>
<label>Senha do banco<input name="db_password" type="password" maxlength="512" autocomplete="new-password"></label>
<p class="field-note">Crie o banco e associe o usuário com todas as permissões no cPanel. O instalador cria as tabelas automaticamente.</p></div>
<?php endif; ?>
<button class="primary" <?=$requirements?'disabled':''?>><?=$installed?'Entrar no painel':'Instalar e entrar'?> <span>→</span></button></form><small>A senha do painel é independente da senha de cada VPS.</small></section>
<?php else: ?>
<input type="hidden" id="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" id="server-data" value="<?=e(json_encode($servers, JSON_UNESCAPED_UNICODE))?>">
<div class="heading"><div><div class="eyebrow">CENTRAL DE CONSULTA</div><h1>Usuários do Dragon</h1><p>Os usuários são consultados automaticamente ao abrir e ao trocar de máquina.</p></div><button class="primary" id="add"><span>＋</span> Adicionar máquina</button></div>
<div id="message" class="notice" role="status" hidden></div>
<div class="workspace"><aside><div class="side-title">MINHAS MÁQUINAS <span id="machine-count">0</span></div><input type="search" id="machine-search" placeholder="Filtrar máquinas…" aria-label="Filtrar máquinas"><div id="machines"></div><div class="side-note">A consulta faz login na API de cada máquina. Nenhum cadastro da VPS é alterado.</div></aside>
<section class="content"><div class="machine-head"><div><span class="eyebrow" id="selected-category">SELECIONE UMA MÁQUINA</span><h2 id="selected-name">Pronto para consultar</h2><p id="selected-url">Adicione a primeira VPS para começar.</p></div><div class="actions"><button id="edit" class="secondary" disabled>Editar máquina</button><button id="query" class="primary" disabled>↻ Atualizar agora</button></div></div>
<div class="stats"><article><span>Total de usuários</span><strong id="total">—</strong></article><article><span>Validade vigente</span><strong id="valid">—</strong></article><article><span>Validade vencida</span><strong id="expired">—</strong></article><article><span>Contas com conexões</span><strong id="online">—</strong></article></div>
<div class="toolbar"><input type="search" id="user-search" placeholder="Buscar usuário…" aria-label="Buscar usuário"><select id="status-filter" aria-label="Filtrar validade"><option value="all">Todas as validades</option><option value="valid">Vigentes</option><option value="expired">Vencidas</option><option value="unknown">Não informadas</option></select><span id="row-count">Sem consulta</span></div>
<div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Validade</th><th>Estado</th><th>Limite</th><th>Conexões</th></tr></thead><tbody id="rows"><tr><td colspan="5" class="empty">A consulta inicia automaticamente ao selecionar uma máquina.</td></tr></tbody></table></div>
<button id="more" class="secondary" hidden>Mostrar mais 100</button><div class="result-footer"><span id="updated">Nenhuma consulta realizada.</span><span>Limite 0: valor da API, sem interpretação.</span></div><p class="footnote">Validade é a data informada pelo Dragon. O estado de validade não comprova autenticação ou acesso. Conexões não informadas aparecem como “—”.</p></section></div>
<dialog id="editor"><form id="machine-form"><div class="dialog-head"><div><span class="eyebrow">CONFIGURAÇÃO</span><h2 id="editor-title">Adicionar máquina</h2></div><button type="button" id="close-editor" class="icon-button" aria-label="Fechar">×</button></div><input type="hidden" name="id" id="machine-id">
<div class="form-grid"><label>Nome da máquina<input name="name" id="machine-name" required maxlength="80" placeholder="VPS Oracle • Teste"></label><label>Categoria<input name="category" id="machine-category" required maxlength="50" value="Testes" placeholder="Testes, clientes, região…"></label></div>
<label>Endereço público da API<input name="url" id="machine-url" required maxlength="300" placeholder="http://IP-PUBLICO:9090 ou https://api.seudominio.com"></label><p class="field-note">Use o IP público e a porta da API. Na sua VPS de teste: http://IP-PUBLICO:9090. A hospedagem e o firewall da VPS precisam permitir a conexão.</p>
<label class="check"><input name="allow_http" id="allow-http" type="checkbox" value="1"> Permitir HTTP para esta máquina de teste</label><p class="field-note">Em HTTP, as credenciais trafegam sem criptografia entre a hospedagem e a VPS.</p>
<div class="form-grid"><label>Usuário admin do Dragon<input name="username" id="machine-username" required maxlength="128" value="admin" autocomplete="off"></label><label>Senha da API<input name="password" id="machine-password" type="password" maxlength="256" autocomplete="new-password" placeholder="Senha do painel Dragon"></label></div>
<p class="field-note">As credenciais ficam salvas no MySQL para consultas futuras. Ao editar, deixe a senha vazia para manter. Se mudar endereço ou usuário, informe a senha novamente.</p><div id="editor-error" class="notice error" role="alert" hidden></div><div class="dialog-footer"><button type="button" id="remove" class="danger" hidden>Remover do painel</button><button class="primary" id="save">Salvar máquina</button></div></form></dialog>
<footer><span>DOMINUS • Mini Consulta SQL v2.0 · Credenciais salvas em MySQL</span><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><button name="action" value="logout" class="secondary">Sair</button></form></footer><script src="assets/app.js?v=2.0" defer></script>
<?php endif; ?></main></body></html>
