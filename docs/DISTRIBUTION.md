# Distributing RyzeMP: Composer and PHAR

RyzeMP ships through two complementary channels. Both distribute **the same server source**; neither automatically bundles a PHP interpreter or bypasses the PocketMine-specific extensions.

> Publication status: **v1.0.0-beta.4 is publicly available** on [Packagist](https://packagist.org/packages/ryzemcbe/ryzemp) and [GitHub Releases](https://github.com/RyzeCompany/RyzeMP/releases/tag/v1.0.0-beta.4). The tagged `v1.0.0-beta.2` BedrockProtocol and BedrockData packages are also public. A clean `composer create-project` installation and GitHub PHAR build have both passed. This is a **beta**, not a stable or fully gameplay-certified release.

## Channel A: Composer project (developers)

The main package is `ryzemcbe/ryzemp` with Composer type `project`.

Plugin developers can require the same source using its numeric Composer API
tag, `ryzemcbe/ryzemp:^5.44.2-p1`. This is separate from the server/PHAR version.
See [the Composer API guide](COMPOSER_API.md). Numeric API tags do not create
GitHub Releases or replace egg manifests.

Release maintenance checklist for future versions:

1. Confirm the three RyzeCompany repositories remain public, their Composer package names are unique, and their licenses/third-party attribution are suitable for publication. Keep the protocol and data package versions consistent with the RyzeMP core.
2. Keep the root `composer.json` and `composer.lock` consistent. The lockfile is prepared for `ryzemcbe/bedrock-data:v1.0.0-beta.2` and `ryzemcbe/bedrock-protocol:v1.0.0-beta.2`, with exact tagged Git revisions. Confirm `composer validate --strict` and an end-to-end `composer install` against the final release metadata.
3. Verify that dependency tags are published and indexed in Packagist before changing constraints. Current verified tags: `v1.0.0-beta.2` for both Bedrock libraries.
4. For subsequent releases, make sure [Packagist.org](https://packagist.org/) has indexed the relevant tags of `RyzeCompany/BedrockData`, `RyzeCompany/BedrockProtocol`, and `RyzeCompany/RyzeMP`; keep update webhooks configured.
5. Re-run the clean public installation workflow and confirm the corresponding GitHub Release PHAR plus checksum before announcing new version commands.

The verified public `1.0.0-beta.4` installation command:

```bash
composer create-project ryzemcbe/ryzemp ryzemp "1.0.0-beta.4" --no-dev --prefer-dist
cd ryzemp
```

Use a compatible 64-bit PHP environment with the PocketMine-specific extensions required by `composer.json`. Standard hosting PHP is usually insufficient. To compile the source into a PHAR using the installed dependencies:

```bash
php -d phar.readonly=0 build/server-phar.php --out=RyzeMP.phar
```

`composer create-project` installs the source project **and dependencies**; it does **not** produce a PHAR by itself. For source development, developers may alternatively clone the public GitHub repository and run `composer install` directly from Packagist with the committed Composer lockfile.

### Private distribution

Private enterprise deployments can instead use a [private Composer repository](https://packagist.com/) and appropriate access controls. Never commit access tokens into Composer files, workflows, or installation examples.

## Channel B: GitHub Releases PHAR (server operators)

GitHub Actions now installs the versioned public Composer dependencies without private GitHub credentials and builds `RyzeMP.phar` for `main` and pull requests after syntax checks, PHPUnit, Composer installation, and an automated server startup smoke test. These development artifacts are separate from the public `v1.0.0-beta.4` release.

The published `v1.0.0-beta.4` tag triggered the release workflow successfully. Future maintainer-created tags beginning with `v` also trigger that workflow. It checks that the tag matches `VersionInfo::BASE_VERSION`, then publishes:

- `RyzeMP_v1.0.0-beta.4.phar` — ready-built server archive (without the PHP runtime).
- `SHA256SUMS.txt` — SHA-256 hash of the PHAR.
- `LICENSE` — software license.
- `ryzemp-update.json` — exact PHAR/native-runtime pair for the egg.
- Native transport, world-spawn and public command-API logs.
- Versioned release notes from `docs/releases/`.

After main passes the complete regression and native/PHAR pipeline, the workflow creates the new beta tag and publishes a draft release with its verified PHAR, logs, checksum and update manifest. It publishes the completed prerelease last. Existing published versions are never overwritten. Maintainer-created version tags also use the same pipeline.

To install the released PHAR after the tag build has passed:

1. Download the PHAR and `SHA256SUMS.txt` from [GitHub Releases](https://github.com/RyzeCompany/RyzeMP/releases).
2. Verify it with `sha256sum -c SHA256SUMS.txt` in the directory containing both files.
3. Rename it to `RyzeMP.phar` if desired, and start with a compatible PHP binary:

```bash
php RyzeMP.phar --no-wizard
```

If using Pterodactyl with PocketMine's bundled PHP binary:

```bash
TMPDIR=/home/container/tmp ./bin/php7/bin/php ./RyzeMP.phar --no-wizard
```

The `php7` directory name is not proof of the runtime's PHP version. Use `./bin/php7/bin/php -v` and confirm the required extensions with `./bin/php7/bin/php -m`. `TMPDIR` should point to an existing writable directory if your host requires it.

## Packagist update automation

Enable the [official Packagist GitHub service hook](https://packagist.org/about#how-to-update-packages) for each of the three repositories. Once connected, each new version tag is indexed automatically; an existing published Git tag must not be rewritten. The GitHub/Packagist account owner must authorize this connection or configure the webhook using their Packagist token (do not commit tokens to this repository).

RyzeMP resolves `ryzemcbe/bedrock-protocol` and `ryzemcbe/bedrock-data` from Packagist by their published versions. Update the Composer constraints and regenerate `composer.lock` with Composer for each future Bedrock compatibility upgrade, then verify CI and the PHAR before tagging a new RyzeMP release.

## Publication gate and release order

1. Confirm automated CI on `main` for all three repositories.
2. Test **actual Bedrock clients** against a staging server: mixed-version login, classic/Persona skins, respawn, portals/dimensions, chat and inventory. Automated startup tests do not establish live gameplay compatibility.
3. Review licenses, third-party attribution, and distribution rights before publishing package metadata.
4. Publish/register versioned libraries, update RyzeMP to validated version constraints with a consistent lockfile, and test the resulting Composer installation.
5. Tag RyzeMP only after its `BASE_VERSION`, tested lockfile, dependencies, and release notes correspond to the chosen version.
6. Verify both the Composer installation and the downloadable GitHub Release archive, including its SHA-256 hash.

**Current beta.4 publication was verified** with a fresh Packagist `composer create-project`, and the `v1.0.0-beta.4` tagged PHAR build was successful. For future releases, do not announce new installation commands until their dependency versions and downloadable artifacts have been validated.

## Public and private integration tests

The ordinary release pipeline uses public Composer packages and tests the public plugin/command API against its actual PHAR. It does not require access to another private repository. `Optional private HCF command API integration` remains available through workflow_dispatch with a valid SUBMODULE_TOKEN that can read the pinned HCF repository. It fails explicitly before tests if credentials are unavailable. HCF source is unchanged, and a public release passing does not claim private HCF compatibility was tested.
