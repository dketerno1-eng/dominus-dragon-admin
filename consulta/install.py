#!/usr/bin/env python3
"""Interactive installer. Installs only the read-only inventory service."""
import getpass
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parent
ETC = Path('/etc/dominus-consulta')
APP = Path('/opt/dominus-consulta')
SERVICE = Path('/etc/systemd/system/dominus-consulta.service')

def ask(prompt, default=''):
    answer = input(prompt + (' ['+default+']' if default else '') + ': ').strip()
    return answer or default

def main():
    if os.geteuid() != 0:
        raise RuntimeError('Execute com sudo python3 install.py.')
    if ETC.exists() or APP.exists() or SERVICE.exists():
        raise RuntimeError('Instalação existente detectada. Nada foi substituído. Veja README para manutenção.')
    for command in ('openssl', 'systemctl'):
        if not shutil.which(command):
            raise RuntimeError('Dependência ausente: '+command)
    print('DOMINUS CONSULTA • instala somente leitura. Não altera usuários, Dragon, SSH ou firewall.')
    print('Para apenas listar no terminal, cancele e use: sudo python3 agent/reader.py')
    host = ask('IP público IPv4 ou domínio que aponta diretamente para esta VPS')
    try:
        address = ipaddress.ip_address(host)
        if address.version != 4 or not address.is_global:
            raise ValueError('Informe IPv4 público.')
        san = 'IP:'+host
    except ValueError:
        if not re.fullmatch(r'(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}', host):
            raise RuntimeError('Use IPv4 público ou domínio válido; sem protocolo/caminho.')
        san = 'DNS:'+host
    port = int(ask('Porta HTTPS exclusiva para consulta', '9443'))
    if not 1024 <= port <= 65535:
        raise RuntimeError('Use porta de 1024 a 65535. Não use 22, 80 ou 443.')
    with socket.socket() as probe:
        probe.bind(('0.0.0.0', port))
    source = ask('Fonte: linux ou dragon', 'linux').lower()
    if source not in ('linux', 'dragon'):
        raise RuntimeError('Fonte inválida.')
    config = {'source': source, 'port': port, 'bind': '0.0.0.0', 'secret': secrets.token_hex(32),
              'cert': str(ETC/'agent-ca.pem'), 'key': str(ETC/'agent.key')}
    if source == 'dragon':
        config.update(dragon_url=ask('API DragonCore local', 'http://127.0.0.1:9090'),
                      dragon_user=ask('Usuário da API DragonCore'),
                      dragon_password=getpass.getpass('Senha da API DragonCore (oculta): '))
    sys.path.insert(0, str(ROOT/'agent'))
    from reader import snapshot
    inventory = snapshot(config)  # compatibility check before installing anything
    print('Leitura validada: %s contas; fonte %s.' % (inventory['total'], source))
    password = getpass.getpass('Crie uma senha para o painel (mínimo 12 caracteres): ')
    if len(password) < 12 or len(password.encode()) > 256 or password != getpass.getpass('Repita a senha: '):
        raise RuntimeError('Senha curta ou confirmação diferente.')
    salt = secrets.token_bytes(24)
    digest = hashlib.pbkdf2_hmac('sha256', password.encode(), salt, 300000).hex()
    os.umask(0o077)
    ETC.mkdir(mode=0o700)
    APP.mkdir(mode=0o755)
    shutil.copyfile(ROOT/'agent/reader.py', APP/'reader.py')
    (ETC/'config.json').write_text(json.dumps(config, indent=2))
    subprocess.run(['openssl','req','-x509','-newkey','rsa:3072','-sha256','-nodes',
        '-keyout',str(ETC/'agent.key'),'-out',str(ETC/'agent-ca.pem'),'-days','730',
        '-subj','/CN='+host,'-addext','subjectAltName='+san,
        '-addext','basicConstraints=critical,CA:TRUE'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    unit = '''[Unit]
Description=Dominus Consulta - inventario somente leitura
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/dominus-consulta/reader.py --serve
Restart=on-failure
RestartSec=5
User=root
UMask=0077
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
LockPersonality=true
CapabilityBoundingSet=
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
MemoryMax=192M
TasksMax=40
LimitNOFILE=128

[Install]
WantedBy=multi-user.target
'''
    SERVICE.write_text(unit)
    SERVICE.chmod(0o644)
    phpconfig = {'agent_url': 'https://'+host+':'+str(port), 'secret': config['secret'],
                 'password_salt': salt.hex(), 'password_hash': digest}
    # JSON is embedded as a PHP single-quoted string, escaped independently.
    encoded = json.dumps(phpconfig).replace('\\', '\\\\').replace("'", "\\'")
    php = "<?php\nreturn json_decode('"+encoded+"', true);\n"
    output = Path('/root/DOMINUS_CONSULTA_INFINITYFREE_PRIVADO.zip')
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for file in (ROOT/'site').rglob('*'):
            if file.is_file() and file.name not in ('config.php', 'login-lock.php', 'agent-ca.pem'):
                archive.write(file, str(file.relative_to(ROOT/'site')))
        archive.writestr('private/config.php', php)
        archive.write(ETC/'agent-ca.pem', 'private/agent-ca.pem')
    subprocess.run(['systemctl','daemon-reload'], check=True)
    subprocess.run(['systemctl','enable','--now','dominus-consulta'], check=True)
    print('\nServiço instalado. Consulte: systemctl status dominus-consulta --no-pager')
    print('Baixe por SFTP o arquivo: '+str(output))
    print('Extraia o conteúdo em uma subpasta NOVA de htdocs na InfinityFree e abra por HTTPS.')
    print('Senha do painel: a que você acabou de criar. Não compartilhe o ZIP privado.')
    print('Libere TCP %s no firewall da VPS e do provedor somente se necessário; o instalador não altera regras.' % port)
    print('HTTPS verifica certificado e nome/IP. Não desative essa validação.')

if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as exc:
        print('Instalação interrompida:', str(exc) or 'cancelada', file=sys.stderr)
        print('Se a falha ocorreu após criar arquivos, confira /etc/dominus-consulta e o serviço antes de repetir.', file=sys.stderr)
        sys.exit(1)
