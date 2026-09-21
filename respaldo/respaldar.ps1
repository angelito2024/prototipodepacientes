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

# =====================================================================
#  Una copia fuera de esta computadora
#
#  Todas las copias de arriba viven en el mismo disco que la base que
#  respaldan: un disco malogrado, un robo o un virus que cifre el equipo
#  se lleva el original y las copias de una sola vez.
#
#  Por eso sube una a OneDrive, y sube CIFRADA: contiene historias
#  clínicas, y en la nube la puede abrir quien entre a esa cuenta. El
#  archivo que sale es ilegible sin la contraseña de respaldo/clave.txt,
#  que se queda acá y nunca sube.
# =====================================================================

. (Join-Path $PSScriptRoot 'cifrar.ps1')

$nube = Join-Path $env:USERPROFILE 'OneDrive\Respaldos MAGUSA'
$clave = Get-ClaveRespaldo -Carpeta $PSScriptRoot

if ($null -eq $clave) {
    Anotar "AVISO: no hay respaldo/clave.txt, la copia NO salió del local"
    Write-Host ""
    Write-Host "AVISO: la copia no salió de esta computadora." -ForegroundColor Yellow
    Write-Host "  Crea el archivo respaldo\clave.txt con una contraseña de 12 o más" -ForegroundColor Yellow
    Write-Host "  caracteres y anótala en papel. Sin ella no se puede cifrar, y sin" -ForegroundColor Yellow
    Write-Host "  cifrar no se sube: en la nube esos datos quedan legibles." -ForegroundColor Yellow
} else {
    try {
        if (-not (Test-Path $nube)) { New-Item -ItemType Directory -Path $nube -Force | Out-Null }
        $cifrado = Join-Path $nube "magusa_$sello.sql.cifrado"
        Protect-Archivo -Origen $archivo -Destino $cifrado -Clave $clave

        # Afuera se guardan menos copias: la nube es el respaldo de último
        # recurso, no el archivo histórico.
        $viejasNube = Get-ChildItem -Path $nube -Filter 'magusa_*.sql.cifrado' |
                      Sort-Object LastWriteTime -Descending | Select-Object -Skip 14
        foreach ($v in $viejasNube) { Remove-Item $v.FullName -Force }

        $mbc = [math]::Round((Get-Item $cifrado).Length / 1MB, 2)
        Anotar "OK  copia cifrada en OneDrive ($mbc MB)"
        Write-Host "Copia cifrada en OneDrive: $mbc MB" -ForegroundColor Green
    } catch {
        Anotar "ERROR al sacar la copia del local: $($_.Exception.Message)"
        Write-Host "ERROR: la copia local sí se hizo, pero no salió de la PC." -ForegroundColor Red
        Write-Host "  $($_.Exception.Message)" -ForegroundColor Red
    }
}
