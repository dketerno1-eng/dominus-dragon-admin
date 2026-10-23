<?php
declare(strict_types=1);
function sql_configured(): bool { return is_file(private_dir().'/db.php'); }
function sql_config(): array {
    $c = require private_dir().'/db.php';
    if (!is_array($c) || strlen(base64_decode($c['key'] ?? '', true) ?: '') !== 32) { throw new RuntimeException('Configuração do banco inválida. Restaure private/db.php do seu backup.'); }
    return $c;
}
function sql_connect(array $c): PDO {
    $host = (string)($c['host'] ?? ''); $name = (string)($c['name'] ?? ''); $port = (int)($c['port'] ?? 3306);
    if (!preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D', $host) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $name) || $port < 1 || $port > 65535) { throw new RuntimeException('Host, porta ou nome do banco inválido. Use os dados do cPanel.'); }
    try {
        return new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4', (string)$c['user'], (string)$c['password'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>8
        ]);
    } catch (PDOException $ex) { throw new RuntimeException('Não foi possível conectar ao MySQL. Confira host, porta, banco, usuário, senha e permissões no cPanel.'); }
}
function sql_db(): PDO {
    static $db;
    if (!$db) { $db = sql_connect(sql_config()); }
    return $db;
}
function sql_load_settings(PDO $db, bool $lock=false): array {
    $row = $db->query('SELECT schema_version, password_hash FROM dc_settings WHERE id=1'.($lock ? ' FOR UPDATE' : ''))->fetch();
    if (!$row) { throw new RuntimeException('Banco sem configuração do painel. Conclua a instalação SQL.'); }
    $machines = $db->query('SELECT id, name, category, api_url, api_username, api_password_encrypted, created_at FROM dc_machines ORDER BY created_at, id')->fetchAll();
    $servers = array_map(function($v) { return ['id'=>$v['id'], 'name'=>$v['name'], 'category'=>$v['category'], 'url'=>$v['api_url'], 'username'=>$v['api_username'], 'password'=>$v['api_password_encrypted'], 'created_at'=>str_replace(' ','T',$v['created_at']).'Z']; }, $machines);
    return ['version'=>$row['schema_version'], 'password_hash'=>$row['password_hash'], 'key'=>sql_config()['key'], 'servers'=>$servers];
}
function sql_read(string $name): array {
    $db = sql_db();
    if ($name === 'settings') {
        if ($db->inTransaction()) return sql_load_settings($db);
        $db->beginTransaction();
        try { $s=sql_load_settings($db); $db->commit(); return $s; }
        catch (Throwable $ex) { if ($db->inTransaction()) $db->rollBack(); throw $ex; }
    }
    if ($name === 'login') {
        $row=$db->query('SELECT window_started, attempts FROM dc_login_guard WHERE id=1')->fetch();
        return ['start'=>(int)($row['window_started'] ?? 0), 'count'=>(int)($row['attempts'] ?? 0)];
    }
    throw new RuntimeException('Armazenamento desconhecido.');
}
function sql_store_servers(PDO $db, array $servers): void {
    $statement=$db->prepare('INSERT INTO dc_machines (id,name,category,api_url,api_username,api_password_encrypted,created_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),api_url=VALUES(api_url),api_username=VALUES(api_username),api_password_encrypted=VALUES(api_password_encrypted)');
    $ids=[];
    foreach ($servers as $server) {
        if (!preg_match('/^[a-f0-9]{24}$/D', $server['id'])) { throw new RuntimeException('Identificador de máquina inválido.'); }
        $ids[]=$server['id'];
        $created=(new DateTimeImmutable($server['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $statement->execute([$server['id'],$server['name'],$server['category'],$server['url'],$server['username'],$server['password'],$created]);
    }
    if (!$ids) { $db->exec('DELETE FROM dc_machines'); }
    else { $delete=$db->prepare('DELETE FROM dc_machines WHERE id NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')'); $delete->execute($ids); }
}
function sql_update(string $name, callable $change): array {
    $db=sql_db();$db->beginTransaction();
    try {
        if ($name === 'settings') {
            $current=sql_load_settings($db,true);$next=$change($current);
            if ($next['key'] !== $current['key']) { throw new RuntimeException('A chave de criptografia não pode ser trocada por esta operação.'); }
            sql_store_servers($db,$next['servers']);
            $db->prepare('UPDATE dc_settings SET schema_version=?,password_hash=? WHERE id=1')->execute([$next['version'],$next['password_hash']]);
        } elseif ($name === 'login') {
            $row=$db->query('SELECT window_started,attempts FROM dc_login_guard WHERE id=1 FOR UPDATE')->fetch();
            if (!$row) { throw new RuntimeException('Controle de login ausente no banco.'); }
            $next=$change(['start'=>(int)$row['window_started'],'count'=>(int)$row['attempts']]);
            $db->prepare('UPDATE dc_login_guard SET window_started=?,attempts=? WHERE id=1')->execute([$next['start'],$next['count']]);
        } else { throw new RuntimeException('Armazenamento desconhecido.'); }
        $db->commit();return $next;
    } catch (Throwable $ex) { if($db->inTransaction())$db->rollBack();throw $ex; }
}
function install_sql(array $input, string $password): void {
    $lock=@fopen(private_dir().'/install-lock.php','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) { throw new RuntimeException('Não foi possível iniciar a instalação. Confira a pasta private.'); }
    $db=null;$committed=false;$ownedPending=false;$pending=private_dir().'/db-pending.php';
    try {
        if (sql_configured()) { throw new RuntimeException('O painel SQL já foi instalado.'); }
        if (is_file($pending)) { throw new RuntimeException('Há uma configuração pendente em private/db-pending.php. Confira o guia antes de repetir.'); }
        $legacy=is_file(private_dir().'/settings.php') ? file_data_read('settings') : null;
        if ($legacy && !password_verify($password,$legacy['password_hash'])) { throw new RuntimeException('Para importar a versão anterior, informe a senha atual do mini painel.'); }
        $key=$legacy ? $legacy['key'] : base64_encode(random_bytes(32));
        if (strlen(base64_decode($key,true) ?: '') !== 32) { throw new RuntimeException('Chave anterior inválida.'); }
        if ($legacy) { foreach ($legacy['servers'] as $server) { unseal($server['password'],$key); } }
        $c=['host'=>text_field($input,'db_host',1,253), 'port'=>(int)($input['db_port'] ?? 3306), 'name'=>text_field($input,'db_name',1,128), 'user'=>text_field($input,'db_user',1,128), 'password'=>(string)($input['db_password'] ?? ''), 'key'=>$key];
        $db=sql_connect($c);
        $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
        if ($schema === false) { throw new RuntimeException('Arquivo database/schema.sql ausente. Envie o pacote completo.'); }
        foreach (explode(';',$schema) as $sql) { if (trim($sql)!=='') { $db->exec($sql); } }
        if ((int)$db->query('SELECT COUNT(*) FROM dc_settings')->fetchColumn() || (int)$db->query('SELECT COUNT(*) FROM dc_machines')->fetchColumn() || (int)$db->query('SELECT COUNT(*) FROM dc_login_guard')->fetchColumn()) { throw new RuntimeException('O banco já tem dados Dominus. Use um banco novo ou restaure o private/db.php correspondente; o instalador não sobrescreve dados.'); }
        $encoded="<?php\nreturn ".var_export($c,true).";\n";
        $ownedPending=true;
        if (file_put_contents($pending,$encoded,LOCK_EX)!==strlen($encoded)) { throw new RuntimeException('Não foi possível gravar private/db-pending.php.'); }
        @chmod($pending,0600);
        $db->beginTransaction();
        $db->prepare('INSERT INTO dc_settings (id,schema_version,password_hash) VALUES (1,?,?)')->execute(['2.0.0',$legacy ? $legacy['password_hash'] : password_hash($password,PASSWORD_DEFAULT)]);
        $db->exec('INSERT INTO dc_login_guard (id,window_started,attempts) VALUES (1,0,0)');
        // O banco está vazio e bloqueado pela instalação; somente os dados anteriores locais são importados.
        if ($legacy) { sql_store_servers($db,$legacy['servers']); }
        $db->commit();$committed=true;
        if (!rename($pending,private_dir().'/db.php')) { throw new RuntimeException('Banco preparado. Renomeie private/db-pending.php para private/db.php para concluir.'); }
    } catch (Throwable $ex) {
        if ($db && $db->inTransaction()) $db->rollBack();
        if (!$committed && $ownedPending) @unlink($pending);
        if ($ex instanceof PDOException) { throw new RuntimeException('Falha ao criar ou gravar as tabelas MySQL. Confira permissões do usuário do banco e compatibilidade InnoDB.'); }
        throw $ex;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
