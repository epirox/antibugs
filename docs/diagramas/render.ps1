# Regenera los PNG a partir de los bloques ```mermaid de ../06-diagramas.md
# Uso:  powershell -File render.ps1   (requiere Node.js; descarga mermaid-cli con npx)

$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$md = [IO.File]::ReadAllText((Join-Path $here '..\06-diagramas.md'), [Text.Encoding]::UTF8)
$blocks = [regex]::Matches($md, '(?s)```mermaid\s*\n(.*?)```')
$names = 'arquitectura', 'secuencia-cambio-estado', 'maquina-de-estados', 'modelo-de-datos', 'docker-compose', 'pipeline-cicd', 'observabilidad'

for ($i = 0; $i -lt $blocks.Count; $i++) {
    $base = Join-Path $here ('{0:00}-{1}' -f ($i + 1), $names[$i])
    [IO.File]::WriteAllText("$base.mmd", $blocks[$i].Groups[1].Value, (New-Object Text.UTF8Encoding $false))
    Write-Host "→ $base.png"
    npx -y @mermaid-js/mermaid-cli@11 -i "$base.mmd" -o "$base.png" -b white -s 2 -c (Join-Path $here 'mermaid.config.json')
}
