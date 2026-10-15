#!/usr/bin/env python3
"""Fast Cloud draft-PR lint for *changed* PHP and JavaScript files.

No shell interpolation or network requests; diff fails closed if base is
unavailable. The full release and real MySQL suites still run for ready PRs.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import subprocess


def syntax_commands(paths: list[str]) -> list[list[str]]:
    commands = []
    for path in sorted(set(paths)):
        # Restrict to tracked repository-relative file paths, no traversal.
        parts = Path(path).parts
        if not parts or Path(path).is_absolute() or ".." in parts:
            raise ValueError("Unsafe changed-file path")
        if not Path(path).is_file():
            continue
        if path.endswith(".php"):
            commands.append(["php", "-l", path])
        elif path.endswith((".js", ".mjs", ".cjs")):
            commands.append(["node", "--check", path])
    return commands


def changed_files(base: str) -> list[str]:
    if not re.fullmatch(r"[0-9a-fA-F]{40}", base):
        raise ValueError("Expected the exact GitHub PR base commit SHA")
    raw = subprocess.check_output(
        ["git", "diff", "--name-only", "--diff-filter=ACMR", "-z", base + "...HEAD"]
    )
    return [name.decode("utf-8", errors="surrogateescape")
            for name in raw.split(b"\0") if name]


def main() -> None:
    base = os.environ.get("BASE_SHA", "")
    paths = changed_files(base)
    commands = syntax_commands(paths)
    if not commands:
        print("CI-G1: no changed PHP/JS sources; full tests run at ready-for-review.")
    for cmd in commands:
        print("CI-G1:", " ".join(cmd), flush=True)
        subprocess.run(cmd, check=True)
    print(f"CI-G1 draft smoke: {len(commands)} changed sources validated")


if __name__ == "__main__":
    main()
