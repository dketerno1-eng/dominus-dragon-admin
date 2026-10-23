#!/usr/bin/env python3
"""Build upload-ready zip. Installation key is generated only locally."""
from pathlib import Path
import secrets
import zipfile
import sys

root=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1] if len(sys.argv)>1 else root.parent/'DOMINUS_MINI_CONSULTA_ALPHIMEDIA_SQL_v2.zip')
key=secrets.token_hex(16)
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
    for path in root.rglob('*'):
        relative=path.relative_to(root)
        if not path.is_file() or relative.parts[0] in ('tests','tools') or '__pycache__' in relative.parts or path.name=='.gitignore': continue
        if relative.parts[0]=='private' and path.suffix=='.php': continue
        z.write(path,str(relative))
    z.writestr('private/install-key.php',"<?php\nreturn '"+key+"';\n")
    z.writestr('CHAVE_INSTALACAO.txt','CHAVE DE INSTALAÇÃO DO MINI PAINEL\n\n'+key+'\n\nUse no primeiro acesso. Depois exclua este TXT da hospedagem.\nNão é a senha do Dragon nem a senha do painel.\n')
print(out)
