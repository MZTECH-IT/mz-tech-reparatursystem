Set-StrictMode -Version 2.0

if (-not ('MzTech.NativeCredential' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

namespace MzTech {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct NativeCredentialData {
        public UInt32 Flags;
        public UInt32 Type;
        public string TargetName;
        public string Comment;
        public System.Runtime.InteropServices.ComTypes.FILETIME LastWritten;
        public UInt32 CredentialBlobSize;
        public IntPtr CredentialBlob;
        public UInt32 Persist;
        public UInt32 AttributeCount;
        public IntPtr Attributes;
        public string TargetAlias;
        public string UserName;
    }

    public static class NativeCredential {
        [DllImport("advapi32.dll", EntryPoint = "CredWriteW", CharSet = CharSet.Unicode, SetLastError = true)]
        public static extern bool CredWrite(ref NativeCredentialData credential, UInt32 flags);

        [DllImport("advapi32.dll", EntryPoint = "CredReadW", CharSet = CharSet.Unicode, SetLastError = true)]
        public static extern bool CredRead(string target, UInt32 type, UInt32 flags, out IntPtr credential);

        [DllImport("advapi32.dll", EntryPoint = "CredDeleteW", CharSet = CharSet.Unicode, SetLastError = true)]
        public static extern bool CredDelete(string target, UInt32 type, UInt32 flags);

        [DllImport("advapi32.dll", SetLastError = true)]
        public static extern void CredFree(IntPtr credential);
    }
}
'@
}

$script:CredentialTypeGeneric = 1
$script:CredentialPersistLocalMachine = 2

function Test-MzTechCredential {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$Target)

    $pointer = [IntPtr]::Zero
    $found = [MzTech.NativeCredential]::CredRead(
        $Target,
        $script:CredentialTypeGeneric,
        0,
        [ref]$pointer
    )
    if ($found -and $pointer -ne [IntPtr]::Zero) {
        [MzTech.NativeCredential]::CredFree($pointer)
    }
    return $found
}

function Set-MzTechCredential {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Target,
        [Parameter(Mandatory = $true)][string]$Metadata,
        [Parameter(Mandatory = $true)][Security.SecureString]$Secret
    )

    $bstr = [IntPtr]::Zero
    $blob = [IntPtr]::Zero
    $bytes = $null
    $credential = New-Object MzTech.NativeCredentialData
    try {
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secret)
        $plainText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        $bytes = [Text.Encoding]::Unicode.GetBytes($plainText)
        if ($bytes.Length -gt 512) {
            throw 'Das Geheimnis überschreitet die Windows-Credential-Grenze von 512 Byte.'
        }

        $blob = [Runtime.InteropServices.Marshal]::AllocHGlobal($bytes.Length)
        [Runtime.InteropServices.Marshal]::Copy($bytes, 0, $blob, $bytes.Length)
        $credential.Type = $script:CredentialTypeGeneric
        $credential.TargetName = $Target
        $credential.Comment = 'MZ Tech Reparatursystem – lokal und benutzergebunden'
        $credential.CredentialBlobSize = $bytes.Length
        $credential.CredentialBlob = $blob
        $credential.Persist = $script:CredentialPersistLocalMachine
        $credential.UserName = $Metadata

        if (-not [MzTech.NativeCredential]::CredWrite([ref]$credential, 0)) {
            $errorCode = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
            throw "Windows Credential Manager konnte den Eintrag nicht speichern (Fehler $errorCode)."
        }
    }
    finally {
        if ($bytes) {
            [Array]::Clear($bytes, 0, $bytes.Length)
        }
        if ($blob -ne [IntPtr]::Zero) {
            for ($index = 0; $index -lt $credential.CredentialBlobSize; $index++) {
                [Runtime.InteropServices.Marshal]::WriteByte($blob, $index, 0)
            }
            [Runtime.InteropServices.Marshal]::FreeHGlobal($blob)
        }
        if ($bstr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
        $plainText = $null
    }
}

function Get-MzTechCredential {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$Target)

    $pointer = [IntPtr]::Zero
    if (-not [MzTech.NativeCredential]::CredRead(
        $Target,
        $script:CredentialTypeGeneric,
        0,
        [ref]$pointer
    )) {
        return $null
    }

    try {
        $native = [Runtime.InteropServices.Marshal]::PtrToStructure(
            $pointer,
            [type][MzTech.NativeCredentialData]
        )
        $secure = New-Object Security.SecureString
        if ($native.CredentialBlobSize -gt 0) {
            $bytes = New-Object byte[] $native.CredentialBlobSize
            [Runtime.InteropServices.Marshal]::Copy(
                $native.CredentialBlob,
                $bytes,
                0,
                $native.CredentialBlobSize
            )
            $chars = [Text.Encoding]::Unicode.GetChars($bytes)
            foreach ($character in $chars) {
                $secure.AppendChar($character)
            }
            $secure.MakeReadOnly()
            [Array]::Clear($chars, 0, $chars.Length)
            [Array]::Clear($bytes, 0, $bytes.Length)
        }
        return [pscustomobject]@{
            Target = $Target
            Metadata = $native.UserName
            Secret = $secure
        }
    }
    finally {
        if ($pointer -ne [IntPtr]::Zero) {
            [MzTech.NativeCredential]::CredFree($pointer)
        }
    }
}

function Remove-MzTechCredential {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$Target)

    if (-not (Test-MzTechCredential -Target $Target)) {
        return $false
    }
    if (-not [MzTech.NativeCredential]::CredDelete(
        $Target,
        $script:CredentialTypeGeneric,
        0
    )) {
        $errorCode = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
        throw "Windows Credential Manager konnte den Eintrag nicht löschen (Fehler $errorCode)."
    }
    return $true
}

function ConvertFrom-MzTechSecureString {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][Security.SecureString]$Secret)

    $bstr = [IntPtr]::Zero
    try {
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Secret)
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    }
    finally {
        if ($bstr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
    }
}

Export-ModuleMember -Function Test-MzTechCredential, Set-MzTechCredential,
    Get-MzTechCredential, Remove-MzTechCredential, ConvertFrom-MzTechSecureString
