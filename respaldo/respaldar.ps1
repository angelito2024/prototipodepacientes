# =====================================================================
#  Respaldo de la base del Centro Psicológico MAGUSA
#
#  Guarda una copia completa con la fecha en el nombre y borra las que
#  pasen de 30 días, para que la carpeta no crezca sin fin.
#
#  Lo corre solo la tarea programada "MAGUSA respaldo" cada noche.
#  También se puede ejecutar a mano: clic derecho → Ejecutar con PowerShell.
#
#  Las copias contienen historias clínicas y datos de pacientes: la carpeta
#  está en .gitignore y NUNCA debe subirse a ningún repositorio.
# =====================================================================

$ErrorActionPreference = 'Stop'

$mysqldump = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe'
$base      = 'centro_psicologico'
$destino   = Join-Path $PSScriptRoot 'copias'
$registro  = Join-Path $destino 'registro.txt'
$diasQueGuarda = 30

function Anotar([string]$texto) {
    "$(Get-Date -Format 'yyyy-MM-dd HH:mm')  $texto" | Add-Content -Path $registro -Encoding utf8
}

if (-not (Test-Path $destino)) { New-Item -ItemType Directory -Path $destino | Out-Null }

if (-not (Test-Path $mysqldump)) {
    Anotar "ERROR: no se encontró mysqldump en $mysqldump"
    Write-Host "ERROR: no se encontró mysqldump. ¿Se actualizó Laragon y cambió la carpeta?" -ForegroundColor Red
    exit 1
}

$sello   = Get-Date -Format 'yyyy-MM-dd_HHmm'
$archivo = Join-Path $destino "magusa_$sello.sql"

Write-Host "Respaldando $base ..."
try {
    # Se escribe UTF-8 SIN BOM. Out-File en PowerShell 5.1 mete un BOM al
    # inicio, y hay versiones de MySQL y MariaDB que con eso fallan al
    # restaurar con un error en la línea 1 que no dice nada de la causa.
    $lineas = & $mysqldump -uroot --single-transaction --routines --triggers --events `
                  --default-character-set=utf8mb4 $base
    $sinBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllLines($archivo, $lineas, $sinBom)
} catch {
    Anotar "ERROR al respaldar: $($_.Exception.Message)"
    Write-Host "ERROR: falló el respaldo. ¿Está encendido Laragon?" -ForegroundColor Red
    exit 1
}

# Un archivo muy chico significa que algo salió mal: no lo damos por bueno.
$tam = (Get-Item $archivo).Length
if ($tam -lt 10KB) {
    Remove-Item $archivo -Force
    Anotar "ERROR: el respaldo salió vacío ($tam bytes). ¿Está encendido Laragon?"
    Write-Host "ERROR: el respaldo salió vacío. ¿Está encendido Laragon?" -ForegroundColor Red
    exit 1
}

$mb = [math]::Round($tam / 1MB, 2)
Anotar "OK  magusa_$sello.sql  ($mb MB)"
Write-Host "Listo: magusa_$sello.sql ($mb MB)" -ForegroundColor Green

# Borrar las copias viejas
$viejas = Get-ChildItem -Path $destino -Filter 'magusa_*.sql' |
          Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$diasQueGuarda) }
foreach ($v in $viejas) {
    Remove-Item $v.FullName -Force
    Anotar "Borrada por antigua: $($v.Name)"
}

$cuantas = (Get-ChildItem -Path $destino -Filter 'magusa_*.sql').Count
Write-Host "Copias guardadas: $cuantas (se conservan las de los últimos $diasQueGuarda días)"
