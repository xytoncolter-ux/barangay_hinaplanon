$ErrorActionPreference = 'Stop'
$python = Join-Path $PSScriptRoot '.venv/Scripts/python.exe'
if (!(Test-Path $python)) { throw 'Missing virtual environment. See README.md for installation.' }
Push-Location $PSScriptRoot
try {
    & $python -m uvicorn ml_api:app --host 127.0.0.1 --port 8001
    if ($LASTEXITCODE -ne 0) { throw "ML service exited with code $LASTEXITCODE" }
} finally {
    Pop-Location
}
