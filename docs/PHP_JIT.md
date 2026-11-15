# PHP OPcache/JIT in RyzeMP

RyzeMP supports PHP's OPcache/JIT when the PHP CLI executable provides the Zend OPcache extension. JIT is **optional**, not an intrinsic feature of the PHAR, and is not enabled automatically by this repository.

## Check the running process

- Use `/version runtime` (or `/ver runtime`) to see PHP, ZTS, OS, and the live JIT state.
- `/version` includes a brief runtime/JIT summary.
- The actual PHP interpreter launched by the Egg/container must be the same one being checked. A separate host PHP installation can have different extensions and INI settings.
- `php -m` should show `Zend OPcache` under Zend modules; `php --ri "Zend OPcache"` can inspect its settings.

## Opt-in test with PHP CLI

Use the same PHP binary and extensions as the working RyzeMP server:

```bash
php -d opcache.enable=1 \
    -d opcache.enable_cli=1 \
    -d opcache.jit=tracing \
    -d opcache.jit_buffer_size=64M \
    RyzeMP.phar
```

Alternatively configure those directives in the active `php.ini` or in your container's launch command. PHP 8.4 disables JIT by default even though its default JIT buffer is nonzero. A positive buffer alone does not activate JIT.

Do not embed native extension files or PHP/JIT configuration in `RyzeMP.phar`. If the active PHP binary lacks OPcache/JIT, installing an appropriate PHP build is a separate host/container task.

## Why measure before enabling it permanently?

- JIT can accelerate some repeated, CPU-bound PHP operations (numeric loops, certain calculations).
- Work already performed by native extensions, networking, LevelDB, asynchronous workers or external Go worldgen is not made faster merely by enabling PHP JIT.
- JIT consumes additional shared memory and may increase warm-up work. In PHP 8.4 a failure to initialize an enabled JIT may stop PHP on startup.
- Run the same workloads with JIT **off** and **on**: generation/loading of chunks, entities and HCF gameplay, median/p95 tick time, TPS, memory and CPU usage.
- Keep JIT disabled by default for production until results show a benefit and no crashes, memory regressions or plugin issues in the target PHP 8.4 ZTS environment.

The configuration is reversible at the next restart. The `/version runtime` command is diagnostic only and never changes OPcache settings.

PHP reference: https://www.php.net/manual/en/opcache.configuration.php
