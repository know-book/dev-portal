#!/usr/bin/env python3

import re
import sys
from pathlib import Path


def fail(message: str) -> None:
    raise SystemExit(message)


if len(sys.argv) < 4:
    fail("usage: update-kustomization-tags.py PATH TAG IMAGE [IMAGE ...]")

path = Path(sys.argv[1])
tag = sys.argv[2]
targets = set(sys.argv[3:])

if not re.fullmatch(r"[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}", tag):
    fail(f"invalid container image tag: {tag}")

lines = path.read_text().splitlines(keepends=True)
in_images = False
current_image = None
updated = set()

for index, line in enumerate(lines):
    if re.match(r"^images:\s*(?:#.*)?(?:\r?\n)?$", line):
        in_images = True
        current_image = None
        continue

    if in_images and re.match(r"^[A-Za-z][A-Za-z0-9_-]*:\s*", line):
        in_images = False
        current_image = None

    if not in_images:
        continue

    name_match = re.match(
        r"^\s*-\s*name:\s*[\"']?([^\"'\r\n]+?)[\"']?\s*(?:#.*)?(?:\r?\n)?$",
        line,
    )
    if name_match:
        current_image = name_match.group(1).strip()
        continue

    if current_image not in targets:
        continue

    tag_match = re.match(r"^(\s*newTag:\s*).*(\r?\n)?$", line)
    if tag_match:
        lines[index] = f"{tag_match.group(1)}{tag}{tag_match.group(2) or ''}"
        updated.add(current_image)
        current_image = None

missing = targets - updated
if missing:
    fail("image entries not found in kustomization.yaml: " + ", ".join(sorted(missing)))

path.write_text("".join(lines))
