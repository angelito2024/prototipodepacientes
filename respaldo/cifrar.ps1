# =====================================================================
#  Cifrado de las copias que salen del local
#
#  Una copia de la base contiene historias clínicas completas. Guardarla
#  en OneDrive tal cual es entregársela a un tercero: quien entre a esa
#  cuenta —o quien la administre— puede abrirla y leerla.
#
#  Por eso lo que sale del local sale cifrado. El archivo que sube es
#  ilegible sin la contraseña, y la contraseña no viaja con él: vive en
#  respaldo/clave.txt, que está fuera del repositorio y fuera de OneDrive.
#
#  Cifrado: AES-256 en modo CBC. La contraseña no se usa directamente;
#  pasa por PBKDF2 con 200 000 vueltas y una sal distinta en cada archivo,
#  que es lo que hace cara de probar una contraseña a quien lo intente.
#
#  Formato del archivo:
#     "MAGUSA1"  (7 bytes)  +  sal (16)  +  vector inicial (16)  +  datos
# =====================================================================

$ErrorActionPreference = 'Stop'

function Get-ClaveRespaldo {
    <#
      Lee la contraseña de respaldo/clave.txt. Si no existe, no inventa
      una: avisa y corta. Una copia cifrada con una contraseña que nadie
      anotó es una copia perdida.
    #>
    param([string]$Carpeta)

    $ruta = Join-Path $Carpeta 'clave.txt'
    if (-not (Test-Path $ruta)) { return $null }
    $clave = (Get-Content $ruta -Raw -Encoding UTF8).Trim()
    if ($clave.Length -lt 12) {
        throw "La contraseña de respaldo/clave.txt es muy corta ($($clave.Length) caracteres). Usa 12 o más."
    }
    return $clave
}

function Protect-Archivo {
    <# Cifra $Origen y escribe el resultado en $Destino. #>
    param(
        [Parameter(Mandatory)] [string] $Origen,
        [Parameter(Mandatory)] [string] $Destino,
        [Parameter(Mandatory)] [string] $Clave
    )

    $marca = [System.Text.Encoding]::ASCII.GetBytes('MAGUSA1')
    $rng   = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $sal   = New-Object byte[] 16
    $rng.GetBytes($sal)

    # PBKDF2: convierte la contraseña en una llave de 256 bits. Las 200 000
    # vueltas son para que probar contraseñas una por una salga caro.
    $kdf   = New-Object System.Security.Cryptography.Rfc2898DeriveBytes(
                 $Clave, $sal, 200000,
                 [System.Security.Cryptography.HashAlgorithmName]::SHA256)
    $llave = $kdf.GetBytes(32)

    $aes = [System.Security.Cryptography.Aes]::Create()
    $aes.KeySize  = 256
    $aes.Mode     = [System.Security.Cryptography.CipherMode]::CBC
    $aes.Padding  = [System.Security.Cryptography.PaddingMode]::PKCS7
    $aes.Key      = $llave
    $aes.GenerateIV()

    $entrada = [System.IO.File]::OpenRead($Origen)
    $salida  = [System.IO.File]::Create($Destino)
    try {
        $salida.Write($marca, 0, $marca.Length)
        $salida.Write($sal,   0, $sal.Length)
        $salida.Write($aes.IV,0, $aes.IV.Length)
        $cripto = New-Object System.Security.Cryptography.CryptoStream(
                      $salida, $aes.CreateEncryptor(),
                      [System.Security.Cryptography.CryptoStreamMode]::Write)
        $entrada.CopyTo($cripto)
        $cripto.FlushFinalBlock()
        $cripto.Dispose()
    } finally {
        $entrada.Dispose(); $salida.Dispose()
        $aes.Dispose(); $kdf.Dispose(); $rng.Dispose()
    }
}

function Unprotect-Archivo {
    <#
      Descifra una copia. Esto es lo que hay que correr el día que haga
      falta restaurar desde OneDrive:

          . .\cifrar.ps1
          Unprotect-Archivo -Origen "magusa_....sql.cifrado" `
                            -Destino "magusa_recuperado.sql" -Clave "tu contraseña"
    #>
    param(
        [Parameter(Mandatory)] [string] $Origen,
        [Parameter(Mandatory)] [string] $Destino,
        [Parameter(Mandatory)] [string] $Clave
    )

    $entrada = [System.IO.File]::OpenRead($Origen)
    try {
        $marca = New-Object byte[] 7
        [void]$entrada.Read($marca, 0, 7)
        if ([System.Text.Encoding]::ASCII.GetString($marca) -ne 'MAGUSA1') {
            throw "Ese archivo no es una copia cifrada de MAGUSA."
        }
        $sal = New-Object byte[] 16; [void]$entrada.Read($sal, 0, 16)
        $iv  = New-Object byte[] 16; [void]$entrada.Read($iv,  0, 16)

        $kdf = New-Object System.Security.Cryptography.Rfc2898DeriveBytes(
                   $Clave, $sal, 200000,
                   [System.Security.Cryptography.HashAlgorithmName]::SHA256)
        $aes = [System.Security.Cryptography.Aes]::Create()
        $aes.KeySize = 256
        $aes.Mode    = [System.Security.Cryptography.CipherMode]::CBC
        $aes.Padding = [System.Security.Cryptography.PaddingMode]::PKCS7
        $aes.Key     = $kdf.GetBytes(32)
        $aes.IV      = $iv

        $salida = [System.IO.File]::Create($Destino)
        try {
            $cripto = New-Object System.Security.Cryptography.CryptoStream(
                          $entrada, $aes.CreateDecryptor(),
                          [System.Security.Cryptography.CryptoStreamMode]::Read)
            $cripto.CopyTo($salida)
            $cripto.Dispose()
        } catch {
            $salida.Dispose(); Remove-Item $Destino -Force -ErrorAction SilentlyContinue
            throw "No se pudo descifrar. Casi siempre es la contraseña equivocada."
        } finally {
            if (-not $salida.SafeFileHandle.IsClosed) { $salida.Dispose() }
        }
        $aes.Dispose(); $kdf.Dispose()
    } finally {
        $entrada.Dispose()
    }
}
