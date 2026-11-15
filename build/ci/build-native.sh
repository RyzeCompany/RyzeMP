#!/bin/bash
# CI builds the native Go worldgen helper for external installation by the Egg.
# RyzeRTC is released separately. pm-nethernet is no longer downloaded or used.
# Usage: NATIVE_TARGETS="linux/amd64" build/ci/build-native.sh [version]
# Third-party native bridge and Go generator retain their upstream attribution.
set -euo pipefail

VERSION="${1:-git-$(git rev-parse --short HEAD)}"
TARGETS="${NATIVE_TARGETS:-windows/amd64 linux/amd64 linux/arm64 darwin/amd64 darwin/arm64}"
export CGO_ENABLED=0
export GOFLAGS=-trimpath
ROOT="$(pwd)"

echo "== vanillagen"
#automatic multiply-add fusion would make the terrain differ from the PHP implementation (see build.ps1)
cd "$ROOT/native/vanillagen"
GCFLAGS="github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/...=-d=fmahash=n"
CHECK="$(mktemp -d)"
trap 'rm -rf "$CHECK"' EXIT
filter(){ grep -Ev '^(fmahash triggered|\[DISABLED\]|# github.com/)' || true; }
mkdir -p bin
for t in $TARGETS; do
	os="${t%/*}"; arch="${t#*/}"
	ext=""; [ "$os" = "windows" ] && ext=".exe"
	echo "Building bin/vanillagen-$os-$arch$ext"
	GOOS="$os" GOARCH="$arch" go build -gcflags "$GCFLAGS" -ldflags "-s -w -X main.version=$VERSION" -o "bin/vanillagen-$os-$arch$ext" . 2>&1 | filter
	#unstripped copy for the disassembly check
	GOOS="$os" GOARCH="$arch" go build -gcflags "$GCFLAGS" -o "$CHECK/vanillagen-$os-$arch$ext" . 2>&1 | filter
	if go tool objdump -s "native/vanillagen" "$CHECK/vanillagen-$os-$arch$ext" | grep -E '\b(FMADD|FMSUB|FNMADD|FNMSUB|VFMADD|VFMSUB|VFNMADD|VFNMSUB)\w*' | head -n 10 | grep .; then
		echo "$t has fused multiply-add instructions in the generator (results would differ from PHP)" >&2
		exit 1
	fi
done

cd "$ROOT"
sha256sum native/vanillagen/bin/*
