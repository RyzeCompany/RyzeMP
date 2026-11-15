# RyzeMP — vanilla gameplay with multiversion Bedrock networking

**Status: development integration (`integration/vanillamine-base`).** This branch is not a production release. It combines the VanillaMine game engine with RyzeMP's Bedrock multi-protocol packet and item conversion. Until real-client tests pass, use the [integration CI artifacts](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-integration.yml) only on a disposable test server; do not replace a production PHAR.

**Compatibility:** PocketMine-MP 5 plugin API and world data are retained; the core API version is independent from RyzeMP release tags. Target clients: Bedrock 1.26.40, 1.26.45, 1.26.52 and 1.26.60, including mixed sessions. Client compatibility has **not yet been validated end-to-end**.

**Native world generation:** Go is developed and released independently in [RyzeVanillaGen](https://github.com/RyzeCompany/RyzeVanillaGen), not built from this RyzeMP tree. The Egg and CI pin the official [v0.1.0 binary](https://github.com/RyzeCompany/RyzeVanillaGen/releases/tag/v0.1.0) with SHA-256 `3cddcb4be9bd17eaa3225be5d167fb63328566c252a8c4fe7b1e3b9e7d716dc7`, protocol 7 / algorithm 4; RyzeMP retains the PHP fallback. Go lives outside `RyzeMP.phar`.

**Attribution:** RyzeMP incorporates [VanillaMine](https://gitlab.com/VanillaMine/VanillaMine) and descends from [PocketMine-MP](https://github.com/pmmp/PocketMine-MP). Their original copyrights, attributions and LGPL license are retained. RyzeCompany does not claim authorship of the upstream code.

**Deployment:** The [official RyzeMP Pterodactyl egg](https://github.com/RyzeCompany/egg-ryzemp) installs the official RyzeRTC API 2 extension **outside** the PHAR. RyzeRTC is the only NetherNet transport; pm-nethernet is removed. With NetherNet enabled, a missing native module stops startup instead of silently falling back. RakLib and RyzeRTC share the same public server-port on TCP/UDP (typically 19132). A trusted PEM certificate enables HTTPS on that TCP port; ACME issuance still requires external certificate provisioning. RakNet-only smoke testing can explicitly disable NetherNet. Retail Bedrock clients and existing-world compatibility still require verification before production release.

## What is this?
PocketMine-MP is a highly customisable server software for Minecraft: Bedrock Edition, built from scratch in PHP.

- 🧩 **Powerful plugin API** - extend and customise gameplay as you see fit. Plugins written for PocketMine-MP 5 keep working: the API stays compatible with 5.44.3 (additions only)
- 🌲 **Vanilla gameplay** - vanilla worlds, mobs, redstone and more, built into the core (see below)
- 🌍 **Multi-world support** - offer a more varied game experience to players without transferring them to other server nodes
- 🏎️ **Performance** - native helpers for the heavy parts (terrain generation, networking), 100+ players on one server depending on hardware and plugins
- ⤴️ **Kept up to date** - new Bedrock versions supported by us, independently of the original project

## 🌲 Vanilla features
Upstream PocketMine-MP was famously **not** a vanilla server: no vanilla world generation, no redstone, no mob AI.
This fork changes that. Implemented so far:

**Worlds**
- Vanilla terrain generator (`level-type=vanilla`): Overworld, Nether and End with vanilla biomes, caves and carvers,
  ores and decoration; terrain shape computed by a native core (`vanillagen`) with an identical PHP fallback
- Vanilla structures: villages and other jigsaw structures, desert pyramids, jungle temples, ruined portals,
  mineshafts (with chest minecarts), strongholds, ocean monuments, woodland mansions, Nether fortresses and fossils,
  End cities, with their loot tables
- Weather per world with rain, thunderstorms and lightning
- The End: dragon fight, exit portal and End gateways to the outer islands

**Mobs**
- Mob AI and vanilla mob spawning (Bedrock spawn rules, light levels, herds, patrols)
- Passive, neutral and hostile mobs, including the warden, creaking, slimes, piglins and many more
- Bosses: Ender Dragon and Wither with boss bars
- Villagers with professions, job sites, schedules, breeding, gossip and trading; wandering traders
- Illager patrols and raids, Hero of the Village
- Riding: horses (with inventory), mounts, happy ghast; minecarts (rideable, chest, hopper, TNT, command block) with rail physics

**Redstone & blocks**
- Redstone power engine and wire, repeaters, comparators, torches, buttons, pressure plates
- Pistons and sticky pistons, observers, sculk sensors and vibrations, hoppers and dispensers
- Beacon, maps, bundles, trial spawners and vaults, the vanilla creative inventory and more

**Gameplay**
- Vanilla combat details (mace smash, spears) and many more enchantments (Channeling, Looting, Soul Speed, Wind Burst, Density, Breach, Lunge…)
- Commands with target selectors (`@a`, `@p`, `@e[...]`), `/tag`, `/locate biome|structure`, `/weather` and command overloads

**Server**
- [NetherNet over RyzeRTC](docs/INTEGRATION_RELEASE_GATES.md) (WebRTC), needed by Bedrock clients 1.26.50+, alongside RakNet
- Plugin fault isolation: a plugin that throws no longer takes the whole server down
- Production RyzeMP builds are published only through official RyzeCompany releases (integration builds never auto-publish)

Vanilla behaviour is still a work in progress: some mechanics are simplified or missing. If you find something that
behaves differently from Bedrock Dedicated Server, please [open an issue](https://github.com/RyzeCompany/RyzeMP/issues).

## Getting Started
- [Integration test PHAR](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-integration.yml) - artifacts are published only when CI passes; production release is not yet authorized
- [Official RyzeMP Egg](https://github.com/RyzeCompany/egg-ryzemp) - installs PHP, native RyzeRTC and version-locked RyzeVanillaGen separately from the PHAR
- [Building and running from source](BUILDING.md)
- [Original documentation](https://doc.pmmp.io/) - installation and configuration are still the same as upstream
- [Plugin repository (Poggit)](https://poggit.pmmp.io/plugins) - PocketMine-MP 5 plugins

Keep a backup of worlds, plugins and configuration when testing a new major server base; importing this branch directly into production is not supported.

## Developing Plugins
The plugin API is the PocketMine-MP 5 API, so the upstream resources still apply:

 * [Developer documentation](https://devdoc.pmmp.io) - General documentation for PocketMine-MP plugin developers
 * [API documentation](https://apidoc.pmmp.io) - Doxygen API documentation of the last upstream release
 * [DevTools](https://github.com/pmmp/DevTools/) - Development tools plugin for creating plugins
 * [ExamplePlugin](https://github.com/pmmp/ExamplePlugin/) - Example plugin demonstrating some basic API features

New APIs added by this fork are additive: existing interfaces are never changed, new functionality gets new classes
or sub-interfaces.

## Contributing
Contributions are welcome! Report RyzeMP issues and open pull requests on [GitHub](https://github.com/RyzeCompany/RyzeMP).
 * [Building and running PocketMine-MP from source](BUILDING.md)
 * [Contributing Guidelines](CONTRIBUTING.md)

Original VanillaMine dependency repositories (for provenance; RyzeMP uses its committed Composer lockfile): [BedrockProtocol](https://gitlab.com/VanillaPocketMine/BedrockProtocol),
[BedrockData](https://gitlab.com/VanillaPocketMine/BedrockData), [RakLib](https://gitlab.com/VanillaPocketMine/RakLib),
[BedrockBlockUpgradeSchema](https://gitlab.com/VanillaPocketMine/BedrockBlockUpgradeSchema),
[BedrockItemUpgradeSchema](https://gitlab.com/VanillaPocketMine/BedrockItemUpgradeSchema),
[PHP-Binaries](https://gitlab.com/VanillaPocketMine/PHP-Binaries).

## Credits
PocketMine-MP was created by the [PMMP team](https://github.com/pmmp) and its contributors. Thank you to everyone
who built and maintained it for more than a decade, and to the Lifeboat Network for funding its development in its
final years. The original repository is archived at [github.com/pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP).

## Licensing information
This project is licensed under LGPL-3.0, like the original. Please see the [LICENSE](/LICENSE) file for details.

VanillaPocketMine and PocketMine-MP are not affiliated with Mojang. All brands and trademarks belong to their
respective owners. This software is not a Mojang-approved software, nor is it associated with Mojang.
