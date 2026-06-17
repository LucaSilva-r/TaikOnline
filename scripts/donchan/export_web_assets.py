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
    public/donchan/models/head/{id}.glb  head part models
    public/donchan/models/body/{id}.glb  body part models
    public/donchan/sheet.png             picker spritesheet matching the exported GLB ids
    public/donchan/sheet.json            picker sprite coordinates
    public/donchan/animations.glb        shared skeleton animations (used to pose the model)
    public/donchan/face/{sheet}.png      face expression sheets (12 stacked 128x128 frames)
"""

from __future__ import annotations

import argparse
import json
import shutil
import sys
from pathlib import Path

from PIL import Image

# Default sibling checkout of the simulator that owns the source assets.
DEFAULT_YATAIDON = Path(__file__).resolve().parents[3] / "YataiDON"
SKIN_MODELS = Path("Skins/PyTaikoGreen/Models")

PUBLIC_ROOT = Path(__file__).resolve().parents[2] / "public" / "donchan"
CELL = 96
COLS = 16
SLOTS = {
    "kigurumi": ("cos", "costume_icon"),
    "head": ("head", "costume_head_icon"),
    "body": ("body", "costume_body_icon"),
}


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


def numeric_pngs(src: Path) -> list[Path]:
    return sorted(
        (file for file in src.glob("*.png") if file.stem.isdigit()),
        key=lambda file: int(file.stem),
    )


def model_ids(src: Path) -> set[int]:
    return {int(file.stem) for file in src.glob("*.glb") if file.stem.isdigit()}


def build_picker_sheet(models_root: Path) -> dict[str, int]:
    """Build a picker sheet whose ids map directly to exported GLB filenames."""
    items: list[tuple[str, int, Path]] = []
    slots: dict[str, list[dict[str, int]]] = {slot: [] for slot in SLOTS}
    skipped: dict[str, int] = {}

    for slot, (model_dir, icon_dir) in SLOTS.items():
        available_models = model_ids(models_root / model_dir)
        missing_model = 0

        for icon in numeric_pngs(models_root / icon_dir):
            costume_id = int(icon.stem)
            if costume_id not in available_models:
                missing_model += 1
                continue

            items.append((slot, costume_id, icon))

        if missing_model > 0:
            skipped[f"{slot}_icons_without_models"] = missing_model

        missing_icons = len(available_models - {costume_id for item_slot, costume_id, _ in items if item_slot == slot})
        if missing_icons > 0:
            skipped[f"{slot}_models_without_icons"] = missing_icons

    if not items:
        return {}

    rows = (len(items) + COLS - 1) // COLS
    sheet = Image.new("RGBA", (COLS * CELL, rows * CELL), (0, 0, 0, 0))

    for index, (slot, costume_id, path) in enumerate(items):
        x = (index % COLS) * CELL
        y = (index // COLS) * CELL
        icon = Image.open(path).convert("RGBA")
        if icon.width > CELL or icon.height > CELL:
            raise ValueError(f"{path} is {icon.width}x{icon.height}, larger than {CELL}px cell")
        offset = ((CELL - icon.width) // 2, (CELL - icon.height) // 2)
        sheet.paste(icon, (x + offset[0], y + offset[1]), icon)
        slots[slot].append({"id": costume_id, "x": x, "y": y})

    sheet.save(PUBLIC_ROOT / "sheet.png")
    (PUBLIC_ROOT / "sheet.json").write_text(json.dumps({
        "cell": CELL,
        "sheet": [sheet.width, sheet.height],
        "slots": slots,
    }))

    return {slot: len(items) for slot, items in slots.items() if items} | skipped


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
    head = copy_tree(models_root / "head", PUBLIC_ROOT / "models" / "head", "*.glb", args.force)
    body = copy_tree(models_root / "body", PUBLIC_ROOT / "models" / "body", "*.glb", args.force)
    face = copy_tree(models_root / "face", PUBLIC_ROOT / "face", "*.png", args.force)
    sheet = build_picker_sheet(models_root)

    anim_src = models_root / "animations.glb"
    anim_dst = PUBLIC_ROOT / "animations.glb"
    anim = 0
    if anim_src.is_file() and (args.force or not anim_dst.exists()):
        anim_dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(anim_src, anim_dst)
        anim = 1

    print(f"exported: {cos} kigurumi, {head} head, {body} body glb, "
          f"{face} face sheets, {anim} animations.glb")
    if sheet:
        print(f"picker sheet: {sheet}")
    print(f"  -> {PUBLIC_ROOT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
