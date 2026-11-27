#!/usr/bin/env sh
# Apaga TODAS as inscrições e cria cadastros de teste em cada etapa do fluxo,
# com PDFs de teste (backup antes; senhas em cadastros-teste.md).
# Requer a stack no ar (docker compose up -d). Uso:
#   sh tests/popular.sh --apagar-tudo
set -e
cd "$(dirname "$0")"

if [ ! -d .venv ]; then
    python3 -m venv .venv
    .venv/bin/python -m pip install -q -r requirements.txt
fi

exec .venv/bin/python popular_banco.py "$@"
