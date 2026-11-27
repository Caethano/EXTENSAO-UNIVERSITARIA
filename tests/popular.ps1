# Apaga TODAS as inscrições e cria cadastros de teste em cada etapa do fluxo,
# com PDFs de teste (backup antes; senhas em cadastros-teste.md).
# Requer a stack no ar (docker compose up -d). Uso:
#   .\tests\popular.ps1 --apagar-tudo
param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $ScriptArgs)

$ErrorActionPreference = 'Stop'
# Push/Pop: o terminal de quem chamou volta para a pasta onde estava.
Push-Location $PSScriptRoot
try {
    $env:PYTHONIOENCODING = 'utf-8'

    if (-not (Test-Path .venv)) {
        python -m venv .venv
        .\.venv\Scripts\python -m pip install -q -r requirements.txt
    }

    # Daqui em diante, o que o Python escreve na saída de erro é mensagem, não falha do script.
    $ErrorActionPreference = 'Continue'
    .\.venv\Scripts\python popular_banco.py @ScriptArgs
} finally {
    Pop-Location
}
exit $LASTEXITCODE
