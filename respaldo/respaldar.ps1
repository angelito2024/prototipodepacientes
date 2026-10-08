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

# -----------------------------------------------------------------
#  El parte del respaldo, en un archivo que el panel pueda leer
#
#  El panel corre sobre Apache, con otro usuario de Windows: no alcanza
#  la carpeta de OneDrive ni puede saber si la copia salió. Y el
#  registro.txt es prosa, no se puede interpretar.
#
#  Así que el script deja acá lo que hizo. Es lo que mira el panel para
#  avisar en pantalla cuando hace días que no sale una copia, en vez de
#  que el fallo quede escondido en un .txt que nadie abre.
# -----------------------------------------------------------------
$estadoArchivo = Join-Path $destino 'estado.json'
$estado = [ordered]@{
    corrioEn    = (Get-Date -Format 'yyyy-MM-dd HH:mm')
    local       = $null
    fuera       = $null
    problema    = $null
}
function GuardarEstado {
    # Sin BOM: con BOM, json_decode() de PHP devuelve null y el panel
    # creería que nunca se respaldó nada.
    $sinBomJson = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($estadoArchivo,
        ($estado | ConvertTo-Json -Depth 4), $sinBomJson)
}

if (-not (Test-Path $destino)) { New-Item -ItemType Directory -Path $destino | Out-Null }

if (-not (Test-Path $mysqldump)) {
    Anotar "ERROR: no se encontró mysqldump en $mysqldump"
    Write-Host "ERROR: no se encontró mysqldump. ¿Se actualizó Laragon y cambió la carpeta?" -ForegroundColor Red
    exit 1
}

# --- Encender la base si hace falta ---------------------------------
#
#  En esta máquina MySQL no es un servicio de Windows: lo levanta Laragon.
#  La tarea de la noche se dispara a las 21:30 esté Laragon abierto o no,
#  y si no lo está, mysqldump no devuelve nada y el respaldo falla. Pasó
#  el 5 y el 7 de octubre, y solo se supo porque el registro lo anotó.
#
#  Así que el respaldo enciende la base él mismo. No la apaga después:
#  apagarla podría cortarle el trabajo a alguien que esté usando el panel.
if (-not (Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue)) {
    $mysqld = Get-ChildItem 'C:\laragon\bin\mysql\*\bin\mysqld.exe' -ErrorAction SilentlyContinue |
              Sort-Object Name -Descending | Select-Object -First 1
    if ($null -ne $mysqld) {
        Write-Host 'La base estaba apagada: encendiéndola...'
        $ini = Join-Path (Split-Path (Split-Path $mysqld.FullName)) 'my.ini'
        if (Test-Path $ini) {
            Start-Process -FilePath $mysqld.FullName -ArgumentList "--defaults-file=`"$ini`"" -WindowStyle Hidden
        } else {
            Start-Process -FilePath $mysqld.FullName -WindowStyle Hidden
        }
        # Esperar a que acepte conexiones: arrancar no es estar listo.
        $mysqladmin = Join-Path (Split-Path $mysqld.FullName) 'mysqladmin.exe'
        $fin = (Get-Date).AddSeconds(60)
        $lista = $false
        while ((Get-Date) -lt $fin -and -not $lista) {
            Start-Sleep -Seconds 2
            & $mysqladmin -uroot ping *>$null
            if ($LASTEXITCODE -eq 0) { $lista = $true }
        }
        if ($lista) {
            Anotar 'La base estaba apagada; se encendió para respaldar'
        } else {
            Anotar 'ERROR: la base no llegó a encender'
        }
    }
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
    $estado.problema = "No se pudo respaldar. ¿Está encendido Laragon?"
    GuardarEstado
    Anotar "ERROR al respaldar: $($_.Exception.Message)"
    Write-Host "ERROR: falló el respaldo. ¿Está encendido Laragon?" -ForegroundColor Red
    exit 1
}

# Un archivo muy chico significa que algo salió mal: no lo damos por bueno.
$tam = (Get-Item $archivo).Length
if ($tam -lt 10KB) {
    Remove-Item $archivo -Force
    $estado.problema = "El respaldo salió vacío. ¿Está encendido Laragon?"
    GuardarEstado
    Anotar "ERROR: el respaldo salió vacío ($tam bytes). ¿Está encendido Laragon?"
    Write-Host "ERROR: el respaldo salió vacío. ¿Está encendido Laragon?" -ForegroundColor Red
    exit 1
}

$mb = [math]::Round($tam / 1MB, 2)
$estado.local = [ordered]@{
    archivo = "magusa_$sello.sql"
    cuando  = (Get-Date -Format 'yyyy-MM-dd HH:mm')
    mb      = $mb
}
GuardarEstado
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
    $estado.problema = "Falta respaldo\clave.txt: la copia no sale de esta computadora."
    GuardarEstado
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
        $cuantasNube = (Get-ChildItem -Path $nube -Filter 'magusa_*.sql.cifrado').Count
        $estado.fuera = [ordered]@{
            archivo = "magusa_$sello.sql.cifrado"
            cuando  = (Get-Date -Format 'yyyy-MM-dd HH:mm')
            mb      = $mbc
            cuantas = $cuantasNube
            donde   = 'OneDrive'
        }
        GuardarEstado
        Anotar "OK  copia cifrada en OneDrive ($mbc MB)"
        Write-Host "Copia cifrada en OneDrive: $mbc MB" -ForegroundColor Green
    } catch {
        $estado.problema = "La copia local se hizo, pero no salió de la computadora."
        GuardarEstado
        Anotar "ERROR al sacar la copia del local: $($_.Exception.Message)"
        Write-Host "ERROR: la copia local sí se hizo, pero no salió de la PC." -ForegroundColor Red
        Write-Host "  $($_.Exception.Message)" -ForegroundColor Red
    }
}

# =====================================================================
#  Las finanzas personales, que viven en su propia base
#
#  Desde que se separaron del sistema del centro, un respaldo que solo
#  copie `centro_psicologico` deja fuera los préstamos, las juntas y las
#  cuentas de casa. Se copian acá, con el mismo criterio: una copia
#  local y otra cifrada fuera de la computadora.
# =====================================================================

$basePersonal = 'finanzas_personales'
$existe = & $mysqldump --no-data --skip-comments -uroot $basePersonal 2>$null
if ($LASTEXITCODE -eq 0 -and $existe) {
    $archivoP = Join-Path $destino "misfinanzas_$sello.sql"
    try {
        $lineasP = & $mysqldump -uroot --single-transaction --routines --triggers --events `
                        --default-character-set=utf8mb4 $basePersonal
        [System.IO.File]::WriteAllLines($archivoP, $lineasP, (New-Object System.Text.UTF8Encoding $false))
        $tamP = (Get-Item $archivoP).Length
        if ($tamP -lt 2KB) {
            Remove-Item $archivoP -Force
            $estado.problema = "El respaldo de las finanzas personales salió vacío."
            Anotar "ERROR: respaldo personal vacío ($tamP bytes)"
        } else {
            $mbP = [math]::Round($tamP / 1MB, 2)
            $estado.personal = [ordered]@{
                archivo = "misfinanzas_$sello.sql"
                cuando  = (Get-Date -Format 'yyyy-MM-dd HH:mm')
                mb      = $mbP
                fuera   = $false
            }
            Anotar "OK  misfinanzas_$sello.sql  ($mbP MB)"
            Write-Host "Listo: misfinanzas_$sello.sql ($mbP MB)" -ForegroundColor Green

            if ($null -ne $clave) {
                $cifradoP = Join-Path $nube "misfinanzas_$sello.sql.cifrado"
                Protect-Archivo -Origen $archivoP -Destino $cifradoP -Clave $clave
                Get-ChildItem -Path $nube -Filter 'misfinanzas_*.sql.cifrado' |
                    Sort-Object LastWriteTime -Descending | Select-Object -Skip 14 |
                    ForEach-Object { Remove-Item $_.FullName -Force }
                $estado.personal.fuera = $true
                Anotar "OK  finanzas personales cifradas en OneDrive"
                Write-Host "Finanzas personales cifradas en OneDrive" -ForegroundColor Green
            }
        }
    } catch {
        $estado.problema = "No se pudo respaldar las finanzas personales."
        Anotar "ERROR al respaldar finanzas personales: $($_.Exception.Message)"
    }
    # Las viejas, igual que las del centro.
    Get-ChildItem -Path $destino -Filter 'misfinanzas_*.sql' |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$diasQueGuarda) } |
        ForEach-Object { Remove-Item $_.FullName -Force }
    GuardarEstado
}
