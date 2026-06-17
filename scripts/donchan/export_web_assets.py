#!/usr/bin/env python3
"""Export the Don-chan 3D assets YataiDON uses into the web-served public dir.

The Three.js avatar customizer (resources/js/lib/donchan/renderer.ts) loads these
GLBs/textures at runtime to render the player's Don. We copy them out of the YataiDON
skin instead of committing 95MB of binaries into this repo, so public/donchan/ is
git-ignored and this script must be run on deploy.

Usage:
    python3 scripts/donchan/export_web_assets.py [--yataidon /path/to/YataiDON] [--force]

Layout produced:
    public/donchan/models/cos/{id}.glb   full-body kigurumi models (loaded one at a time)
    public/donchan/animations.glb        shared skeleton animations (used to pose the model)
    public/donchan/face/{sheet}.png      face expression sheets (12 stacked 128x128 frames)
"""

from __future__ import annotations

import argparse
import shutil
import sys
from pathlib import Path

# Default sibling checkout of the simulator that owns the source assets.
DEFAULT_YATAIDON = Path(__file__).resolve().parents[3] / "YataiDON"
SKIN_MODELS = Path("Skins/PyTaikoGreen/Models")

PUBLIC_ROOT = Path(__file__).resolve().parents[2] / "public" / "donchan"


def copy_tree(src: Path, dst: Path, pattern: str, force: bool) -> int:
    dst.mkdir(parents=True, exist_ok=True)
    count = 0
    for file in sorted(src.glob(pattern)):
        target = dst / file.name
        if target.exists() and not force:
            continue
        shutil.copy2(file, target)
        count += 1
    return count


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--yataidon", type=Path, default=DEFAULT_YATAIDON,
                        help="Path to the YataiDON checkout (default: sibling dir)")
    parser.add_argument("--force", action="store_true",
                        help="Re-copy files that already exist")
    args = parser.parse_args()

    models_root = args.yataidon / SKIN_MODELS
    if not models_root.is_dir():
        print(f"error: models dir not found: {models_root}", file=sys.stderr)
        return 1

    cos = copy_tree(models_root / "cos", PUBLIC_ROOT / "models" / "cos", "*.glb", args.force)
    face = copy_tree(models_root / "face", PUBLIC_ROOT / "face", "*.png", args.force)

    anim_src = models_root / "animations.glb"
    anim_dst = PUBLIC_ROOT / "animations.glb"
    anim = 0
    if anim_src.is_file() and (args.force or not anim_dst.exists()):
        anim_dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(anim_src, anim_dst)
        anim = 1

    print(f"exported: {cos} kigurumi glb, {face} face sheets, {anim} animations.glb")
    print(f"  -> {PUBLIC_ROOT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
