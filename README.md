# RyzeMP

**RyzeMP** is a PHP-based Minecraft: Bedrock Edition server software, maintained by RyzeMCBE and based on PocketMine-MP 5.

> Development status: **1.0.0-beta.1**. This is a development branch; no stable RyzeMP release has been published yet.

## Features and purpose

- Multiversion Bedrock network support through customized `RyzeMCBE/BedrockProtocol` and `RyzeMCBE/BedrockData` dependencies.
- PocketMine-MP 5 plugin API compatibility. The PHP namespace remains `pocketmine\` intentionally.
- Multiple worlds, game entities, inventory mechanics, native hopper and dimension-related work.
- PHAR packaging and CI checks for code syntax, unit tests, and startup verification.

Support for a Bedrock protocol in the dependency does **not** guarantee every gameplay feature works on every supported client. Use staging to validate mixed-version joins, skins, respawns, dimension transfers, and inventory behavior.

## Building

Read [BUILDING.md](BUILDING.md) for source and PHAR instructions. The standard artifact name is `RyzeMP.phar`.

The shell and Docker entrypoints prefer `RyzeMP.phar`, with a fallback to `PocketMine-MP.phar` for existing installs.

## Compatibility

RyzeMP has its **own software version**, while `Server::getApiVersion()` retains a PocketMine-MP 5 compatibility value. This is deliberate: plugins must not be forced to declare RyzeMP's independent release version as their API requirement.

Read [RyzeMP compatibility and rebranding contract](docs/RYZEMP_COMPATIBILITY.md) before changing namespaces, Composer package names, or world data identifiers.

## Development and releases

- [Source repository](https://github.com/RyzeMCBE/RyzeMP)
- [GitHub Actions](https://github.com/RyzeMCBE/RyzeMP/actions)
- [Releases](https://github.com/RyzeMCBE/RyzeMP/releases)

The source repository currently retains its historical GitHub path. Branding the software does not itself rename the repository.

## License and credits

RyzeMP is a derivative of [PocketMine-MP](https://github.com/pmmp/PocketMine-MP), developed by the PocketMine Team and contributors. It includes modifications and third-party dependencies with their own licenses and copyright holders.

The covered PocketMine-MP code remains under **LGPL-3.0-or-later**; see [LICENSE](LICENSE) and preserve existing copyright notices. The RyzeMP name does not imply the upstream maintainers endorse this fork.

Minecraft is a trademark of Mojang/Microsoft. RyzeMP is an independent community project and is not affiliated with or endorsed by Mojang or Microsoft.
