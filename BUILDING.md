# Building RyzeMP

## Requirements

- A shell, Git and Composer
- A compatible 64-bit PHP runtime with the extensions required in [composer.json](composer.json)
- Repository access to the customized `Mykoss/BedrockProtocol` and `Mykoss/BedrockData` dependencies

For the current CI build, GitHub Actions uses PHP 8.4 plus PocketMine's required extensions for validation, and PHP 8.3 for the PHAR builder.

## Install dependencies

```bash
git clone https://github.com/Mykoss/PocketMine-MP.git RyzeMP
cd RyzeMP
composer install --prefer-dist
```

For a release-style build:

```bash
composer install --no-dev --classmap-authoritative
php -d phar.readonly=0 build/server-phar.php --out=RyzeMP.phar
```

`composer make-server` also produces `RyzeMP.phar` by default. Your PHP runtime must meet the project's extension requirements. Some CI packaging jobs use `--ignore-platform-reqs` only because they build the archive using a packaging runtime; do not assume a local server will run without the required extensions.

## Start

```bash
./start.sh
```

Or:

```bash
php RyzeMP.phar --no-wizard
```

For source execution, run `src/PocketMine.php` with the appropriate PHP binary.

Do not change the `pocketmine\` PHP namespace, `PMMPDataVersion` tag, Composer lockfile or plugin API version solely to change the software's public branding.
