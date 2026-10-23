<?php
declare(strict_types=1);
const DATA_PREFIX = "<?php exit; ?>\n";
function private_dir(): string { return dirname(__DIR__).'/private'; }
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_reply(int $status, array $value): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function data_decode(string $raw): array {
    if (substr($raw, 0, strlen(DATA_PREFIX)) !== DATA_PREFIX) { throw new RuntimeException('Arquivo de dados incompatível.'); }
    $value = json_decode(substr($raw, strlen(DATA_PREFIX)), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { throw new RuntimeException('Arquivo de dados inválido.'); }
    return $value;
}
function data_read(string $name): array {
    $path = private_dir().'/'.$name.'.php';
    $handle = @fopen($path, 'rb');
    if (!$handle) { throw new RuntimeException('Não foi possível ler a configuração.'); }
    try {
        if (!flock($handle, LOCK_SH)) { throw new RuntimeException('Não foi possível bloquear a leitura.'); }
        return data_decode(stream_get_contents($handle));
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
function data_update(string $name, callable $change): array {
    $path = private_dir().'/'.$name.'.php';
    $lock = @fopen(private_dir().'/'.$name.'-lock.php', 'c+b');
    if (!$lock) { throw new RuntimeException('A pasta private precisa permitir gravação pelo PHP.'); }
    $temp = null;
    try {
        if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Não foi possível bloquear a gravação.'); }
        $raw = is_file($path) ? file_get_contents($path) : '';
        if ($raw === false) { throw new RuntimeException('Não foi possível ler a configuração.'); }
        $next = $change($raw === '' ? [] : data_decode($raw));
        if (!is_array($next)) { throw new RuntimeException('Dados inválidos.'); }
        $encoded = DATA_PREFIX.json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temp = private_dir().'/.dominus-'.bin2hex(random_bytes(12)).'.php';
        if (file_put_contents($temp, $encoded, LOCK_EX) !== strlen($encoded)) { throw new RuntimeException('Falha ao gravar. Confira o espaço disponível.'); }
        @chmod($temp, 0600);
        if (!rename($temp, $path)) { throw new RuntimeException('Não foi possível concluir a gravação.'); }
        $temp = null;
        return $next;
    } finally {
        if ($temp !== null) { @unlink($temp); }
        flock($lock, LOCK_UN); fclose($lock);
    }
}
function settings(): array { return data_read('settings'); }
function seal(string $plain, string $key): string {
    $iv = random_bytes(12); $tag = '';
    $raw = openssl_encrypt($plain, 'aes-256-gcm', base64_decode($key, true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($raw === false) { throw new RuntimeException('Falha ao proteger credenciais.'); }
    return base64_encode($iv.$tag.$raw);
}
function unseal(string $encoded, string $key): string {
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) { throw new RuntimeException('Credencial inválida.'); }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', base64_decode($key, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) { throw new RuntimeException('Não foi possível recuperar a credencial.'); }
    return $plain;
}
function authenticated(): bool {
    if (empty($_SESSION['auth']) || time() - (int)($_SESSION['seen'] ?? 0) > 1800) { unset($_SESSION['auth']); return false; }
    $_SESSION['seen'] = time(); return true;
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { throw new RuntimeException('Sessão expirada. Atualize a página.'); }
}
function login_attempt(string $password): bool {
    $success = false;
    data_update('login', function(array $state) use ($password, &$success) {
        if (time() - (int)($state['start'] ?? 0) > 300) { $state = ['start'=>time(), 'count'=>0]; }
        if ((int)($state['count'] ?? 0) >= 10) { throw new RuntimeException('Muitas tentativas. Aguarde 5 minutos.'); }
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        $success = strlen($password) <= 256 && password_verify($password, settings()['password_hash']);
        return $success ? ['start'=>time(), 'count'=>0] : $state;
    });
    return $success;
}
function text_field(array $data, string $key, int $min, int $max): string {
    $value = trim((string)($data[$key] ?? ''));
    if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Campo inválido: '.$key.'.'); }
    return $value;
}
function normalize_url(string $url, bool $allowHttp): string {
    $url = rtrim(trim($url), '/'); $u = parse_url($url);
    if (!$u || !isset($u['scheme'], $u['host']) || !in_array($u['scheme'], ['http','https'], true) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || !empty($u['path'])) {
        throw new RuntimeException('Use somente http://IP ou https://dominio, sem caminho.');
    }
    if ($u['scheme'] === 'http' && !$allowHttp) { throw new RuntimeException('Para HTTP, marque a opção de teste.'); }
    $port = $u['port'] ?? ($u['scheme'] === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) { throw new RuntimeException('Porta inválida.'); }
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $u['host']) || strlen($u['host']) > 253) { throw new RuntimeException('Host inválido. Esta versão aceita IPv4 ou domínio com IPv4.'); }
    return $u['scheme'].'://'.strtolower($u['host']).(($u['scheme']==='http' && $port===80) || ($u['scheme']==='https' && $port===443) ? '' : ':'.$port);
}
function public_target(string $url): array {
    $u = parse_url($url); $host = $u['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
    $chosen = null;
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !preg_match('/^(0\.|127\.|169\.254\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.)/', $ip)) { $chosen = $ip; break; }
    }
    if ($chosen === null) { throw new RuntimeException('Informe o IPv4 público da VPS ou domínio que resolva para IPv4 público. 127.0.0.1 e 10.x não servem para acesso externo pela hospedagem.'); }
    return ['url'=>$url, 'host'=>$host, 'ip'=>$chosen, 'port'=>$u['port'] ?? ($u['scheme']==='https' ? 443 : 80)];
}
function dragon_request(array $target, string $path, ?array $payload, ?string $token): array {
    // Allowlists make account mutation routes unreachable from this connector.
    if (!(($path === '/api/auth/login' && $payload !== null && $token === null) || ($path === '/api/users?server_id=local' && $payload === null && $token !== null))) {
        throw new RuntimeException('Operação fora do escopo de consulta.');
    }
    $stage = $payload !== null ? 'login' : 'consulta'; $body = ''; $large = false;
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        if (preg_match('/[\r\n]/', $token) || strlen($token) > 16384) { throw new RuntimeException('Token da API inválido.'); }
        $headers[] = 'X-Session-Token: '.$token;
    }
    $curl = curl_init($target['url'].$path);
    $options = [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROXY=>'', CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE=>[$target['host'].':'.$target['port'].':'.$target['ip']],
        CURLOPT_WRITEFUNCTION=>function($ch, $chunk) use (&$body, &$large) {
            if (strlen($body) + strlen($chunk) > 4000000) { $large = true; return 0; }
            $body .= $chunk; return strlen($chunk);
        }];
    if ($payload !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR); }
    if (!curl_setopt_array($curl, $options)) { curl_close($curl); throw new RuntimeException('Não foi possível configurar o cURL da hospedagem.'); }
    $ok = curl_exec($curl); $errno = curl_errno($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($ok === false) {
        if ($large) { throw new RuntimeException('Resposta acima de 4 MB.'); }
        if ($errno === 60 || $errno === 77) { throw new RuntimeException('Certificado HTTPS inválido ou não confiável. Configure certificado válido na VPS.'); }
        throw new RuntimeException('Falha de conexão no '.$stage.' (cURL '.$errno.'). Confira IP público, porta cadastrada, firewall da VPS, regras Oracle e saída de rede da hospedagem.');
    }
    if ($status >= 300 && $status < 400) { throw new RuntimeException('A API redirecionou o '.$stage.'. Cadastre o endereço final HTTP/HTTPS.'); }
    if ($status === 401 || $status === 403) { throw new RuntimeException('HTTP '.$status.' no '.$stage.': confira usuário, senha e permissão no Dragon.'); }
    if ($status === 404) { throw new RuntimeException('HTTP 404 no '.$stage.': esta porta não disponibiliza a rota esperada da API Dragon.'); }
    if ($status < 200 || $status >= 300) { throw new RuntimeException('API retornou HTTP '.$status.' no '.$stage.'.'); }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { throw new RuntimeException('O '.$stage.' retornou HTML ou resposta incompatível com JSON. Confira a porta da API.'); }
    return $decoded;
}
function summarize_users(array $users): array {
    // Native arrays and associative JSON objects must not be confused.
    if ($users !== [] && array_keys($users) !== range(0, count($users)-1)) { throw new RuntimeException('A API retornou objeto em vez de lista de usuários.'); }
    $rows = []; $summary = ['total'=>0, 'valid'=>0, 'expired'=>0, 'unknown'=>0, 'active_accounts'=>0, 'connections_known'=>false];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($users as $u) {
        if (!is_array($u) || !isset($u['username']) || !is_string($u['username'])) { throw new RuntimeException('Cadastro em formato incompatível.'); }
        $expires = isset($u['expires_at']) && is_string($u['expires_at']) ? substr($u['expires_at'], 0, 100) : null;
        $status = 'unknown';
        // Only dates with an explicit timezone are classified.
        if ($expires && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $expires)) {
            try { $date = new DateTimeImmutable($expires); $errors = DateTimeImmutable::getLastErrors();
                if (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) { $status = $date <= $now ? 'expired' : 'valid'; }
            } catch (Exception $ex) { }
        }
        $limit = isset($u['max_connections']) && is_int($u['max_connections']) && $u['max_connections'] >= 0 ? $u['max_connections'] : null;
        $active = isset($u['active_conns']) && is_int($u['active_conns']) && $u['active_conns'] >= 0 ? $u['active_conns'] : null;
        $summary[$status]++;
        if ($active !== null) { $summary['connections_known'] = true; if ($active > 0) { $summary['active_accounts']++; } }
        $rows[] = ['username'=>substr($u['username'], 0, 128), 'expires_at'=>$expires, 'status'=>$status, 'max_connections'=>$limit, 'active_conns'=>$active];
    }
    usort($rows, function($a,$b) { return strcasecmp($a['username'],$b['username']); });
    $summary['total'] = count($rows);
    return ['users'=>$rows, 'summary'=>$summary];
}
function dragon_inventory(array $server, string $key, ?callable $transport=null, ?array $target=null): array {
    $transport = $transport ?? 'dragon_request';
    $target = $target ?? public_target($server['url']);
    $login = $transport($target, '/api/auth/login', ['username'=>$server['username'], 'password'=>unseal($server['password'], $key)], null);
    if (empty($login['token']) || !is_string($login['token'])) { throw new RuntimeException('Login não retornou token compatível.'); }
    $users = $transport($target, '/api/users?server_id=local', null, $login['token']);
    return summarize_users($users) + ['collected_at'=>gmdate('c'), 'server'=>['id'=>$server['id'], 'name'=>$server['name'], 'category'=>$server['category'], 'url'=>$server['url']]];
}
function public_servers(array $servers): array {
    return array_map(function($s) { return array_intersect_key($s, array_flip(['id','name','category','url','username','created_at'])); }, $servers);
}
