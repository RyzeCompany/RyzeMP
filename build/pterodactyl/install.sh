#!/bin/bash
# Pterodactyl install script of the VanillaMine egg (egg-vanillamine.json is generated from this file by make-egg.php).
# Runs in the installer container with the server's files mounted at /mnt/server.
set -euo pipefail
#the egg's TMPDIR variable (/home/container/.tmp) is meant for the server; it does not exist in this installer container
export TMPDIR=/tmp

API="https://gitlab.com/api/v4/projects"
VERSION="${VANILLAMINE_VERSION:-latest}"
MARCH="${PHP_MARCH:-x86-64-v3}"
#updates.vanillamine.com/download/<version>/<file> redirects to the release files on GitLab
PHAR_URL="https://updates.vanillamine.com/download/$VERSION/PocketMine-MP.phar"
PHP_URL="$API/VanillaPocketMine%2FPHP-Binaries/packages/generic/PHP-Binaries/latest/PHP-8.4-Linux-$MARCH-PM5.tar.gz"

if [ "$(uname -m)" != "x86_64" ]; then
	echo "VanillaMine PHP binaries are only built for x86_64 Linux (this node is $(uname -m))"
	exit 1
fi

if ! command -v curl >/dev/null; then
	apt-get update -qq && apt-get install -y -qq curl ca-certificates tar gzip >/dev/null
fi

mkdir -p /mnt/server
cd /mnt/server
#temporary directory of the server (the egg's hidden TMPDIR variable points here): /tmp is a small tmpfs
mkdir -p .tmp

echo "Downloading VanillaMine ($VERSION)"
curl -fsSL --retry 3 -o PocketMine-MP.phar.new "$PHAR_URL"
mv PocketMine-MP.phar.new PocketMine-MP.phar

echo "Downloading PHP 8.4 ($MARCH)"
rm -rf bin.new && mkdir bin.new
curl -fsSL --retry 3 "$PHP_URL" | tar -xz -C bin.new
rm -rf bin && mv bin.new/bin bin && rm -rf bin.new

#the archive's php.ini points at the build machine: use the path the server sees at runtime (/home/container)
EXTENSION_DIR=$(find "$PWD/bin" -type d -name "*debug-zts*" | head -n 1)
EXTENSION_DIR="/home/container${EXTENSION_DIR#/mnt/server}"
INI=bin/php7/bin/php.ini
if grep -q '^extension_dir' "$INI"; then
	sed -i "s{^extension_dir=.*{extension_dir=\"$EXTENSION_DIR\"{" "$INI"
else
	echo "extension_dir=\"$EXTENSION_DIR\"" >> "$INI"
fi

#PHP-Binaries ships opcache.jit=off; the JIT (tracing) cuts the server's PHP CPU by ~5-30% depending on the load.
#Only opcache.jit changes: the buffer size stays as shipped (128M) and no other jit_* option is set.
JIT="${PHP_JIT:-tracing}"
case "$JIT" in
	tracing|function|off) ;;
	*) echo "Unknown PHP_JIT value '$JIT', using tracing"; JIT=tracing ;;
esac
if grep -q '^opcache.jit=' "$INI"; then
	sed -i "s/^opcache.jit=.*/opcache.jit=$JIT/" "$INI"
else
	echo "opcache.jit=$JIT" >> "$INI"
fi
echo "PHP JIT: $JIT"

if [ ! -f server.properties ]; then
	#the panel rewrites server-ip and server-port on every start (egg config files)
	cat > server.properties <<EOF
server-ip=0.0.0.0
server-port=${SERVER_PORT:-19132}
enable-ipv6=off
EOF
fi

#-n: extension_dir only exists at runtime
./bin/php7/bin/php -n -v
echo "Install complete"
