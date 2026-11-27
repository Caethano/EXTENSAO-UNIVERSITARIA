#!/usr/bin/env sh
# Roda a suíte de testes do plugin no Linux/macOS. Requer a stack no ar
# (docker compose up -d). Exemplos:
#   tests/rodar.sh                     # tudo
#   tests/rodar.sh test_03_fluxo.py    # um arquivo
#   tests/rodar.sh -m seguranca        # só as regressões de segurança
set -e
cd "$(dirname "$0")"

if [ ! -d .venv ]; then
    python3 -m venv .venv
    .venv/bin/python -m pip install -q -r requirements.txt
fi

exec .venv/bin/python -m pytest "$@"
