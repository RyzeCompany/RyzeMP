#!/bin/bash
# CI step for the native programs, into native/*/bin where server-phar.php picks them up:
# - pm-nethernet (NetherNet bridge): downloaded from the releases of gitlab.com/VanillaPocketMine/nethernet, version
#   NETHERNET_VERSION (default 0.1.2), checked against the upstream release's SHA256SUMS.
# - vanillagen (terrain core): built from native/vanillagen (Linux counterpart of native/vanillagen/build.ps1).
# Usage: NATIVE_TARGETS="linux/amd64" build/ci/build-native.sh [version]
# Third-party native bridge and Go generator retain their upstream attribution.
set -euo pipefail

VERSION="${1:-git-$(git rev-parse --short HEAD)}"
TARGETS="${NATIVE_TARGETS:-windows/amd64 linux/amd64 linux/arm64 darwin/amd64 darwin/arm64}"
NETHERNET_VERSION="${NETHERNET_VERSION:-0.1.2}"
NETHERNET_PACKAGE_URL="${NETHERNET_PACKAGE_URL:-https://gitlab.com/api/v4/projects/VanillaPocketMine%2Fnethernet/packages/generic/pm-nethernet}"
export CGO_ENABLED=0
export GOFLAGS=-trimpath
ROOT="$(pwd)"

echo "== pm-nethernet $NETHERNET_VERSION"
cd "$ROOT/native/nethernet"
mkdir -p bin
SUMS="$(mktemp)"
curl -fsSL --retry 3 -o "$SUMS" "$NETHERNET_PACKAGE_URL/$NETHERNET_VERSION/SHA256SUMS"
for t in $TARGETS; do
	os="${t%/*}"; arch="${t#*/}"
	ext=""; [ "$os" = "windows" ] && ext=".exe"
	f="pm-nethernet-$os-$arch$ext"
	echo "Downloading bin/$f"
	curl -fsSL --retry 3 -o "bin/$f" "$NETHERNET_PACKAGE_URL/$NETHERNET_VERSION/$f"
	want="$(awk -v f="$f" '{ n = $2; sub(/^\*/, "", n); if (n == f) print $1 }' "$SUMS")"
	got="$(sha256sum "bin/$f" | cut -d' ' -f1)"
	if [ -z "$want" ] || [ "$want" != "$got" ]; then
		echo "bin/$f: SHA-256 $got does not match the release's SHA256SUMS (${want:-missing})" >&2
		exit 1
	fi
	chmod +x "bin/$f"
done
rm -f "$SUMS"

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
sha256sum native/nethernet/bin/* native/vanillagen/bin/*
