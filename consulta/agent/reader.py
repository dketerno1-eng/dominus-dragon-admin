#!/usr/bin/env python3
"""Dominus Consulta 1.0: inventory only. No account mutation operations."""
import argparse
import datetime as dt
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import socket
import ssl
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSION = '1.0.0'
CONFIG = '/etc/dominus-consulta/config.json'

class ReadError(Exception):
    pass

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise ReadError('Redirecionamento inesperado da API local.')

def linux_users(passwd='/etc/passwd', shadow='/etc/shadow', login_defs='/etc/login.defs'):
    """Local candidates, not a claim that all are Dragon/VPN accounts."""
    minimum = 1000
    try:
        match = re.search(r'^\s*UID_MIN\s+(\d+)', Path(login_defs).read_text(), re.M)
        if match:
            minimum = int(match[1])
    except OSError:
        pass
    expiration = {}
    shadow_ok = False
    try:
        for line in Path(shadow).read_text().splitlines():
            fields = line.split(':')
            if len(fields) >= 8:
                expiration[fields[0]] = (fields[7], fields[1].startswith(('!', '*')))
        shadow_ok = True
    except (PermissionError, FileNotFoundError):
        pass
    result = []
    today = (dt.datetime.now(dt.timezone.utc).date() - dt.date(1970, 1, 1)).days
    for line in Path(passwd).read_text().splitlines():
        f = line.split(':')
        if len(f) != 7 or not f[2].isdigit():
            continue
        uid = int(f[2])
        if uid < minimum or uid == 65534:
            continue
        raw, locked = expiration.get(f[0], ('', None))
        until = None
        status = 'não informado'
        if f[0] in expiration:
            status = 'sem vencimento no Linux'
            if raw.isdigit():
                try:
                    until = (dt.date(1970, 1, 1) + dt.timedelta(days=int(raw))).isoformat()
                    status = 'vencido no Linux' if int(raw) <= today else 'válido no Linux'
                except (ValueError, OverflowError):
                    status = 'data inválida no Linux'
            if locked:
                status = 'senha bloqueada no Linux'
        result.append({'username': f[0], 'uid': uid, 'expires_at': until,
                       'status': status, 'max_connections': None,
                       'active_conns': None, 'shell': f[6]})
    warnings = ['Lista de contas locais com UID >= %s; pode incluir administradores e serviços. Não comprova vínculo com o Dragon.' % minimum,
                'Limites e conexões VPN não são inferidos pelo Linux. Validade do script pode ser diferente da validade da conta Linux.']
    if not shadow_ok:
        warnings.append('Sem acesso a /etc/shadow: vencimentos indisponíveis. Execute com sudo para consultá-los.')
    return result, warnings

def dragon_users(config):
    url = config.get('dragon_url', '')
    if not re.fullmatch(r'http://127\.0\.0\.1:[0-9]{2,5}', url):
        raise ReadError('API Dragon deve usar HTTP em 127.0.0.1 e porta local.')
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    def request(path, payload=None, token=None):
        headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
        if token:
            headers['X-Session-Token'] = token
        req = urllib.request.Request(url + path, headers=headers,
            data=json.dumps(payload).encode() if payload is not None else None)
        try:
            with opener.open(req, timeout=12) as response:
                raw = response.read(4_000_001)
                if len(raw) > 4_000_000:
                    raise ReadError('Resposta da API acima do limite de 4 MB.')
                return json.loads(raw)
        except ReadError:
            raise
        except Exception as exc:
            raise ReadError('Não foi possível consultar a API DragonCore. Confira porta, credenciais e compatibilidade.') from exc
    login = request('/api/auth/login', {'username': config.get('dragon_user', ''), 'password': config.get('dragon_password', '')})
    if not isinstance(login, dict) or not isinstance(login.get('token'), str) or not login['token']:
        raise ReadError('API Dragon não retornou uma sessão compatível.')
    users = request('/api/users?server_id=local', token=login['token'])
    if not isinstance(users, list) or any(not isinstance(u, dict) or not isinstance(u.get('username'), str) for u in users):
        raise ReadError('A API não retornou a lista esperada. Não substituímos por uma lista Linux silenciosamente.')
    rows = []
    for user in users:
        def number(key):
            n = user.get(key)
            return n if type(n) is int and n >= 0 else None
        expiration = user.get('expires_at')
        status = 'não informado'
        if isinstance(expiration, str) and expiration:
            try:
                parsed = dt.datetime.fromisoformat(expiration.replace('Z', '+00:00'))
                if parsed.tzinfo is not None:
                    status = 'vencido' if parsed <= dt.datetime.now(dt.timezone.utc) else 'válido'
            except ValueError:
                pass
        rows.append({'username': user['username'][:128], 'expires_at': expiration if isinstance(expiration, str) else None,
            'status': status, 'max_connections': number('max_connections'), 'active_conns': number('active_conns')})
    return rows, ['Dados informados pela API DragonCore; validade não comprova conexão ativa. Senhas e segredos são descartados.']

def snapshot(config):
    source = config.get('source', 'linux')
    if source == 'linux':
        rows, warnings = linux_users()
    elif source == 'dragon':
        rows, warnings = dragon_users(config)
    else:
        raise ReadError('Fonte deve ser linux ou dragon.')
    rows.sort(key=lambda u: u['username'].lower())
    return {'ok': True, 'version': VERSION, 'source': source, 'readonly': True,
            'collected_at': dt.datetime.now(dt.timezone.utc).isoformat(), 'total': len(rows),
            'users': rows, 'warnings': warnings}

class Auth:
    def __init__(self, secret):
        if not re.fullmatch(r'[a-f0-9]{64}', secret):
            raise ReadError('Chave inválida.')
        self.secret = bytes.fromhex(secret)
        self.nonces = {}
        self.lock = threading.Lock()
    def verify(self, headers, path):
        stamp = headers.get('X-Dominus-Time', '')
        nonce = headers.get('X-Dominus-Nonce', '')
        signature = headers.get('X-Dominus-Signature', '')
        if not re.fullmatch(r'\d{10,12}', stamp) or abs(time.time() - int(stamp)) > 120:
            return False
        if not re.fullmatch(r'[a-f0-9]{32}', nonce):
            return False
        expected = hmac.new(self.secret, ('\n'.join([stamp, nonce, 'GET', path])).encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, signature):
            return False
        with self.lock:
            self.nonces = {n: t for n, t in self.nonces.items() if t > time.time() - 300}
            if nonce in self.nonces or len(self.nonces) >= 10000:
                return False
            self.nonces[nonce] = time.time()
        return True

class Server(ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 16
    def __init__(self, address, handler, config):
        self.config = config
        self.auth = Auth(config['secret'])
        self.slots = threading.BoundedSemaphore(16)
        self.read_lock = threading.Lock()
        self.cached = None
        self.cached_at = 0
        super().__init__(address, handler)
    def process_request(self, request, address):
        if not self.slots.acquire(False):
            request.close()
            return
        try:
            super().process_request(request, address)
        except Exception:
            self.slots.release()
            raise
    def process_request_thread(self, request, address):
        try:
            super().process_request_thread(request, address)
        finally:
            self.slots.release()
    def get_request(self):
        sock, addr = super().get_request()
        sock.settimeout(8)
        return sock, addr
    def read_snapshot(self):
        with self.read_lock:
            if self.cached is None or time.time() - self.cached_at > 10:
                self.cached = snapshot(self.config)
                self.cached_at = time.time()
            return self.cached

class Handler(BaseHTTPRequestHandler):
    server_version = 'DominusConsulta/1'
    def log_message(self, *args):
        pass
    def setup(self):
        # Handshake inside a bounded worker, not inside the accept loop.
        if hasattr(self.server, 'tls'):
            self.request = self.server.tls.wrap_socket(self.request, server_side=True)
        super().setup()
    def reply(self, status, value):
        raw = json.dumps(value, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)
    def do_GET(self):
        if self.path != '/v1/users':
            return self.reply(404, {'ok': False, 'message': 'Rota inexistente.'})
        if not self.server.auth.verify(self.headers, self.path):
            return self.reply(401, {'ok': False, 'message': 'Assinatura inválida ou relógio incorreto.'})
        try:
            self.reply(200, self.server.read_snapshot())
        except ReadError as exc:
            self.reply(502, {'ok': False, 'message': str(exc)})
        except Exception:
            self.reply(503, {'ok': False, 'message': 'Falha na leitura local. Confira o serviço.'})
    def do_POST(self):
        self.reply(405, {'ok': False, 'message': 'Somente consulta GET é permitida.'})
    do_PUT = do_DELETE = do_PATCH = do_POST

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--serve', action='store_true')
    parser.add_argument('--config', default=CONFIG)
    parser.add_argument('--json', action='store_true')
    parser.add_argument('--dragon', action='store_true', help='Usar credenciais de configuração Dragon explícita.')
    args = parser.parse_args()
    try:
        config = json.loads(Path(args.config).read_text()) if args.serve or args.dragon else {'source': 'linux'}
        if args.serve:
            server = Server((config.get('bind', '0.0.0.0'), int(config.get('port', 9443))), Handler, config)
            tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            tls.minimum_version = ssl.TLSVersion.TLSv1_2
            tls.load_cert_chain(config['cert'], config['key'])
            server.tls = tls
            print('Dominus Consulta: HTTPS somente leitura iniciado.', flush=True)
            server.serve_forever()
        else:
            if args.dragon:
                config['source'] = 'dragon'
            data = snapshot(config)
            if args.json:
                print(json.dumps(data, ensure_ascii=False, indent=2))
            else:
                print('DOMINUS • CONSULTA SOMENTE LEITURA\nFonte: %s | Contas: %s' % (data['source'], data['total']))
                for warning in data['warnings']:
                    print('Nota:', warning)
                print('\nUSUÁRIO\tVALIDADE\tESTADO\tLIMITE\tCONEXÕES')
                for u in data['users']:
                    print('\t'.join(str(u.get(k)) if u.get(k) is not None else 'não informado' for k in
                                    ('username', 'expires_at', 'status', 'max_connections', 'active_conns')))
    except (ReadError, OSError, ValueError) as exc:
        parser.exit(1, 'Consulta não concluída: %s\n' % exc)

if __name__ == '__main__':
    main()
