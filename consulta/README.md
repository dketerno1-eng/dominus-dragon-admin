# Dominus Consulta 1.0.0

Primeira etapa: **somente consultar usuários da VPS**. Sem criar, excluir, renovar, editar, cobrar ou enviar mensagens. O projeto anterior não é alterado.

## Comece pela consulta local

Na VPS, execute `sudo python3 agent/reader.py` dentro desta pasta. Não instala serviço, não abre porta e não altera contas. Para JSON, acrescente `--json`.

A lista local inclui contas de `/etc/passwd` com UID a partir de UID_MIN (normalmente 1000), exceto nobody. Contas de administrador e de serviços podem aparecer; uma conta VPN com UID menor pode não aparecer. Contas com shell nologin/false são mantidas porque podem ser utilizadas por túneis. **Não é uma lista confirmada de clientes Dragon.**

O programa lê apenas a data de expiração e o indicador de senha bloqueada de `/etc/shadow`; não imprime, transmite nem grava hashes de senha. Sem permissão para esse arquivo, informa que não há validade disponível. Data Linux não substitui a data própria do Dragon. Senha bloqueada não exclui a possibilidade de autenticação por chave ou outros mecanismos.

## Fonte DragonCore

Compatibilidade baseada no contrato existente no pacote anterior, ainda não homologada na sua VPS:

- Login local: `POST /api/auth/login`, resposta com `token`.
- Consulta: `GET /api/users?server_id=local`, resposta como lista.
- Header: `X-Session-Token`.

Não chama endpoints de alteração. As credenciais devem ser de um usuário autorizado à leitura; a exigência exata de permissões depende da API instalada. O login pode criar uma sessão na API, mas não modifica cadastros. Sem esse contrato, use a consulta Linux e depois adapte a integração à versão real do script. O modo Dragon nunca troca silenciosamente a fonte por Linux.

Para consultar com a configuração do agente anterior, se ele estiver instalado:

```bash
sudo python3 agent/reader.py --dragon --config /etc/dominus-dragon/agent.json
```

A consulta descarta senhas, hashes, TOTP e outros campos não autorizados. Só retorna username, validade, limite e conexões quando informados. O estado calculado de validade não é uma prova de acesso ou conexão bem-sucedida.

## Opcional: consulta pela InfinityFree

Após validar a lista local, execute nesta pasta:

```bash
sudo python3 install.py
```

Requisitos da VPS: Python 3.8+, Linux com systemd e OpenSSL com suporte a `-addext`. PHP 7.4+ com cURL na hospedagem. Não requer MySQL, Docker, Cloudflare ou outro servidor.

O instalador:

1. Solicita IP público/domínio, porta livre (padrão 9443), fonte e senha do painel.
2. Valida a leitura antes de instalar.
3. Gera uma chave aleatória e certificado TLS próprio com validade de 730 dias.
4. Instala `/opt/dominus-consulta/reader.py` e o serviço separado `dominus-consulta`.
5. Gera `/root/DOMINUS_CONSULTA_INFINITYFREE_PRIVADO.zip`, com painel configurado e certificado de confiança.

Baixe esse ZIP por SFTP e extraia **o conteúdo em uma subpasta nova** de `htdocs`, por exemplo `htdocs/consulta/`. Não substitua o painel antigo. Abra `https://SEU-SITE/consulta/` e entre com a senha que criou no instalador. Inclua `.htaccess` e a pasta `private`. A pasta `private` precisa permitir a criação do arquivo de controle de tentativas de login. Nunca publique o ZIP privado no GitHub.

A comunicação é PHP da InfinityFree → HTTPS da VPS. O navegador não recebe a chave do agente. O PHP valida certificado e nome/IP com o certificado incluído; não desabilita TLS. Não abra a URL do agente esperando uma página: ela exige assinatura HMAC. `POST`, `PUT`, `PATCH` e `DELETE` são recusados.

**Conectividade precisa de teste real.** Porta 9443 pode precisar de liberação no firewall da VPS e no provedor (Oracle: regras da VCN/NSG). A hospedagem também pode restringir saída para essa porta. O instalador não altera firewall ou portas da VPN. Se a porta não for acessível, é necessário escolher uma porta livre permitida ou ajustar o roteamento HTTPS existente; não reutilize uma porta já ocupada pelo Dragon/VPN. Esta versão não oferece um contorno para restrições da hospedagem.

O agente guarda resultados por até 10 segundos para evitar consultas simultâneas excessivas. O painel exige clique para atualizar, mostra a data de coleta e preserva resultados anteriores identificados quando há falha. Pesquisa e paginação de 100 em 100 são locais. Até 4 MB por resposta. Sem exportação pública ou senhas visíveis.

## Operação e diagnóstico

```bash
sudo systemctl status dominus-consulta --no-pager
sudo journalctl -u dominus-consulta -n 50 --no-pager
sudo python3 /opt/dominus-consulta/reader.py --json
```

O último comando consulta Linux. Para a fonte configurada Dragon: acrescente `--dragon --config /etc/dominus-consulta/config.json`.

- Assinatura inválida: confira chave e relógios da hospedagem/VPS (tolerância de 120 segundos).
- Erro de certificado: confira IP/domínio, validade e `private/agent-ca.pem`. Não desative a verificação.
- API incompatível: confira o contrato acima; a lista não será inventada.
- Fonte Linux sem validade: execute com sudo.
- InfinityFree sem resposta: confira TCP, regras Oracle, porta e eventuais restrições de saída da hospedagem.

O serviço usa root para ler vencimentos do Linux, com filesystem somente leitura e sem capabilities no systemd. Não recebe comandos shell. A chave do agente permite consultar usuários, portanto trate o ZIP como confidencial. Credenciais Dragon permanecem na VPS. Reiniciar o serviço limpa a memória anti-replay: não é uma solução de autenticação geral, apenas leitura. Certificados precisam ser renovados e redistribuídos antes de vencer, ou após mudança de IP/domínio. Não há atualização automática nesta primeira etapa.

Para parar sem alterar os usuários:

```bash
sudo systemctl disable --now dominus-consulta
```

O instalador aborta se encontrar instalação existente, preservando configurações. Atualizações futuras devem ser feitas após backup de `/etc/dominus-consulta` e do ZIP privado. Não existe migração automática do painel antigo.

## Validação desta entrega

8 testes automatizados com fixtures e API simulada: filtros Linux, ausência de shadow, remoção de dados sensíveis, contrato de leitura, fonte inválida, HMAC, repetição concorrente e bloqueio de rotas de alteração. Compilação Python e sintaxe JavaScript verificadas. Teste HTTPS local também passou: certificado confiável, verificação de IP no SAN e consulta autenticada HMAC.

PHP revisado, mas sem runtime PHP disponível no ambiente de construção: validação de sintaxe e execução na hospedagem ainda pendentes. Não houve conexão com sua VPS nem homologação real de DragonCore, InfinityFree ou regras de rede. A primeira ação recomendada é a consulta local, antes de instalar o serviço.
