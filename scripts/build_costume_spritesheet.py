#!/usr/bin/env python3
"""
Pack a version's costume icons into a single spritesheet + JSON coord map so the
frontend loads one image instead of hundreds. Sources the per-slot PNGs already
published under public/costumes/<version>/<slot>/<id>.png.

Output (per version):
  public/costumes/<version>/sheet.png
  public/costumes/<version>/sheet.json  { cell, sheet:[w,h], slots:{slot:[{id,x,y}]} }

Usage: python3 scripts/build_costume_spritesheet.py [version ...]   (default: green)
"""
import json
import sys
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
CELL = 96            # px per icon cell; source icons are 96px, so do not rescale
PUCHI_CELL = 64      # puchi source icons are 256px; 64px is an exact 4:1 reduction
COLS = 16            # icons per row in the sheet
SLOTS = ["kigurumi", "body", "head", "puchi"]
RESAMPLE_NEAREST = Image.Resampling.NEAREST if hasattr(Image, "Resampling") else Image.NEAREST


def fit_icon(icon: Image.Image, slot: str, path: Path) -> Image.Image:
    if icon.width <= CELL and icon.height <= CELL:
        return icon

    if (
        slot == "puchi"
        and icon.width == icon.height
        and icon.width % PUCHI_CELL == 0
    ):
        return icon.resize((PUCHI_CELL, PUCHI_CELL), RESAMPLE_NEAREST)

    raise ValueError(f"{path} is {icon.width}x{icon.height}, larger than {CELL}px cell")


def build(version: str) -> None:
    base = ROOT / "public" / "costumes" / version
    items = []  # (slot, id, Path), in slot then id order
    for slot in SLOTS:
        d = base / slot
        if not d.is_dir():
            continue
        for p in sorted(d.glob("*.png"), key=lambda f: int(f.stem)):
            items.append((slot, int(p.stem), p))

    if not items:
        print(f"[{version}] no icons found, skipping")
        return

    rows = (len(items) + COLS - 1) // COLS
    sheet = Image.new("RGBA", (COLS * CELL, rows * CELL), (0, 0, 0, 0))
    slots: dict[str, list] = {s: [] for s in SLOTS}

    for i, (slot, cid, path) in enumerate(items):
        x, y = (i % COLS) * CELL, (i // COLS) * CELL
        icon = fit_icon(Image.open(path).convert("RGBA"), slot, path)
        off = ((CELL - icon.width) // 2, (CELL - icon.height) // 2)
        sheet.paste(icon, (x + off[0], y + off[1]), icon)
        slots[slot].append({"id": cid, "x": x, "y": y})

    sheet.save(base / "sheet.png")
    (base / "sheet.json").write_text(json.dumps({
        "cell": CELL,
        "sheet": [sheet.width, sheet.height],
        "slots": slots,
    }))
    counts = {s: len(v) for s, v in slots.items() if v}
    print(f"[{version}] sheet {sheet.width}x{sheet.height}  {counts}")


if __name__ == "__main__":
    for v in (sys.argv[1:] or ["green"]):
        build(v)
