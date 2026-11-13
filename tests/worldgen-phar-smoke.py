#!/usr/bin/env python3
"""Generate, save, reopen and extend three opt-in worlds with the actual PHAR."""

import argparse
import os
from pathlib import Path
import shutil
import socket
import subprocess
from tempfile import TemporaryDirectory


def test_profile(php, phar, cache, fixture, profile, benchmark_seed=None, cache_failure=False):
    with TemporaryDirectory(prefix=f"ryze-worldgen-{profile}-") as directory:
        data = Path(directory)
        plugins = data / "plugins"
        plugins.mkdir()
        package = plugins / "WorldgenFixture.phar"
        builder = """
        $p = new Phar($argv[1]);
        $p->startBuffering();
        $p->addFile($argv[2] . "/plugin.yml", "plugin.yml");
        $p->addFile($argv[2] . "/Main.php", "src/RyzeTests/Worldgen/Main.php");
        $p->setStub("<?php __HALT_COMPILER();");
        $p->stopBuffering();
        """
        subprocess.run(
            [php, "-d", "phar.readonly=0", "-r", builder, str(package), str(fixture)],
            check=True,
        )
        config = (fixture.parents[2] / "resources/pocketmine.yml").read_text()
        config = config.replace("enable-dev-builds: false", "enable-dev-builds: true")
        config = config.replace("async-workers: auto", "async-workers: 2")
        (data / "pocketmine.yml").write_text(config)
        (data / "dimensions.yml").write_text(
            "nether:\n  source-dimension: nether\nend:\n  source-dimension: end\n"
        )
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        (data / "server.properties").write_text(
            f"server-ip=127.0.0.1\nserver-port={port}\nenable-ipv6=off\n"
        )
        if cache_failure:
            source_cache = cache
            cache = data / "private-worldgen-cache"
            shutil.copytree(source_cache, cache, ignore=shutil.ignore_patterns("compiled"))
        env = os.environ | {
            "POCKETMINE_VANILLA_WORLDGEN_DATA": str(cache),
            "RYZE_WORLDGEN_TEST_PROFILE": profile,
        }
        if cache_failure:
            env["RYZE_WORLDGEN_TEST_CACHE_FAILURE"] = "1"
            markers = ["PASS: cache mismatch rejected new chunks and preserved stored terrain"]
        elif benchmark_seed is None:
            markers = [f"PASS: {profile} {outcome} all three dimensions"
                       for outcome in ("generated and saved", "reopened and extended")]
        else:
            env["RYZE_WORLDGEN_BENCHMARK_SEED"] = str(benchmark_seed)
            markers = [f'BENCHMARK: {{"seed":{benchmark_seed},']
        for marker in markers:
            with (data / "console.log").open("w+") as log:
                try:
                    process = subprocess.run(
                        [php, phar, "--no-wizard", "--disable-ansi", f"--data={data}", f"--plugins={plugins}"],
                        env=env, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
                        timeout=150 if benchmark_seed is None else 480,
                    )
                finally:
                    log.seek(0)
                    output = log.read()
                    print(output, end="")
            if (
                process.returncode
                or marker not in output
                or any(marker in output for marker in ("Fatal error", "Uncaught", "CRITICAL", "EMERGENCY"))
            ):
                raise RuntimeError(f"PHAR did not report {marker}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("php", help="PHP 8.4 ZTS executable")
    parser.add_argument("phar", type=Path)
    parser.add_argument("cache", type=Path)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--benchmark", action="store_true", help="Run the Linux core-v6 concurrency benchmark")
    mode.add_argument("--cache-failure", action="store_true", help="Test rejection using a private cache copy")
    parser.add_argument("profiles", nargs="*", default=["core-v1", "core-v4", "core-v5", "core-v6"])
    args = parser.parse_args()
    fixture = Path(__file__).resolve().parent / "fixtures/worldgen"
    phar = str(args.phar.resolve())
    cache = args.cache.resolve()
    if args.cache_failure:
        test_profile(args.php, phar, cache, fixture, "core-v6", cache_failure=True)
    elif args.benchmark:
        for seed in (12345, -987654321):
            test_profile(args.php, phar, cache, fixture, "core-v6", benchmark_seed=seed)
    else:
        for profile in args.profiles:
            test_profile(args.php, phar, cache, fixture, profile)


if __name__ == "__main__":
    main()
