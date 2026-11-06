#!/usr/bin/env python3
"""Map each game version's costume ids to the web Don-chan's model ids, by icon.

The costume chooser shows a 3D preview with the avatar editor's models (YataiDON's skin, exported by
export_web_assets.py), which number costumes their own way: e.g. the dan costume's 26 rank models
are separate ids there, so every later kigurumi shifts. Every version's chooser icons
(storage/app/public/costumes/<version>/sheet.*, from build_costume_spritesheet.py) are the same pixel
art as YataiDON's icons, so the ids are matched by image. An icon with no close match (collab
costumes YataiDON lacks, dummy slots) maps to null: no preview.

Identical twin icons (the dan costume's ranks, a few recoloured items) keep the game's own id when
it is among the tied ones.

Usage:
    python3 scripts/donchan/map_costumes.py [--yataidon /path/to/YataiDON]

Writes database/data/donchan-costume-map.json: {version: {slot: {game id: model id | null}}}.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from PIL import Image, ImageChops, ImageStat

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_YATAIDON = ROOT.parent / "YataiDON"
SKIN_MODELS = Path("Skins/PyTaikoGreen/Models")
SHEETS = ROOT / "storage" / "app" / "public" / "costumes"
OUTPUT = ROOT / "database" / "data" / "donchan-costume-map.json"
SLOTS = {"kigurumi": "costume_icon", "head": "costume_head_icon", "body": "costume_body_icon"}
SIZE = 48
# Mean per-channel difference (0-255) still counted as the same icon: real matches sit below 1 (Momoiro's
# recoloured rocket 5.5), the closest wrong ones above 13.
THRESHOLD = 8.0


def flatten(icon: Image.Image) -> Image.Image:
    """The icon on a fixed backdrop, so transparent pixels compare equal whatever their colour."""
    icon = icon.convert("RGBA").resize((SIZE, SIZE), Image.NEAREST)
    backdrop = Image.new("RGBA", icon.size, (255, 0, 255, 255))
    return Image.alpha_composite(backdrop, icon).convert("RGB")


def difference(a: Image.Image, b: Image.Image) -> float:
    return sum(ImageStat.Stat(ImageChops.difference(a, b)).mean) / 3


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--yataidon", type=Path, default=DEFAULT_YATAIDON)
    args = parser.parse_args()

    models = args.yataidon / SKIN_MODELS
    references = {
        slot: {int(path.stem): flatten(Image.open(path)) for path in (models / folder).glob("*.png") if path.stem.isdigit()}
        for slot, folder in SLOTS.items()
    }

    result: dict[str, dict[str, dict[str, int | None]]] = {}
    for version_dir in sorted(path for path in SHEETS.iterdir() if (path / "sheet.json").exists()):
        layout = json.loads((version_dir / "sheet.json").read_text())
        sheet = Image.open(version_dir / "sheet.png")
        cell = layout["cell"]
        result[version_dir.name] = {}
        for slot in SLOTS:
            mapping: dict[str, int | None] = {}
            for item in layout["slots"].get(slot, []):
                icon = flatten(sheet.crop((item["x"], item["y"], item["x"] + cell, item["y"] + cell)))
                scores = {model_id: difference(icon, reference) for model_id, reference in references[slot].items()}
                best = min(scores.values())
                tied = [model_id for model_id, score in scores.items() if score == best]
                choice = item["id"] if item["id"] in tied else min(tied)
                mapping[str(item["id"])] = choice if best <= THRESHOLD else None
            result[version_dir.name][slot] = mapping
            missing = sorted(int(key) for key, value in mapping.items() if value is None)
            print(f"{version_dir.name} {slot}: {len(mapping)} ids, no model for {missing}")

    OUTPUT.write_text(json.dumps(result, separators=(",", ":"), sort_keys=True) + "\n")
    print(f"wrote {OUTPUT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
