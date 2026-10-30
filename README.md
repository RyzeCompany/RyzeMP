# RyzeMP

**RyzeMP** is a PHP-based Minecraft: Bedrock Edition server software, maintained by RyzeCompany and based on PocketMine-MP 5.

> Development status: **1.0.0-beta.3**. This version is in development; no stable RyzeMP release has been published yet.

## Features and purpose

- Multiversion Bedrock network support through customized `RyzeCompany/BedrockProtocol` and `RyzeCompany/BedrockData` dependencies.
- PocketMine-MP 5 plugin API compatibility. The PHP namespace remains `pocketmine\` intentionally.
- Multiple worlds, game entities, inventory mechanics, native hopper and dimension-related work.
- PHAR packaging and CI checks for code syntax, unit tests, and startup verification.

Support for a Bedrock protocol in the dependency does **not** guarantee every gameplay feature works on every supported client. Use staging to validate mixed-version joins, skins, respawns, dimension transfers, and inventory behavior.

## Get RyzeMP — two distribution channels

- **Composer (source project for developers):** package `ryzemcbe/ryzemp`. Once the dependencies and RyzeMP have tested release tags registered on Packagist, use `composer create-project ryzemcbe/ryzemp ryzemp "1.0.0-beta.3" --no-dev --prefer-dist`.
- **GitHub Releases (ready-built server PHAR):** a release tag will produce `RyzeMP_v1.0.0-beta.3.phar` with SHA-256 checksum and license. Download and run it with a compatible PocketMine PHP environment.

**Public Composer distribution is not available yet.** RyzeMP and its matching multiversion libraries are now public on GitHub. Their Composer package names are prepared, but publication still requires versioned dependency releases, Packagist registration, a final Composer install test, and live game tests.

Read [DISTRIBUTION.md](docs/DISTRIBUTION.md) for the publication checklist and [BUILDING.md](BUILDING.md) for developer builds. The development artifact is named `RyzeMP.phar`.

The shell and Docker entrypoints prefer `RyzeMP.phar`, with a fallback to `PocketMine-MP.phar` for existing installs.

## Native command API

RyzeMP includes Commando 3.3.0 for plugins, with structured arguments emitted
through the native multiversion command pipeline. See [the command API](docs/COMMAND_API.md).

`/enchant` suggests both vanilla names and custom enchantments registered by plugins.
The list is read from the native registry when command metadata is generated, including
the 14 custom enchantments in HardCoreFactions beta.16. No separate HCF update is needed.

## Compatibility

Read [Dimension configuration and portal safety](docs/DIMENSIONS.md) to configure
Nether/End worlds and safe arrival points for custom End maps.

RyzeMP has its **own software version**, while `Server::getApiVersion()` retains a PocketMine-MP 5 compatibility value. This is deliberate: plugins must not be forced to declare RyzeMP's independent release version as their API requirement.

Read [RyzeMP compatibility and rebranding contract](docs/RYZEMP_COMPATIBILITY.md) before changing namespaces, Composer package names, or world data identifiers.

## Development and releases

- [Source repository](https://github.com/RyzeCompany/RyzeMP)
- [GitHub Actions](https://github.com/RyzeCompany/RyzeMP/actions)
- [Releases](https://github.com/RyzeCompany/RyzeMP/releases)

The source repository is now under the `RyzeCompany` GitHub organization; the public Composer vendor remains `ryzemcbe` to avoid changing dependency names between releases.

## License and credits

RyzeMP is a derivative of [PocketMine-MP](https://github.com/pmmp/PocketMine-MP), developed by the PocketMine Team and contributors. It includes modifications and third-party dependencies with their own licenses and copyright holders.

The covered PocketMine-MP code remains under **LGPL-3.0-or-later**; see [LICENSE](LICENSE) and preserve existing copyright notices. The RyzeMP name does not imply the upstream maintainers endorse this fork.

Minecraft is a trademark of Mojang/Microsoft. RyzeMP is an independent community project and is not affiliated with or endorsed by Mojang or Microsoft.


