Set-StrictMode -Version 2.0

Add-Type -AssemblyName System.Security

$script:FonedaySecretDirectory = Join-Path (
    [Environment]::GetFolderPath([Environment+SpecialFolder]::LocalApplicationData)
) 'MZTechRepairSystem\secrets'
$script:FonedaySecretPath = Join-Path $script:FonedaySecretDirectory 'foneday_token.dat'
$script:FonedayEntropy = [Text.Encoding]::UTF8.GetBytes('MZTechRepairSystem:FonedayToken:v1')

function Set-MzTechPrivateDirectoryAcl {
    param([Parameter(Mandatory = $true)][string]$Path)

    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $security = New-Object Security.AccessControl.DirectorySecurity
    $security.SetOwner($identity.User)
    $security.SetAccessRuleProtection($true, $false)
    $rule = New-Object Security.AccessControl.FileSystemAccessRule(
        $identity.User,
        [Security.AccessControl.FileSystemRights]::FullControl,
        [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
        [Security.AccessControl.PropagationFlags]::None,
        [Security.AccessControl.AccessControlType]::Allow
    )
    $security.AddAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $security
}

function Set-MzTechPrivateFileAcl {
    param([Parameter(Mandatory = $true)][string]$Path)

    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $security = New-Object Security.AccessControl.FileSecurity
    $security.SetOwner($identity.User)
    $security.SetAccessRuleProtection($true, $false)
    $rule = New-Object Security.AccessControl.FileSystemAccessRule(
        $identity.User,
        [Security.AccessControl.FileSystemRights]::FullControl,
        [Security.AccessControl.AccessControlType]::Allow
    )
    $security.AddAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $security
}

function Set-MzTechFonedayToken {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][Security.SecureString]$Token)

    if ($Token.Length -eq 0) {
        throw 'Der Foneday-Token darf nicht leer sein.'
    }

    $bstr = [IntPtr]::Zero
    $characters = $null
    $plainBytes = $null
    $protectedBytes = $null
    try {
        if (-not (Test-Path -LiteralPath $script:FonedaySecretDirectory -PathType Container)) {
            New-Item -ItemType Directory -Path $script:FonedaySecretDirectory -Force | Out-Null
        }
        Set-MzTechPrivateDirectoryAcl -Path $script:FonedaySecretDirectory

        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Token)
        $characterCount = [Runtime.InteropServices.Marshal]::ReadInt32($bstr, -4) / 2
        $characters = New-Object char[] $characterCount
        for ($index = 0; $index -lt $characterCount; $index++) {
            $characters[$index] = [char][Runtime.InteropServices.Marshal]::ReadInt16(
                $bstr,
                $index * 2
            )
        }
        $plainBytes = [Text.Encoding]::UTF8.GetBytes($characters)
        $protectedBytes = [Security.Cryptography.ProtectedData]::Protect(
            $plainBytes,
            $script:FonedayEntropy,
            [Security.Cryptography.DataProtectionScope]::CurrentUser
        )

        [IO.File]::WriteAllBytes($script:FonedaySecretPath, $protectedBytes)
        Set-MzTechPrivateFileAcl -Path $script:FonedaySecretPath
    }
    finally {
        if ($characters) {
            [Array]::Clear($characters, 0, $characters.Length)
        }
        if ($plainBytes) {
            [Array]::Clear($plainBytes, 0, $plainBytes.Length)
        }
        if ($protectedBytes) {
            [Array]::Clear($protectedBytes, 0, $protectedBytes.Length)
        }
        if ($bstr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
    }
}

function Get-MzTechFonedayToken {
    [CmdletBinding()]
    param()

    if (-not (Test-Path -LiteralPath $script:FonedaySecretPath -PathType Leaf)) {
        return $null
    }

    $protectedBytes = $null
    $plainBytes = $null
    $characters = $null
    try {
        $protectedBytes = [IO.File]::ReadAllBytes($script:FonedaySecretPath)
        $plainBytes = [Security.Cryptography.ProtectedData]::Unprotect(
            $protectedBytes,
            $script:FonedayEntropy,
            [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
        $characters = [Text.Encoding]::UTF8.GetChars($plainBytes)
        $secure = New-Object Security.SecureString
        foreach ($character in $characters) {
            $secure.AppendChar($character)
        }
        $secure.MakeReadOnly()
        return $secure
    }
    finally {
        if ($characters) {
            [Array]::Clear($characters, 0, $characters.Length)
        }
        if ($plainBytes) {
            [Array]::Clear($plainBytes, 0, $plainBytes.Length)
        }
        if ($protectedBytes) {
            [Array]::Clear($protectedBytes, 0, $protectedBytes.Length)
        }
    }
}

function Test-MzTechFonedayToken {
    [CmdletBinding()]
    param()
    return Test-Path -LiteralPath $script:FonedaySecretPath -PathType Leaf
}

function Remove-MzTechFonedayToken {
    [CmdletBinding()]
    param()

    if (Test-Path -LiteralPath $script:FonedaySecretPath -PathType Leaf) {
        Remove-Item -LiteralPath $script:FonedaySecretPath -Force
        return $true
    }
    return $false
}

Export-ModuleMember -Function Set-MzTechFonedayToken, Get-MzTechFonedayToken,
    Test-MzTechFonedayToken, Remove-MzTechFonedayToken
