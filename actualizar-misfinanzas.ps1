# Arma (o actualiza) la instalacion de finanzas personales.
# Copia el PROGRAMA desde el panel del centro; nunca los datos ni la config.
$origen  = 'C:\laragon\www\prototipodepacientes'
$destino = 'C:\laragon\www\misfinanzas'

if (-not (Test-Path $destino)) { New-Item -ItemType Directory -Path $destino | Out-Null }

# Lo que se copia: el programa y nada mas.
$archivos = @('index.html', '.htaccess')
$carpetas = @('api', 'src')

foreach ($a in $archivos) {
    $o = Join-Path $origen $a
    if (Test-Path $o) { Copy-Item $o (Join-Path $destino $a) -Force }
}
foreach ($c in $carpetas) {
    $o = Join-Path $origen $c
    $d = Join-Path $destino $c
    if (Test-Path $d) { Remove-Item $d -Recurse -Force }
    Copy-Item $o $d -Recurse -Force
}

# Lo que NO se copia nunca: config (apunta a otra base), storage (adjuntos
# clinicos), respaldo (copias y la clave), db, y las carpetas publicas de
# pacientes, que en este panel no tienen nada que hacer.
foreach ($sobra in @('db', 'storage', 'respaldo', 'pruebas', 'materiales', 'practicantes')) {
    $p = Join-Path $destino $sobra
    if (Test-Path $p) { Remove-Item $p -Recurse -Force }
}

# Su propia configuracion: misma instalacion de MySQL, OTRA base y OTRO
# usuario. Si ya existe NO se toca: tiene la clave de app_finanzas, que no
# esta en ningun otro lado. Pisarla deja el panel sin poder entrar a su
# base (pasó una vez: la copia volvio a dejar ahi el usuario del centro).
$cfgDir = Join-Path $destino 'config'
if (-not (Test-Path $cfgDir)) { New-Item -ItemType Directory -Path $cfgDir | Out-Null }

if (Test-Path (Join-Path $cfgDir 'config.php')) {
    Write-Host 'La configuracion ya existe: no se toca.'
    Write-Host "Listo: $destino"
    exit 0
}

$cfgOrigen = Get-Content (Join-Path $origen 'config\config.php') -Raw
$cfgNueva  = $cfgOrigen -replace "'nombre'(\s*)=>(\s*)'centro_psicologico'", "'nombre'`$1=>`$2'finanzas_personales'"
if ($cfgNueva -eq $cfgOrigen) {
    Write-Host 'ERROR: no pude cambiar el nombre de la base en la configuracion.' -ForegroundColor Red
    exit 1
}
$sinBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText((Join-Path $cfgDir 'config.php'), $cfgNueva, $sinBom)

# La configuracion son credenciales: no se sirve por HTTP.
[System.IO.File]::WriteAllText((Join-Path $cfgDir '.htaccess'),
    "# Credenciales: nada de esta carpeta se sirve por HTTP.`r`nRequire all denied`r`n", $sinBom)

Write-Host "Listo: $destino"
Write-Host "Base de datos: finanzas_personales"
