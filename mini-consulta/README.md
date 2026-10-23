# Dominus Mini Consulta — Alphimedia / cPanel

Painel PHP pequeno, com cadastro de várias VPS e consulta direta à API Dragon. Não requer agente, MySQL, Composer, Docker ou instalação adicional na VPS. Nenhuma operação de criação, renovação, exclusão ou alteração de usuários é implementada.

## Instalar

1. Abra `CHAVE_INSTALACAO.txt` do ZIP pronto e copie a chave.
2. No gerenciador de arquivos do cPanel da Alphimedia, crie uma pasta nova `public_html/consulta` e envie **todo o conteúdo** do ZIP para ela, preservando `.htaccess`, `app`, `private` e `assets`. Não substitua outro painel existente.
3. Abra `https://SEU-SITE/consulta/`. Informe a chave de instalação, crie uma senha do mini painel e confirme.
4. A instalação cria sua configuração e remove a chave PHP. Exclua também `CHAVE_INSTALACAO.txt` da hospedagem.
5. Clique **Adicionar máquina**. Informe nome, categoria, endereço público da API, usuário administrador do Dragon e senha. Para a máquina de testes com HTTP, marque a opção correspondente.
6. Selecione a máquina e clique **Consultar usuários**.

A senha do mini painel é independente das credenciais do Dragon. Requisitos: PHP 7.4+, extensões cURL e OpenSSL, sessões, pasta `private` gravável pelo PHP. O instalador mostra requisitos ausentes. O acesso via HTTPS da própria Alphimedia é recomendado. Sem necessidade de criar banco de dados.

## Endereço da API na Alphimedia

Na sua VPS de teste o login e a leitura foram confirmados em `http://127.0.0.1:9090`. No mini painel use **`http://IP-PUBLICO-DA-VPS:9090`**, usuário `admin`, sua senha do Dragon e marque a opção HTTP de teste. O pacote aceita portas personalizadas entre 1 e 65535 e não instala agentes.

Não use `10.0.0.235` (IP interno Oracle), `127.0.0.1` nem o domínio da hospedagem como endereço da máquina. O público da VPS aparece nos detalhes da instância no painel Oracle.

As regras Oracle/VCN/NSG e o firewall Linux precisam permitir a porta escolhida, e a hospedagem precisa permitir saída TCP para ela. **Não foi confirmado que o seu plano Alphimedia permite a porta 9090**; o botão de consulta testa login e leitura e mostra em qual etapa há falha. Timeout não significa necessariamente senha errada. Se a saída estiver bloqueada, peça ao suporte da Alphimedia liberação para o IPv4 público da sua VPS na porta TCP 9090, ou disponibilize a API em porta permitida conforme o roteamento do Dragon.

O mini painel não altera portas, firewall, SSH, serviços ou contas da VPS. Também não pressupõe que a porta 80 do sshpanel disponibilize a mesma API da 9090. Só é usado o endereço que você cadastrar.

## O que a consulta mostra

- Máquinas separadas por nome e categoria; busca de máquinas.
- Login por máquina, feito no servidor PHP, com `POST /api/auth/login`.
- Consulta `GET /api/users?server_id=local` usando header `X-Session-Token`.
- Usuário, vencimento, estado da validade, limite e conexões reportados pela API.
- Limite `0` permanece `0`; não é interpretado como ilimitado.
- Campos ausentes aparecem como “—”/“Não informado”. Datas sem fuso ou inválidas não são classificadas como vigentes.
- Busca de usuários, filtro de validade e paginação local de 100 em 100.
- Data de coleta no horário do navegador; datas originais da API preservadas no resultado JSON.
- Falha de atualização mantém a leitura anterior identificada; não inventa estado online.
- Editar/remover máquina afeta somente o cadastro do mini painel. Remover do painel não exclui usuários da VPS.

Os resultados ficam apenas na memória da página; atualizar a página requer nova consulta. Não há monitoramento contínuo, pagamentos, mensagens ou controle de usuários nesta etapa. O total é o número de registros devolvidos pela API para o escopo local e credenciais usadas.

## Credenciais e arquivos

As credenciais da API são armazenadas com AES-256-GCM em `private/settings.php`. A chave está na mesma configuração: isso protege contra leitura casual, mas acesso à conta da hospedagem permite recuperá-las. O token de sessão do Dragon existe apenas durante a consulta. Senhas e tokens não são devolvidos ao JavaScript nem incluídos nos resultados. Não há encerramento automático da sessão Dragon por desconhecimento de um endpoint de logout confirmado.

HTTP de teste transmite as credenciais sem TLS entre Alphimedia e VPS, mesmo quando o painel é acessado por HTTPS. A opção é explícita. Em HTTPS, a verificação de certificado permanece habilitada; utilize certificado válido na API. Esta versão não tem importação de certificado próprio.

Faça backup privado de `private/settings.php`; ele inclui configurações, chave e credenciais cifradas. O acesso ao painel tem senha, CSRF, expiração por inatividade e limite global de tentativas. Arquivos de dados têm extensão PHP e prefixo de saída, além da proteção `.htaccess`. Não publique a pasta `private` real ou ZIPs configurados no GitHub. A edição do endereço/usuário exige reinformar a senha para evitar enviá-la a um destino diferente por engano.

Máximo de 100 máquinas e até 4 MB por resposta, consulta manual a uma máquina por vez. DNS IPv4 público; IPv6 exclusivo não suportado nesta versão. A gravação usa bloqueio de arquivo e substituição atômica; evite editar arquivos de dados manualmente com o painel aberto. Ausência de armazenamento transacional/banco exige backup antes de mudanças ou atualização.

## Gerar um ZIP novo a partir do GitHub

```bash
python3 tools/build.py
```

O gerador cria um ZIP de upload e uma chave de instalação nova no interior do ZIP. A chave não está no código público. O ZIP não contém credenciais de nenhuma VPS.

## Verificar o código

```bash
php -l index.php
php -l app/core.php
php tests/core_test.php
node --check assets/app.js
```

O contrato de API foi confirmado localmente pelo usuário na VPS: login e lista contendo Darlam, validade, limite 0 e conexões 0. O acesso da Alphimedia ainda exige validação real. A entrega passou na análise de sintaxe PHP/JavaScript, em 29 verificações do conector e na integração local: instalação, login, cadastro/edição de máquina, CSRF, criptografia e chamadas cURL reais a uma API simulada. Isso não representa homologação da Alphimedia ou da rede Oracle. A aparência final não foi verificada em navegador automatizado neste ambiente.
