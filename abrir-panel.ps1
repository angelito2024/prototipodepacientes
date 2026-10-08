# =====================================================================
#  Abrir el Panel del Centro Psicológico MAGUSA
#
#  Lo usa el acceso directo del escritorio. Hace lo que hay que hacer
#  para que la pantalla abra, en este orden:
#
#    1. ¿El panel ya responde? Entonces solo abre el navegador.
#    2. Si no, levanta la base de datos y el servidor web.
#    3. Espera a que contesten y recién ahí abre el navegador.
#    4. Si algo no levanta, lo dice con palabras, no con un error.
#
#  Por qué hace falta: Apache y MySQL no son servicios de Windows en
#  esta máquina, los arranca Laragon. Con Laragon cerrado, un acceso
#  directo a la dirección muestra "no se puede acceder a este sitio",
#  que no le dice a nadie qué hacer.
#
#  Las carpetas de Apache y MySQL se buscan por patrón: cuando Laragon
#  se actualice, la versión cambia de nombre y esto sigue funcionando.
# =====================================================================

#  El mismo lanzador sirve para los dos paneles. Se le dice cuál con
#  -Panel: "prototipodepacientes" (el centro, por defecto) o
#  "misfinanzas" (las cuentas de Luis, que viven en otra base).
param(
    [string]$Panel = 'prototipodepacientes',
    [string]$Titulo = 'Centro Psicológico MAGUSA'
)

$ErrorActionPreference = 'Stop'

$direccion    = "http://localhost/$Panel/"
$comprobacion = $direccion + 'api/index.php?accion=salud'
$laragon      = 'C:\laragon'
$esperaMax    = 60          # segundos

# Abrir en ventana propia, sin barra de direcciones ni pestañas, para que
# se use como un programa y no como una página. Si no hay Chrome, se abre
# en el navegador que la computadora tenga puesto.
# Para volver a la ventana normal del navegador: poner $comoPrograma = $false
$comoPrograma = $true

function Responde {
    try {
        $r = Invoke-WebRequest -Uri $comprobacion -UseBasicParsing -TimeoutSec 4
        return $r.StatusCode -eq 200
    } catch { return $false }
}

function Avisar([string]$titulo, [string]$texto) {
    Add-Type -AssemblyName System.Windows.Forms
    [System.Windows.Forms.MessageBox]::Show($texto, $titulo,
        [System.Windows.Forms.MessageBoxButtons]::OK,
        [System.Windows.Forms.MessageBoxIcon]::Warning) | Out-Null
}

function PrimeroQueExista([string]$patron) {
    $c = Get-ChildItem -Path $patron -ErrorAction SilentlyContinue |
         Sort-Object Name -Descending | Select-Object -First 1
    if ($null -eq $c) { return $null }
    return $c.FullName
}

function AbrirPanel {
    if ($comoPrograma) {
        foreach ($chrome in @(
            "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
            "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
            "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe")) {
            if (Test-Path $chrome) {
                Start-Process -FilePath $chrome -ArgumentList "--app=$direccion"
                return
            }
        }
    }
    Start-Process $direccion
}

# Mientras enciende, algo en pantalla. Sin esto son siete segundos de
# nada después del doble clic, y la gente vuelve a hacer doble clic.
function MostrarEspera {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    $f = New-Object System.Windows.Forms.Form
    $f.Text = $Titulo
    $f.Size = New-Object System.Drawing.Size(380, 130)
    $f.StartPosition = 'CenterScreen'
    $f.FormBorderStyle = 'FixedDialog'
    $f.MaximizeBox = $false; $f.MinimizeBox = $false
    $f.BackColor = [System.Drawing.Color]::FromArgb(246, 244, 236)
    $f.TopMost = $true
    $ico = Join-Path $PSScriptRoot 'panel.ico'
    if (Test-Path $ico) { $f.Icon = New-Object System.Drawing.Icon($ico) }

    $l = New-Object System.Windows.Forms.Label
    $l.Text = "Encendiendo el panel…`r`n`r`nEsto tarda unos segundos la primera vez del día."
    $l.Font = New-Object System.Drawing.Font('Segoe UI', 10)
    $l.ForeColor = [System.Drawing.Color]::FromArgb(31, 44, 38)
    $l.AutoSize = $false
    $l.Size = New-Object System.Drawing.Size(340, 70)
    $l.Location = New-Object System.Drawing.Point(20, 15)
    $f.Controls.Add($l)
    $f.Show()
    $f.Refresh()
    return $f
}

# --- 1. Si ya está levantado, no se toca nada ------------------------
if (Responde) {
    AbrirPanel
    exit 0
}

# --- 2. Levantar lo que falte ----------------------------------------
$espera = MostrarEspera

$mysqld = PrimeroQueExista "$laragon\bin\mysql\*\bin\mysqld.exe"
$httpd  = PrimeroQueExista "$laragon\bin\apache\*\bin\httpd.exe"

if ($null -eq $mysqld -or $null -eq $httpd) {
    if ($espera) { $espera.Close() }
    Avisar 'No encuentro Laragon' @"
No encuentro el servidor web o la base de datos dentro de C:\laragon.

Abre Laragon a mano y dale a "Iniciar todo". Si Laragon ya no está
instalado en esa carpeta, avísale a quien te ayuda con el sistema.
"@
    exit 1
}

# No se arranca lo que ya está corriendo: dos MySQL sobre la misma
# carpeta de datos se pelean y uno de los dos se cae.
if (-not (Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue)) {
    $ini = Join-Path (Split-Path (Split-Path $mysqld)) 'my.ini'
    if (Test-Path $ini) {
        Start-Process -FilePath $mysqld -ArgumentList "--defaults-file=`"$ini`"" -WindowStyle Hidden
    } else {
        Start-Process -FilePath $mysqld -WindowStyle Hidden
    }
}
if (-not (Get-Process -Name 'httpd' -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $httpd -WindowStyle Hidden
}

# --- 3. Esperar a que contesten --------------------------------------
$fin = (Get-Date).AddSeconds($esperaMax)
while ((Get-Date) -lt $fin) {
    Start-Sleep -Seconds 2
    if (Responde) {
        if ($espera) { $espera.Close() }
        AbrirPanel
        exit 0
    }
}

# --- 4. No levantó: decir qué pasa -----------------------------------
if ($espera) { $espera.Close() }
$hayMysql  = [bool](Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue)
$hayApache = [bool](Get-Process -Name 'httpd'  -ErrorAction SilentlyContinue)
$detalle = "Base de datos: $(if ($hayMysql) {'encendida'} else {'NO arrancó'})`r`n" +
           "Servidor web : $(if ($hayApache) {'encendido'} else {'NO arrancó'})"

Avisar 'El panel no llegó a abrir' @"
Esperé $esperaMax segundos y el panel todavía no responde.

$detalle

Qué hacer:
  1. Abre Laragon (el ícono del escritorio o el menú Inicio).
  2. Dale a "Iniciar todo" y espera a que Apache y MySQL queden en verde.
  3. Vuelve a usar este acceso directo.

Si sigue sin abrir, avísale a quien te ayuda con el sistema y dile
exactamente qué dice este mensaje.
"@
exit 1
