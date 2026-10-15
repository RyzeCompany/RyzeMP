# RyzeMP: identity and compatibility contract

RyzeMP is a Minecraft Bedrock server software maintained by RyzeMCBE, built on the PocketMine-MP 5 codebase.

## Stable compatibility boundaries

- **Software identity:** `pocketmine\VersionInfo::NAME` is `RyzeMP`.
- **Software release:** `VersionInfo::BASE_VERSION` is a RyzeMP-owned version, starting at `1.0.0-beta.1`.
- **Plugin API:** `Server::getApiVersion()` returns `VersionInfo::PLUGIN_API_VERSION`, currently `5.44.2`. Never derive it from the RyzeMP release version.
- **PHP namespaces:** retain `pocketmine\`, `pocketmine\network\mcpe\protocol\`, and all existing plugin-facing classes and interfaces.
- **Composer package identity:** use `ryzemcbe/ryzemp` for the root package, retain its `pocketmine/pocketmine-mp` replacement, and keep Composer's installed-reference lookup aligned with the root package.
- **Protocol:** use the checked-in Composer lockfile references to `RyzeMCBE/BedrockProtocol` and `RyzeMCBE/BedrockData`; retain pinned dependency commits and legacy Composer dependency names until a separately tested package-name migration is complete.
- **World data:** preserve `PMMPDataVersion` and existing world storage identifiers to avoid corrupting or orphaning worlds.
- **Distribution:** new releases generate `RyzeMP.phar`; the shell and Docker launchers prefer it while keeping the older PHAR name as a migration fallback.
- **Provenance:** preserve the source license, notices, original copyright holders, and the LGPL-3.0-or-later conditions. RyzeMP is a derivative, not a clean-room rewrite.

## Pre-merge validation

1. Validate Composer metadata and locked dependency installation.
2. PHP syntax checks, PHPUnit and generated-code consistency checks.
3. Build `RyzeMP.phar` from the branch and run its `--version` smoke test.
4. Start the resulting PHAR and check for critical errors.
5. Check compatibility using representative PM5 plugins and mixed-version clients (including 1.21.x and 1.26.x); these live gameplay tests remain manual.
6. Keep changes in feature branches until validation is complete; no direct edits to `main`.

## Known areas requiring focused gameplay tests

- Persona, classic and HD skin visibility across mixed protocol versions.
- Death / respawn chunk loading and inventory resynchronization.
- Nether and End dimension transitions.
- Chunk palette translation and legacy protocol block behavior.
- Plugin loading and permissions using PM5 API 5.0.0+.

Any functional claim beyond automated checks must be verified on a live Bedrock client.
