# Dominus Dragon Admin v3.0.0 — painel administrativo + agentes VPS

Projeto **admin-first** para o InfinityFree (PHP/MySQL) e agente DragonCore (Python/systemd). O painel administrativo é a tela inicial do administrador; cadastro e autosserviço de clientes são módulos separados.

## Instalação do agente com UM comando no Termius (root/sudo)

1. Os arquivos públicos são publicados neste repositório. Verifique-os antes da instalação.
2. O instalador pode ser executado diretamente da branch `main` com a mesma verificação SHA-256 embutida no bootstrap.
3. Execute a linha abaixo em um terminal SSH interativo:

```bash
REPO='dketerno1-eng/dominus-dragon-admin'; curl -fsSLo /tmp/dominus-bootstrap.sh "https://raw.githubusercontent.com/$REPO/main/bootstrap.sh" && sudo bash /tmp/dominus-bootstrap.sh --repo "$REPO" --ref main
```

O comando faz download dos arquivos via HTTPS, verifica SHA-256 embutido no bootstrap, testa a compatibilidade da API DragonCore local e instala o serviço systemd, escutando **apenas `127.0.0.1:9177`**. O instalador vai perguntar **no seu terminal**, não no GitHub: URL da API local (`http://127.0.0.1:9090` por padrão), usuário/senha superadmin da API DragonCore e segredo de 64 hex gerado no painel em `VPS e agentes`.

**Não execute** antes de conferir o código de `bootstrap.sh` e `agent/install.sh` no seu repositório. O serviço não altera suas portas VPN, não troca firewall, SSH, nem instala o DragonCore por conta própria. Se já há um agente instalado, o instalador preserva os arquivos e aborta.

## Comunicação com o InfinityFree

- Crie um Cloudflare Tunnel no Cloudflare Zero Trust. Public hostname, por exemplo `agente1.seudominio.com`, service `http://127.0.0.1:9177`. Cloudflared é instalado à parte usando o comando específico do próprio Cloudflare; **não abra 9177** e **não mexa na porta 443 da VPN**.
- No painel do InfinityFree: `VPS e agentes → Adicionar VPS`; copie segredo de 64 hex no instalador; teste o agente e ative-o.
- Cada VPS tem instalação e segredo independentes. O painel InfinityFree realiza HTTPS de saída; não hospeda API REST pública para o APK nem recebe webhooks; exige chamadas HTTP do navegador para o PHP e túnel HTTPS para os agentes.

## Painel InfinityFree — instalação

**Nunca publique `site/private/config.php` nem `site/private/installer_key.php` num repositório GitHub público.** Esses arquivos devem ser gerados somente para a hospedagem.

1. No seu computador instale Python 3 e execute `python tools/build_panel_zip.py` (Windows: `py tools/build_panel_zip.py`). Isso gera `dist/DOMINUS_ADMIN_UPLOAD_HTDOCS.zip` e `dist/CHAVE_PRIVADA_INSTALACAO.txt`.
2. Crie um banco MySQL no InfinityFree. Envie **o conteúdo** do ZIP para `htdocs/` no gerenciador de arquivos.
3. Acesse `https://SEU-DOMINIO/install.php`, digite a chave privada do arquivo local e use os dados completos do MySQL do InfinityFree (`sqlXXX.infinityfree.com`, `if0_...`). Crie um e-mail/senha de administrador.
4. Após instalar, **exclua `install.php` e `private/installer_key.php` na hospedagem**. O arquivo `private/config.php` contém segredos; guarde backup privado e não o coloque no GitHub.
5. Entre no painel. A primeira tela do admin será `Central de operações`.

Se a instalação anterior falhou e o MySQL contém tabelas parcialmente criadas, **não apague dados reais**. Faça backup e verifique a instalação existente no phpMyAdmin antes de tentar instalar de novo. O instalador requer banco novo/vazio e não faz migração automática.

## Módulos administrativos

- **Central de operações:** resumo agregado de VPS, contas válidas, vencimentos, pedidos e operações sob revisão. VPS ativada ≠ VPS online; o monitoramento real é por consulta manual.
- **VPS e agentes:** cadastro, habilitação, teste da API autenticada e segredo individual.
- **Diagnóstico e conexões:** consulta real de usuários pelo agente (`list`), validade, limites e conexões ativas reportadas pela API DragonCore; amostra de até 250 usuários.
- **Usuários e contas SSH:** criação manual para clientes, renovação, exclusão, reconciliação de operações.
- **Operações e alertas:** revisão de contas vencidas, falhas e histórico de comandos administrativos.
- **Planos, testes e pagamentos:** catálogo, teste grátis com agente, aprovações manuais de Pix, Checkout Pro Mercado Pago opcional com verificação por ID do pagamento, pedidos e indicações.

## Restrições e segurança

O agente usa exclusivamente a API **local compatível** com endpoints `/api/auth/login`, `/api/users`, `/api/users/create` e `/api/users/delete`. **Não instala o script Dragon do zero e não executa shell remoto.** A instalação na VPS exige Python3, systemd e DragonCore API autenticando como superadmin. Consulte a documentação do pacote anterior para verificar compatibilidade. Senha SSH root não é usada.

A escuta é loopback e as requisições do painel são assinadas com HMAC e nonce anti-replay. Rotas de criação/renovação têm registro de operações para revisão de falhas indeterminadas; não force retry sem reconciliar. O usuário/senha Dragon ficam exclusivamente na VPS em `/etc/dominus-dragon/agent.json` com permissões restritas; não são enviados ao GitHub. O painel apenas armazena segredo individual HMAC e dados gerenciais.

Pix manual requer conferência bancária; não inclui webhook Mercado Pago ou Evolution API no InfinityFree. Testes por WhatsApp ainda não têm OTP. O agente não informa estado de VPN conectado em tempo real se o DragonCore não fornecer tais campos. Dados de validade do painel não substituem controle do DragonCore.

## Testes, manutenção, diagnóstico

```bash
python3 tests/test_agent.py
python3 -m py_compile agent/agent.py
php -l site/index.php
sudo systemctl status dominus-dragon --no-pager
sudo journalctl -u dominus-dragon -n 60 --no-pager
```

O pacote é para homologação: sintaxe PHP e testes locais com DragonCore **simulado** não equivalem a homologação na sua VPS, na conta InfinityFree ou no Cloudflare. Guarde backups antes de publicar.

## Status GitHub

Publicação GitHub: consulte o histórico de commits para verificar a versão dos arquivos. Mantenha o repositório público sem credenciais reais.