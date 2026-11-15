# Pterodactyl integration

Use the **official [RyzeMP Egg](https://github.com/RyzeCompany/egg-ryzemp)** instead of the inherited VanillaMine egg.

The old `egg-vanillamine.json`, `install.sh` and `make-egg.php` are upstream deployment files and are not compatible with RyzeMP's official PHAR/update manifest. They are deliberately retired from this fork; do not upload them to your panel.

The official Egg preserves worlds, plugins and configuration across reinstalls, uses the configured `server-ip` and `server-port` for one public TCP/UDP allocation, and loads `ryzertc.so` **outside** the PHAR. The existing official releases refer to the previous RyzeMP server base. **Do not point the Egg at a PHAR from this integration branch** until its NetherNet transport, native runtime and retail clients have passed compatibility testing.

A GitHub Actions candidate PHAR is available from [the integration workflow](https://github.com/RyzeCompany/RyzeMP/actions/workflows/ryzemp-integration.yml) after a successful run. It is not an official release.
