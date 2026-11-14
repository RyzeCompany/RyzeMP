# Contributing to VanillaMine

Thanks for helping! VanillaMine is developed in the open on GitLab under the LGPL-3.0.

## Where things go

- **Bugs and crashes:** open an issue with the *Bug* or *Crash* template.
- **Ideas:** open an issue with the *Feature proposal* template before writing a big change, so it can be discussed first.
- **Plugins that break on VanillaMine:** use the *Plugin compatibility* template.
- **Questions and help:** ask in the [Discord](https://vanillamine.com) `#help` forum or read the [docs](https://docs.vanillamine.com/).
- **Security problems:** never in a public issue. See [SECURITY.md](SECURITY.md).

The issue tracker is for bugs and proposals only. Questions get the `Support request` label and are closed automatically with a pointer to Discord.

The templates add labels with `/label` quick actions. Only members with the Reporter role or higher can set labels, so if you are not a member they are ignored and a maintainer labels the issue during triage.

## Merge requests

1. Fork the project and branch off `vanilla-content` (the development branch).
2. Keep each merge request focused on one change, and fill in the template.
3. The plugin API only grows: add classes, methods, events or optional parameters, but don't remove or change existing ones, so API 5 plugins keep working.
4. For vanilla gameplay, compare with Bedrock Dedicated Server and describe how you checked it.

Small fixes can go straight to a merge request. Larger features should start as an issue.

## Review

Every change is seen by two people: merge requests from outside the team need the review of a maintainer and an approval from a second one; merge requests from maintainers need one review besides the author. `.gitlab/CODEOWNERS` lists who is responsible for the code; GitLab shows them on each merge request, and the author assigns one of them as reviewer (on the Free tier GitLab does not add them automatically).

When a maintainer asks for changes the merge request gets `Status: Waiting on Author`. Without activity it is marked stale after 7 days and closed after 28 more days (`Resolution: Abandoned`). Assigning someone to the merge request prevents that.

## Labels

Labels follow the PocketMine-MP scheme:

- `Category: …` which part of VanillaMine (API, Core, Gameplay, Network, Protocol, ...)
- `Type: …` kind of change (Bug, Feature, Enhancement, Fix, Bedrock Update, ...)
- `Status: …` where it is (Unconfirmed, Reproduced, Debugged, Waiting on Author, Blocked, ...)
- `Resolution: …` why it was closed (Fixed, Duplicate, Won't Fix, Works As Intended, ...)
- `Priority: …`, `BC break`, `Easy task`, `Help wanted`, `Opinions Wanted`
- `Support request` closes the issue automatically with a pointer to Discord; `Stale` marks merge requests without activity

## Building and checks

See [BUILDING.md](BUILDING.md) for the full setup. Dependencies include our forks of BedrockProtocol, BedrockData, RakLib and the upgrade schemas, so install them the way the CI does:

```bash
bash build/ci/install-deps.sh
```

Before opening a merge request, run the same checks the CI has:

```bash
./vendor/bin/phpstan analyze --memory-limit=2G
./vendor/bin/phpunit --bootstrap vendor/autoload.php tests/phpunit
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

To save shared runner minutes, the `phpstan`, `phpunit`, `codegen` and `codestyle` CI jobs are **manual**: a maintainer starts them with the play button on the pipeline (or by setting the CI/CD variable `RUN_CHECKS=1`) before merging. `composer make-server` only builds the phar; it does not run any check.

## Code style

Follow the existing code style (`.php-cs-fixer.php` and `phpstan.neon.dist`). Write commit messages in English, in the imperative mood ("Fix…", "Add…").

## Credits

VanillaMine continues PocketMine-MP 5. Credit for PocketMine-MP goes to the PMMP team and its contributors. VanillaMine is not affiliated with the PMMP team, Mojang or Microsoft.
