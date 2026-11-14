# Builds the vanillagen terrain core for every supported platform into native/vanillagen/bin.
# PocketMine-MP looks for bin/vanillagen-<os>-<arch>[.exe] next to this script (or inside the phar).
#
# Go fuses x*y+z into one FMA instruction on arm64 (and other architectures), which rounds once instead of twice and
# would make the terrain differ from the PHP implementation; Go 1.27 does so even through explicit float64()
# conversions. The packages are therefore compiled with -d=fmahash=n (automatic fusion off), and every target's
# disassembly is checked for fused instructions. Always build release binaries with this script.
#
# Usage: .\build.ps1 [-Go path\to\go.exe] [-Version 1.2.3] [-Targets windows/amd64,linux/amd64] [-Test]
#   -Test  also runs the Go tests (set POCKETMINE_VANILLA_WORLDGEN_DATA to include the worldgen tests)
param(
	[string]$Go = "",
	[string]$Version = "",
	[string[]]$Targets = @("windows/amd64", "linux/amd64", "linux/arm64", "darwin/amd64", "darwin/arm64"),
	[switch]$Test
)
$ErrorActionPreference = "Stop"
$here = $PSScriptRoot

if($Go -eq ""){
	$cmd = Get-Command go -ErrorAction SilentlyContinue
	if($cmd -ne $null){ $Go = $cmd.Source }
}
if($Go -eq "" -or -not (Test-Path $Go)){
	throw "Go toolchain not found. Pass -Go C:\path\to\go\bin\go.exe or add go to PATH."
}
if($Version -eq ""){
	$sha = (& git -C $here rev-parse --short HEAD 2>$null)
	$Version = if($sha){ "git-$sha" } else { "dev" }
}

$bin = Join-Path $here "bin"
New-Item -ItemType Directory -Force $bin | Out-Null
$check = Join-Path ([System.IO.Path]::GetTempPath()) ("vanillagen-check-" + [System.Guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force $check | Out-Null

$env:CGO_ENABLED = "0"
$env:GOFLAGS = "-trimpath"
#no automatic multiply-add fusion in our packages (the flag logs every site it disables; that output is filtered)
$gcflags = "github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/...=-d=fmahash=n"
function Invoke-GoBuild([string[]]$arguments){
	$ErrorActionPreference = "Continue"
	& $Go build -gcflags $gcflags @arguments 2>&1 | ForEach-Object { "$_" } | Where-Object { $_ -notmatch '^(fmahash triggered|\[DISABLED\]|# github.com/)' } | Write-Host
	return $LASTEXITCODE
}
$ok = $true
Push-Location $here
try{
	if($Test){
		Remove-Item Env:GOOS, Env:GOARCH -ErrorAction SilentlyContinue
		& $Go vet ./...
		if($LASTEXITCODE -ne 0){ $ok = $false; Write-Warning "go vet failed" }
		& $Go test ./...
		if($LASTEXITCODE -ne 0){ $ok = $false; Write-Warning "go test failed" }
	}
	foreach($t in $Targets){
		$os, $arch = $t.Split("/")
		$env:GOOS = $os
		$env:GOARCH = $arch
		$ext = if($os -eq "windows"){ ".exe" } else { "" }
		$out = Join-Path $bin "vanillagen-$os-$arch$ext"
		Write-Host "Building $out"
		$code = Invoke-GoBuild @("-ldflags", "-s -w -X main.version=$Version", "-o", $out, ".")
		if($code -ne 0){ $ok = $false; Write-Warning "build failed for $t"; continue }

		#unstripped copy for the disassembly check
		$dbg = Join-Path $check "vanillagen-$os-$arch$ext"
		$code = Invoke-GoBuild @("-o", $dbg, ".")
		if($code -ne 0){ $ok = $false; Write-Warning "check build failed for $t"; continue }
		$asm = & $Go tool objdump -s "native/vanillagen" $dbg
		$fused = $asm | Select-String -Pattern "\b(FMADD|FMSUB|FNMADD|FNMSUB|VFMADD|VFMSUB|VFNMADD|VFNMSUB)\w*" -CaseSensitive
		if($fused){
			$ok = $false
			Write-Warning "$t has fused multiply-add instructions in the generator (results would differ from PHP):"
			$fused | Select-Object -First 10 | ForEach-Object { Write-Warning $_.Line.Trim() }
		}
	}
}finally{
	Pop-Location
	Remove-Item Env:GOOS, Env:GOARCH -ErrorAction SilentlyContinue
	Remove-Item -Recurse -Force $check -ErrorAction SilentlyContinue
}

Get-ChildItem $bin | ForEach-Object {
	"{0,-40} {1,10:N0} bytes  sha256 {2}" -f $_.Name, $_.Length, (Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLower()
}
if(-not $ok){ exit 1 }
