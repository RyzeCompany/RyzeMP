<p align="center">
	<img src=".github/readme/pocketmine-rgb.gif" alt="The PocketMine-MP logo" loading="eager" /><br>
	<b>A highly customisable, open source server software for Minecraft: Bedrock Edition written in PHP — now with vanilla gameplay</b>
</p>

<p align="center">
	<a href="https://gitlab.com/VanillaPocketMine/VanillaMine/-/pipelines"><img src="https://gitlab.com/VanillaPocketMine/VanillaMine/badges/vanilla-content/pipeline.svg" alt="pipeline status" /></a>
	<a href="https://gitlab.com/VanillaPocketMine/VanillaMine/-/releases"><img src="https://gitlab.com/VanillaPocketMine/VanillaMine/-/badges/release.svg" alt="latest release" /></a>
	<img src="https://img.shields.io/badge/Bedrock-1.26.52-62B47A" alt="Minecraft: Bedrock Edition 1.26.52" />
	<img src="https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4?logo=php&logoColor=white" alt="PHP 8.2 | 8.3 | 8.4" />
</p>

> [!NOTE]
> **This is a fork of [PocketMine-MP](https://github.com/pmmp/PocketMine-MP).**
> The original project was created and maintained for over 10 years by the [PMMP team](https://github.com/pmmp).
> In July 2026 the PMMP team [ended support](https://github.com/pmmp/PocketMine-MP#end-of-support-announcement)
> and archived its repositories; their last release was 5.44.3 for Bedrock 1.26.30.
>
> **VanillaPocketMine** continues the project:
> updates for new Minecraft versions, our own builds of the libraries and PHP binaries, and the vanilla game
> features PocketMine-MP never had. We are not affiliated with the PMMP team; all credit for the original work goes
> to them and to every contributor of the original project.

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
- [NetherNet](native/nethernet/README.md) (WebRTC) transport, needed by Bedrock clients 1.26.50+, alongside RakNet
- Plugin fault isolation: a plugin that throws no longer takes the whole server down
- Auto-updater served from `updates.vanillamine.com`

Vanilla behaviour is still a work in progress: some mechanics are simplified or missing. If you find something that
behaves differently from Bedrock Dedicated Server, please [open an issue](https://gitlab.com/VanillaPocketMine/VanillaMine/-/issues).

## Getting Started
- [Downloads](https://gitlab.com/VanillaPocketMine/VanillaMine/-/releases) - `PocketMine-MP.phar` and start scripts
- [PHP binaries](https://gitlab.com/VanillaPocketMine/PHP-Binaries/-/packages) - PocketMine-MP needs its own PHP build with the required extensions
- [Building and running from source](BUILDING.md)
- [Original documentation](https://doc.pmmp.io/) - installation and configuration are still the same as upstream
- [Plugin repository (Poggit)](https://poggit.pmmp.io/plugins) - PocketMine-MP 5 plugins

Coming from PocketMine-MP 5.44.3? Replace `PocketMine-MP.phar` with ours; worlds, plugins and configuration are kept.

## Developing Plugins
The plugin API is the PocketMine-MP 5 API, so the upstream resources still apply:

 * [Developer documentation](https://devdoc.pmmp.io) - General documentation for PocketMine-MP plugin developers
 * [API documentation](https://apidoc.pmmp.io) - Doxygen API documentation of the last upstream release
 * [DevTools](https://github.com/pmmp/DevTools/) - Development tools plugin for creating plugins
 * [ExamplePlugin](https://github.com/pmmp/ExamplePlugin/) - Example plugin demonstrating some basic API features

New APIs added by this fork are additive: existing interfaces are never changed, new functionality gets new classes
or sub-interfaces.

## Contributing
Contributions are welcome! Report bugs and send merge requests on [GitLab](https://gitlab.com/VanillaPocketMine/VanillaMine).
 * [Building and running PocketMine-MP from source](BUILDING.md)
 * [Contributing Guidelines](CONTRIBUTING.md)

Related repositories (our forks): [BedrockProtocol](https://gitlab.com/VanillaPocketMine/BedrockProtocol),
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
