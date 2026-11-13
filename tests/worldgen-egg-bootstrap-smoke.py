#!/usr/bin/env python3
"""Run one-shot automatic core-v6 setup through the real packaged RyzeMP PHAR."""

import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
from tempfile import TemporaryDirectory


def run_bootstrap(php, phar, root, expected_success=True):
    proc = subprocess.run(
        [php, "-d", "memory_limit=2G", phar, "--prepare-vanilla-worldgen"],
        cwd=root, stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
        timeout=120,
    )
    print(proc.stdout[-8000:], flush=True)
    if (proc.returncode == 0) != expected_success:
        raise AssertionError(f"Vanilla setup returned {proc.returncode}, expected success={expected_success}")
    return proc.stdout


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("php")
    parser.add_argument("phar")
    parser.add_argument("cache")
    options = parser.parse_args()
    php = str(Path(options.php).resolve())
    phar = str(Path(options.phar).resolve())
    cache = Path(options.cache).resolve()

    with TemporaryDirectory(prefix="ryze-egg-bootstrap-") as tmp:
        root = Path(tmp)
        (root / "worlds/world").mkdir(parents=True)
        (root / "worlds/world_nether").mkdir()
        (root / "worlds/world_the_end").mkdir()
        for name in ("world", "world_nether", "world_the_end"):
            (root / f"worlds/{name}/level.dat").write_text("ORIGINAL:" + name)
        (root / "server.properties").write_text(
            "server-ip=127.0.0.1\nserver-port=19132\nlevel-name=world\n"
            "level-type=DEFAULT\nlevel-seed=12345\n"
        )
        (root / "dimensions.yml").write_text(
            "nether:\n  enabled: true\n  world: world_nether\n"
            "end:\n  enabled: true\n  world: world_the_end\n"
        )
        shutil.copytree(cache, root / "vanillamine-cache",
                        ignore=shutil.ignore_patterns("compiled"))
        report = run_bootstrap(php, phar, root)
        assert "Configured new core-v6 Overworld world_vanilla_test" in report
        assert "Verified VanillaMine data SHA-256:" in report
        props = (root / "server.properties").read_text()
        dimensions = (root / "dimensions.yml").read_text()
        assert "level-name=world_vanilla_test" in props
        assert "level-type=ryze_vanilla_overworld" in props
        assert "generator-settings=core-v6;data=" in props
        assert "world_vanilla_test_nether" in dimensions
        assert "world_vanilla_test_end" in dimensions
        assert "worldgen-preset:" in dimensions
        for name in ("world", "world_nether", "world_the_end"):
            assert (root / f"worlds/{name}/level.dat").read_text() == "ORIGINAL:" + name

        # Idempotent before and after the new world is created.
        run_bootstrap(php, phar, root)
        assert (root / "server.properties").read_text() == props
        (root / "worlds/world_vanilla_test").mkdir()
        (root / "worlds/world_vanilla_test/level.dat").write_text("NEW VANILLA WORLD")
        run_bootstrap(php, phar, root)
        assert (root / "server.properties").read_text() == props

        # Altering even a small metadata field changes the actual cache identity.
        stamp = root / "vanillamine-cache/version.json"
        data = json.loads(stamp.read_text())
        data["created"] = int(data.get("created", 0)) + 1
        stamp.write_text(json.dumps(data))
        output = run_bootstrap(php, phar, root, expected_success=False)
        assert "different pinned cache" in output
        assert (root / "worlds/world_vanilla_test/level.dat").read_text() == "NEW VANILLA WORLD"
        assert (root / "server.properties").read_text() == props
        print("AUTO_VANILLA_BOOTSTRAP_PASS worldgen core-v6; "
              "old worlds preserved; new world pinned; cache mismatch rejected", flush=True)


if __name__ == "__main__":
    main()
