#!/usr/bin/env bash
# Compare an installed copy of this plugin against a commit in this repository.
#
# Use it to prove a site was not modified (compare against the baseline import),
# or that a deployment landed exactly what was intended (compare against HEAD).
#
# Usage: tools/verify_untouched.sh <installed-plugin-path> [git-ref]
set -euo pipefail

installed="${1:-}"
ref="${2:-HEAD}"

if [ -z "$installed" ]; then
    echo "Usage: $0 <installed-plugin-path> [git-ref]" >&2
    exit 2
fi
if [ ! -d "$installed" ]; then
    echo "Not a directory: $installed" >&2
    exit 2
fi

repo="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
report="$(mktemp)"
trap 'rm -rf "$tmp" "$report"' EXIT

git -C "$repo" archive --format=tar "$ref" | tar -x -C "$tmp"

# The release archive excludes development-only paths, so compare the plugin
# payload and ignore anything git-archive would have dropped anyway.
if diff -r --brief \
        --exclude=.git --exclude=.github --exclude=tools \
        --exclude=.gitignore --exclude=.gitattributes --exclude=.editorconfig \
        "$tmp" "$installed" > "$report" 2>&1; then
    echo "IDENTICAL: $installed matches $ref"
    exit 0
fi

echo "DIFFERENCES between $ref and $installed:"
cat "$report"
exit 1
