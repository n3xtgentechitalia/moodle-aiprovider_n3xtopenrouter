#!/usr/bin/env python3
"""Lightweight Moodle coding-style checks for this plugin.

A stand-in for moodle-plugin-ci phpcs when a Moodle checkout is not available.
It is not a replacement: CI still runs the real thing.

Usage: python3 tools/check_style.py
"""
import os
import re
import sys

REPO = os.path.dirname(os.path.abspath(os.path.dirname(__file__)))
COMPONENT = "aiprovider_n3xtopenrouter"

# Identifiers that must stay gone, with the reason they were removed.
FORBIDDEN = {
    "orgid": "OpenRouter ignores the OpenAI organization header",
    "testaiservices": "the legacy settings page was removed",
    "numberimages": "not sent: exactly one image is requested",
    "responseformat": "not a parameter of the OpenRouter image endpoint",
    "api.openai.com": "this plugin talks to OpenRouter, not OpenAI",
    "dall-e": "left over from the OpenAI image code",
}

problems = []
phpfiles = []

for root, dirs, files in os.walk(REPO):
    dirs[:] = [d for d in dirs if d != ".git"]
    for name in sorted(files):
        path = os.path.join(root, name)
        rel = os.path.relpath(path, REPO)
        raw = open(path, "rb").read()

        if not raw:
            problems.append(f"{rel}: empty file")
            continue
        if not raw.endswith(b"\n"):
            problems.append(f"{rel}: no trailing newline")
        if b"\r\n" in raw:
            problems.append(f"{rel}: CRLF line endings")

        try:
            text = raw.decode("utf-8")
        except UnicodeDecodeError:
            problems.append(f"{rel}: not valid UTF-8")
            continue

        if name.endswith(".php"):
            phpfiles.append(rel)
            if text.rstrip().endswith("?>"):
                problems.append(f"{rel}: closing ?> tag")
            if not text.startswith("<?php\n"):
                problems.append(f"{rel}: does not start with <?php")
            if "GNU General Public License" not in text:
                problems.append(f"{rel}: missing GPL header")

        for number, line in enumerate(text.split("\n"), 1):
            if line != line.rstrip():
                problems.append(f"{rel}:{number}: trailing whitespace")
            if "\t" in line and not name.endswith(".md"):
                problems.append(f"{rel}:{number}: tab indentation")
            # moodle-cs exempts language files from the line length rule, which
            # core's own lang files rely on (moodle.php has 140 such lines).
            if name.endswith(".php") and "/lang/" not in path and len(line) > 132:
                problems.append(f"{rel}:{number}: line is {len(line)} chars (max 132)")

for rel in phpfiles + ["README.md", "CONTRIBUTING.md", "docs/OPERATIONS.md"]:
    full = os.path.join(REPO, rel)
    if not os.path.isfile(full):
        continue
    # The changelog and operations notes discuss removals on purpose, and the
    # verification scripts assert that the removed things are absent.
    if rel in ("CHANGELOG.md", "docs/OPERATIONS.md") or rel.startswith("tools/"):
        continue
    source = open(full).read()
    for token, reason in FORBIDDEN.items():
        if token in source:
            problems.append(f'{rel}: still references "{token}" ({reason})')

print(f"php files checked: {len(phpfiles)}")
if problems:
    print(f"PROBLEMS ({len(problems)}):")
    for problem in problems:
        print("  -", problem)
    sys.exit(1)

print("style: no problems")
sys.exit(0)
