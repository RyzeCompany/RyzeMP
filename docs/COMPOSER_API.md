# RyzeMP API for plugin developers

The package is [`ryzemcbe/ryzemp`](https://packagist.org/packages/ryzemcbe/ryzemp).
Composer API version **5.44.2-p1** corresponds to the public plugin API of RyzeMP
**1.0.0-beta.4**, including Bedrock 1.26.60 / protocol 2225. The `pocketmine\`
namespace and plugin API remain compatible with existing PocketMine-MP 5 plugins.

## Add the API to a plugin project

Run these commands in an existing Composer project:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require --dev ryzemcbe/ryzemp:^5.44.2-p1
```

The beta setting allows the tagged BedrockProtocol and BedrockData dependencies.
Stable dependency releases remain preferred. Keep Composer's security policy enabled.

Use the API as a development dependency for IDE completion, static analysis and
tests. Distribute your plugin source or PHAR through its normal build process;
do not bundle another copy of the RyzeMP server into the plugin.

Running API code requires the compatible 64-bit PocketMine PHP runtime and native
extensions declared by RyzeMP. For IDE/static-analysis installation on an ordinary
PHP workstation, `--ignore-platform-req='ext-*'` may be added to the final command.
That option does not install the extensions required to run a server or API tests.

## InvMenu

Install the RyzeCompany fork as a plugin dependency:

```bash
composer require ryzecompany/invmenu:^4.7.5
```

InvMenu requires `ryzemcbe/ryzemp:^5.44.2-p1` automatically. Its PHP namespace remains
`muqsit\invmenu`; the fork replaces the matching upstream InvMenu version, so
Composer does not install two copies of its classes. Include InvMenu with your
normal virion/plugin bundling process and exclude server dependencies from the PHAR.

## Versioning and publication

The Git repository has two explicit tag series:

| Tag | Purpose |
| --- | --- |
| `v1.0.0-beta.4` | Server release, PHAR and egg update manifest |
| `5.44.2-p1` | Composer artifact for plugin API 5.44.2 |

The Composer API tag has no `v` prefix and does not create a GitHub PHAR release.
It keeps Composer's comparison with PocketMine's API/advisory version ranges
consistent while preserving RyzeMP's independent server version.

After the complete main-branch PHAR pipeline succeeds, the Composer API workflow
installs RyzeMP as a dependency in a clean project, exercises its actual protocol
and inventory APIs, and publishes the versioned `COMPOSER_API_VERSION` tag. Existing
tags are preserved. API changes intended for Composer consumers need a new Composer artifact
version; changing only the server's `BASE_VERSION` does not move a published API tag.

Packagist's GitHub hook indexes the tag. Verify the indexed version and a clean
consumer installation before announcing a new developer API release.

The `p1` artifact retains plugin API 5.44.2 and makes resource-pack completion
idempotent while asynchronous player creation is pending. Repeated or reentrant
completion packets cannot start multiple player factories.
