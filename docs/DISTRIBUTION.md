# Distributing RyzeMP: Composer and PHAR

RyzeMP ships through two complementary channels. Both distribute **the same server source**; neither automatically bundles a PHP interpreter or bypasses the PocketMine-specific extensions.

> Publication status: preparation only. The three RyzeCompany repositories are public, but version tags and Packagist registration still need to be completed and verified. A public GitHub repository by itself does not make a Composer package installable by name.

## Channel A: Composer project (developers)

The main package is `ryzemcbe/ryzemp` with Composer type `project`.

Public distribution checklist:

1. Confirm the three RyzeCompany repositories remain public, their Composer package names are unique, and their licenses/third-party attribution are suitable for publication. Keep the protocol and data package versions consistent with the RyzeMP core.
2. Keep the root `composer.json` and `composer.lock` consistent. The lockfile is prepared for `ryzemcbe/bedrock-data:v1.0.0-beta.1` and `ryzemcbe/bedrock-protocol:v1.0.0-beta.1`, with exact tagged Git revisions. Confirm `composer validate --strict` and an end-to-end `composer install` against the final release metadata.
3. The **two dependency libraries are tagged** as `v1.0.0-beta.1`. Register them with Packagist first, then verify their tagged package names and revisions before publishing RyzeMP. Run Composer validation and dependency installation against this lockfile.
4. Submit the source URLs to [Packagist.org](https://packagist.org/packages/submit): `RyzeCompany/BedrockData`, `RyzeCompany/BedrockProtocol`, then `RyzeCompany/RyzeMP`. Enable the update webhook so new version tags are detected.
5. Confirm the exact versions are visible in Packagist **before** announcing the commands below.

After the tested `1.0.0-beta.3` RyzeMP release is registered and all required versions are available:

```bash
composer create-project ryzemcbe/ryzemp ryzemp "1.0.0-beta.3" --no-dev --prefer-dist
cd ryzemp
```

Use a compatible 64-bit PHP environment with the PocketMine-specific extensions required by `composer.json`. Standard hosting PHP is usually insufficient. To compile the source into a PHAR using the installed dependencies:

```bash
php -d phar.readonly=0 build/server-phar.php --out=RyzeMP.phar
```

`composer create-project` installs the source project **and dependencies**; it does **not** produce a PHAR by itself. Before Packagist registration, developers can clone the now-public GitHub repository and run `composer install` with its committed VCS repository declarations.

### Private distribution

Private enterprise deployments can instead use a [private Composer repository](https://packagist.com/) and appropriate access controls. Never commit access tokens into Composer files, workflows, or installation examples.

## Channel B: GitHub Releases PHAR (server operators)

The existing GitHub Actions workflow builds `RyzeMP.phar` for `main` and pull requests after syntax checks, PHPUnit, Composer installation, and an automated server startup smoke test. Those development artifacts are not public versioned releases.

A maintainer-created tag beginning with `v`, such as `v1.0.0-beta.3`, triggers the release workflow. It checks that the tag matches `VersionInfo::BASE_VERSION`, then publishes:

- `RyzeMP_v1.0.0-beta.3.phar` — ready-built server archive (without the PHP runtime).
- `SHA256SUMS.txt` — SHA-256 hash of the PHAR.
- `LICENSE` — software license.
- Auto-generated GitHub release notes.

Beta tags are marked as prereleases. The workflow never creates a tag by itself.

After the maintainer has **created the version tag** and the release workflow has passed:

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

## Publication gate and release order

1. Confirm automated CI on `main` for all three repositories.
2. Test **actual Bedrock clients** against a staging server: mixed-version login, classic/Persona skins, respawn, portals/dimensions, chat and inventory. Automated startup tests do not establish live gameplay compatibility.
3. Review licenses, third-party attribution, and distribution rights before publishing package metadata.
4. Publish/register versioned libraries, update RyzeMP to validated version constraints with a consistent lockfile, and test the resulting Composer installation.
5. Tag RyzeMP only after its `BASE_VERSION`, tested lockfile, dependencies, and release notes correspond to the chosen version.
6. Verify both the Composer installation and the downloadable GitHub Release archive, including its SHA-256 hash.

**Do not announce the public Composer installation command until each version is registered on Packagist and a fresh installation succeeds.**
