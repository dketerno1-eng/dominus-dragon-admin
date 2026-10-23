# Dominus Mini Consulta SQL 2.0 — Alphimedia

Painel completo de consulta de usuários Dragon com máquinas e credenciais persistidas em MySQL/MariaDB. As credenciais são cadastradas uma vez e reutilizadas em todas as consultas. A consulta inicia automaticamente ao abrir o painel, ao selecionar outra máquina e após salvar uma máquina.

Esta etapa continua somente leitura de usuários na VPS. Cadastro, edição e remoção de máquinas afetam o mini painel; nenhuma conta VPN é criada, alterada, renovada ou excluída.

## Instalação no cPanel

1. Em **Bancos de dados MySQL**, crie um banco, um usuário e associe esse usuário ao banco com **TODOS OS PRIVILÉGIOS**. Copie os nomes completos, incluindo o prefixo da conta cPanel.
2. Crie `public_html/consulta`. Envie e extraia o conteúdo inteiro do ZIP nessa pasta. Inclua arquivos `.htaccess`, pastas `app`, `assets`, `private` e `database`.
3. Abra `https://SEU-DOMINIO/consulta/`. Informe a chave do arquivo `CHAVE_INSTALACAO.txt`, crie a senha do mini painel e informe host MySQL (geralmente `localhost`), porta (`3306`), banco, usuário e senha do banco.
4. O instalador conecta ao MySQL e cria as tabelas automaticamente. Não precisa importar o SQL manualmente. Se preferir, importe antes `database/schema.sql` pelo phpMyAdmin no banco vazio e depois conclua a instalação pelo navegador.
5. Após instalar, exclua o TXT da chave da hospedagem. A chave PHP de instalação é removida automaticamente.
6. Clique em **Adicionar máquina**, informe nome, categoria, URL pública da API, login admin e senha do Dragon e salve. A consulta começa automaticamente.

A senha do mini painel, a senha do banco e a senha admin do Dragon são três credenciais diferentes. Sem senha admin padrão ou contas de demonstração pré-cadastradas.

Requisitos: PHP 7.4+, PDO MySQL (`pdo_mysql`), cURL, OpenSSL com AES-256-GCM, sessões e permissão de gravação na pasta `private`. MySQL 5.7+ ou MariaDB 10.3+ com InnoDB/utf8mb4. A criação do banco e a associação do usuário são feitas no cPanel; o instalador cria somente as tabelas dentro dele.

## Atualizar das versões 1.0 / 1.1

1. Faça backup da pasta `private` atual.
2. Envie o pacote completo sobre a pasta instalada, **preservando os arquivos atuais dentro de private**. O ZIP não contém `settings.php`, `db.php` nem credenciais de máquinas, portanto não substitui seus dados.
3. Abra o painel. Se encontrar `private/settings.php` da versão anterior, o instalador pede a **senha atual do mini painel**, confirmação, a nova chave do pacote e os dados de um banco vazio.
4. As máquinas, categorias, credenciais e senha do painel são importadas para MySQL numa transação. A chave anterior é preservada para manter as senhas legíveis pela aplicação. O arquivo anterior é mantido como backup; o painel passa a usar o MySQL após a instalação.
5. Confira as máquinas e uma consulta. Guarde o backup anterior fora da pasta pública.

Se já existir `private/db.php`, é uma instalação SQL configurada: ela abre normalmente e não executa o instalador novamente. Não use o instalador em um banco Dominus já preenchido; restaure o `private/db.php` original correspondente ao banco. Não há mesclagem automática entre instalações SQL independentes.

## O que é salvo no SQL

| Tabela | Conteúdo |
| --- | --- |
| `dc_machines` | Nome e categoria da VPS, endereço da API, usuário admin do Dragon, senha cifrada, datas de criação e atualização. |
| `dc_settings` | Versão do esquema e hash da senha de acesso ao mini painel. |
| `dc_login_guard` | Janela e contador de tentativas de login do mini painel. |

`database/schema.sql` contém todas as instruções CREATE TABLE. Não contém senhas reais, INSERT de máquinas ou DROP TABLE. O instalador preenche a configuração inicial e o próprio painel grava cada máquina com consultas preparadas e transações.

A senha Dragon é armazenada em AES-256-GCM, porque o painel precisa recuperá-la para fazer login na API. A chave e os dados de conexão MySQL ficam em `private/db.php`, protegido pelo PHP e `.htaccess`; a chave não é gravada no SQL. Faça backup **do banco e desse arquivo juntos**. Perder a chave exige cadastrar novamente as senhas Dragon. O token da API existe só durante a consulta; o painel faz um novo login automaticamente quando precisa consultar.

Editar nome/categoria com a senha vazia mantém a credencial já salva. Trocar endereço ou usuário exige reinformar a senha. Credenciais e tokens não são enviados ao JavaScript nem incluídos na lista de usuários. O banco da hospedagem e o banco interno do Dragon são independentes: este painel usa a API, não conecta ao PostgreSQL da VPS.

## Configurar sua VPS de teste

Use **`http://IP-PUBLICO-DA-VPS:9090`**, usuário `admin`, senha do painel Dragon e marque **Permitir HTTP para esta máquina de teste**. Use o IPv4 público mostrado no painel Oracle. `127.0.0.1` e `10.0.0.235` não funcionam como endereço externo da VPS na hospedagem.

O contrato confirmado no terminal da sua VPS é:

- `POST /api/auth/login`, enviando `username` e `password`, recebendo `token`.
- `GET /api/users?server_id=local`, com header `X-Session-Token`.

A consulta retorna usuário, validade, limite e conexões informados pelo Dragon. Limite 0 é preservado como 0. Campos ausentes ficam não informados. Datas sem fuso ou inválidas não são classificadas como vigentes. O total é o número de usuários retornados para o escopo local e permissões das credenciais cadastradas.

A porta 9090 já respondeu localmente na sua VPS. O acesso externo pela Alphimedia ainda exige liberação no firewall Linux, nas regras Oracle/VCN/NSG e na saída de rede da hospedagem. O painel mostra se a falha ocorreu no login, consulta, certificado ou conectividade. Credenciais corretas não resolvem bloqueio de porta. Nenhuma porta ou regra é alterada por este pacote.

Em HTTP de teste, as credenciais trafegam sem TLS entre hospedagem e VPS; essa opção é explícita. Em HTTPS, o certificado é sempre verificado. O endereço aceita portas de 1 a 65535 e IPv4 público ou domínio com IPv4. Não é necessário agente adicional, Composer ou Docker.

## Consulta automática

Ao abrir o painel autenticado, a primeira máquina cadastrada é consultada. Ao trocar de máquina, ela também é consultada automaticamente. Máquinas salvas são consultadas logo após salvar. O botão **Atualizar agora** é opcional.

Requisições simultâneas para a mesma máquina são deduplicadas. Ao retornar a uma máquina consultada há menos de 3,5 segundos, o resultado recente é reutilizado. Falhas mantêm os dados anteriores identificados, quando existem. Resultados ficam na memória da página; abrir/atualizar inicia nova consulta. Não há consulta periódica com o navegador fechado.

Busca por máquina e usuário, categorias, filtros de validade e paginação local de 100 em 100. Até 100 máquinas cadastradas e 4 MB por resposta. Validade não comprova acesso ou autenticação bem-sucedida; conexões são apenas as informadas pela API.

## Recuperação e diagnóstico

- **PDO MySQL ausente:** habilite `pdo_mysql` no seletor de PHP do cPanel ou solicite ao suporte.
- **Não conecta ao MySQL:** confira nomes com prefixo, host, senha e associação do usuário ao banco.
- **Banco já tem dados:** restaure o arquivo `private/db.php` correspondente; não tente instalar com outra chave.
- **Instalação SQL concluiu, mas configuração ficou pendente:** se houver `private/db-pending.php` e o banco contiver as tabelas preenchidas, renomeie esse arquivo para `private/db.php`. Ele contém a conexão e a chave geradas naquela instalação. Não o apague nem gere outra chave sem verificar o banco.
- **Chave de instalação:** serve somente para configurar o pacote novo; não é senha de login nem chave de criptografia.
- **401/403 da API:** confira credenciais e permissões admin do Dragon.
- **Timeout:** confira IP público, porta cadastrada, firewall Oracle/Linux e política de saída TCP da Alphimedia.

Não publique `private/db.php`, configurações anteriores nem backups SQL reais no GitHub.

## Código e testes

Gerar novo ZIP: `python3 tools/build.py`. Esse gerador inclui SQL e chave de instalação nova, sem dados de máquinas.

```bash
php -l index.php
php -l app/core.php
php -l app/storage.php
php tests/core_test.php
node --check assets/app.js
node tests/auto_query_test.js
```

Os testes SQL e de migração usam banco isolado. A consulta real ao Dragon foi confirmada pelo usuário no terminal; a instalação na Alphimedia e a conexão dela com a VPS precisam ser validadas no seu ambiente.

Validação desta versão: testes de instalação nova e migração executados contra MariaDB 10.11 real e PDO MySQL; persistência da senha verificada em um novo processo PHP, edição/remoção SQL, login com sessão nova e consulta HTTP a Dragon simulado. Também passaram as 29 verificações do conector, o teste de consulta automática e a análise de sintaxe PHP/JavaScript. Ainda falta homologação na hospedagem Alphimedia e na conexão externa com sua VPS.
