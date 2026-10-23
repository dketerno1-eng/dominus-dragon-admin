<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params(['secure'=>true, 'httponly'=>true, 'samesite'=>'Strict']);
session_start();
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function answer(int $status, array $data): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
$file = __DIR__.'/private/config.php';
if (!is_file($file)) { http_response_code(503); exit('Configuração ausente. Use o ZIP privado gerado pelo instalador da VPS.'); }
$c = require $file;
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(24));
if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 1800) { unset($_SESSION['auth']); }
if (!empty($_SESSION['auth'])) { $_SESSION['seen'] = time(); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Sessão expirada. Atualize a página.'); }
    if (isset($_POST['logout'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
    if (isset($_POST['password'])) {
        // A global lock bounds attempts even when an attacker discards cookies.
        $lock = @fopen(__DIR__.'/private/login-lock.php', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { http_response_code(503); exit('Não foi possível proteger o login. Confira permissão da pasta private.'); }
        $text = stream_get_contents($lock);
        $state = json_decode(substr($text, strlen("<?php exit; ?>\n")), true) ?: ['start'=>time(), 'count'=>0];
        if (time() - (int)$state['start'] > 300) { $state = ['start'=>time(), 'count'=>0]; }
        if ((int)$state['count'] >= 10) {
            $error = 'Limite de tentativas. Aguarde até 5 minutos.';
        } else {
            $state['count']++;
            $password = (string)$_POST['password'];
            $hash = strlen($password) <= 256 ? hash_pbkdf2('sha256', $password, hex2bin($c['password_salt']), 300000, 64) : '';
            if (hash_equals($c['password_hash'], $hash)) {
                session_regenerate_id(true); $_SESSION['auth'] = true; $_SESSION['seen'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(24)); $state = ['start'=>time(), 'count'=>0];
            } else { $error = 'Senha incorreta.'; }
        }
        rewind($lock); ftruncate($lock, 0); fwrite($lock, "<?php exit; ?>\n".json_encode($state)); fflush($lock); flock($lock, LOCK_UN); fclose($lock);
        if (!empty($_SESSION['auth'])) { header('Location: ./'); exit; }
    }
}
if (isset($_GET['users'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { answer(405, ['ok'=>false, 'message'=>'Método não permitido.']); }
    if (empty($_SESSION['auth'])) { answer(401, ['ok'=>false, 'message'=>'Entre novamente no painel.']); }
    if (time() - (int)($_SESSION['last_query'] ?? 0) < 5) { answer(429, ['ok'=>false, 'message'=>'Aguarde 5 segundos entre consultas.']); }
    $_SESSION['last_query'] = time(); session_write_close();
    if (!function_exists('curl_init')) { answer(503, ['ok'=>false, 'message'=>'PHP cURL não está disponível.']); }
    $parts = parse_url($c['agent_url']);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
        answer(503, ['ok'=>false, 'message'=>'Endereço HTTPS inválido na configuração.']);
    }
    $stamp = (string)time(); $nonce = bin2hex(random_bytes(16)); $path = '/v1/users';
    $signature = hash_hmac('sha256', implode("\n", [$stamp,$nonce,'GET',$path]), hex2bin($c['secret']));
    $curl = curl_init(rtrim($c['agent_url'], '/').$path);
    $body = ''; $tooLarge = false;
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>7, CURLOPT_TIMEOUT=>35,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_CAINFO=>__DIR__.'/private/agent-ca.pem',
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-Dominus-Time: '.$stamp,'X-Dominus-Nonce: '.$nonce,'X-Dominus-Signature: '.$signature],
        CURLOPT_WRITEFUNCTION=>function($ch, $chunk) use (&$body, &$tooLarge) {
            if (strlen($body)+strlen($chunk)>4000000) { $tooLarge = true; return 0; }
            $body .= $chunk; return strlen($chunk);
        }]);
    $ok = curl_exec($curl); $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $errno = curl_errno($curl); curl_close($curl);
    if ($ok === false) {
        $hint = $tooLarge ? 'Lista acima de 4 MB.' : (($errno === 60 || $errno === 77) ? 'Certificado não confere. Use agent-ca.pem do ZIP atual e o endereço correto.' : 'Sem conexão HTTPS com a VPS. Confira serviço, porta, firewall da VPS/provedor e saída da hospedagem.');
        answer(502, ['ok'=>false, 'message'=>$hint]);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) { answer(502, ['ok'=>false, 'message'=>'Resposta incompatível: era esperado JSON.']); }
    if ($code !== 200 || empty($data['ok'])) { answer(502, ['ok'=>false, 'message'=>(string)($data['message'] ?? 'Falha na consulta do agente.')]); }
    if (!isset($data['users']) || !is_array($data['users'])) { answer(502, ['ok'=>false, 'message'=>'Lista de usuários ausente.']); }
    answer(200, $data);
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dominus • Consulta</title><link rel="stylesheet" href="style.css"></head>
<body><main><header><div class="brand">D<span>DOMINUS<small>CONSULTA DE USUÁRIOS</small></span></div><span class="badge">SOMENTE LEITURA</span></header>
<?php if (empty($_SESSION['auth'])): ?>
<section class="login"><div class="eyebrow">ACESSO ADMINISTRATIVO</div><h1>Seus usuários.<br>Uma visão clara.</h1><p>Consulte a VPS com segurança.</p><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><label for="password">Senha do painel</label><input id="password" name="password" type="password" required maxlength="256" autocomplete="current-password"><button>Entrar no painel →</button></form><p class="error"><?=esc($error)?></p></section>
<?php else: ?>
<div class="heading"><div><div class="eyebrow">VISÃO DA VPS</div><h1>Usuários da máquina</h1><p id="meta">Faça a primeira consulta para carregar os dados.</p></div><button id="refresh">↻ Consultar agora</button></div>
<div id="error" class="error" role="alert"></div><div id="warnings"></div>
<section class="stats"><article><span>Contas consultadas</span><strong id="total">—</strong></article><article><span>Validade vencida</span><strong id="expired">—</strong></article><article><span>Com conexões informadas &gt; 0</span><strong id="online">—</strong></article></section>
<div class="toolbar"><input id="search" type="search" placeholder="Buscar usuário…" aria-label="Buscar usuário"><span id="count">Nenhuma consulta realizada</span></div>
<div class="tablewrap"><table><thead><tr><th>Usuário</th><th>Validade</th><th>Estado</th><th>Limite</th><th>Conexões</th></tr></thead><tbody id="rows"><tr><td colspan="5">Clique em Consultar agora.</td></tr></tbody></table></div><button id="more" hidden>Mostrar mais 100</button>
<footer><span>Dados de leitura • nenhuma alteração de conta</span><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><button name="logout" value="1" class="secondary">Sair</button></form></footer><script src="app.js" defer></script>
<?php endif; ?></main></body></html>
