# Roda a suíte de testes do plugin contra o WordPress local.
# Requer a stack no ar (docker compose up -d). Exemplos:
#   .\tests\rodar.ps1                              # tudo
#   .\tests\rodar.ps1 test_03_fluxo.py             # um arquivo
#   .\tests\rodar.ps1 -m "not lento"               # sem as rajadas paralelas
#   .\tests\rodar.ps1 -m seguranca                 # só as regressões de segurança
param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $PytestArgs)

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
    .\.venv\Scripts\python -m pytest @PytestArgs
} finally {
    Pop-Location
}
exit $LASTEXITCODE
