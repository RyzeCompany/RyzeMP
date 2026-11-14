### What this changes

### Why
<!-- Link the issue: Closes #123 -->

### How it was tested
- [ ] Tested on a server with Bedrock client version:
- [ ] Ran PHPStan locally: `./vendor/bin/phpstan analyze --memory-limit=2G`
- [ ] Ran the tests locally: `./vendor/bin/phpunit --bootstrap vendor/autoload.php tests/phpunit`
- [ ] Code style is clean: `./vendor/bin/php-cs-fixer fix --dry-run --diff`

<!-- The phpstan, phpunit, codegen and codestyle CI jobs are manual to save runner minutes:
     a maintainer runs them with the play button (or RUN_CHECKS=1) before merging. -->

### Checklist
- [ ] Plugin API: only additions (API 5 plugins keep working)
- [ ] Vanilla behaviour checked against Bedrock Dedicated Server when relevant
- [ ] Changelog entry added if users will notice the change
