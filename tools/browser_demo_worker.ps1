$ErrorActionPreference = 'Stop'
$demoRoot = Split-Path -Parent $PSScriptRoot
$demoHost = Join-Path $demoRoot '.work\vito-8598e4dc0bce12d2b2f915766a100b445b7d9e22'
if (-not (Test-Path -LiteralPath (Join-Path $demoHost 'storage\vpgm-browser.sqlite'))) { throw 'Run setup_browser_demo.php first.' }
Set-Location -LiteralPath $demoHost
Write-Output 'Local demo worker active (3-second tick). Stop this process to stop the demo queue.'
while ($true) {
    & php artisan vito-pg:work --no-interaction
    Start-Sleep -Seconds 3
}
