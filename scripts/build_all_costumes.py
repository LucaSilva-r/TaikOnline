#!/usr/bin/env python3
"""
Build per-version costume icons + spritesheet from the rpcs3 nutdata extraction.

Each Taiko version gets ONLY its own dump's sprites so the in-game ids are
guaranteed correct (ids are not stable across the oldest versions). For every
(version, slot) the most complete pack in that dump is used. Slots with no data
in a dump are simply absent (the picker hides empty tabs).

Source : <RPCS3>/costumes_out/manifest.json  (see extract_costumes.py)
Output : public/costumes/<version>/{<slot>/<id>.png, sheet.png, sheet.json}
"""
import json
import shutil
from collections import defaultdict
from pathlib import Path

from PIL import Image

TAIKONLINE = Path(__file__).resolve().parent.parent
SRC = Path("/home/silvaluca/Documents/git/rpcs3/build/bin/dev_hdd0/game/costumes_out")

# enum version value -> dump color folder name in the extraction.
VERSION_DUMP = {
    "sorairo": "SCEEXE001 SORAIRO",
    "momoiro": "SCEEXE001 MOMOIRO",
    "kimidori": "SCEEXE001 KIMIDORI",
    "murasaki": "SCEEXE001 MURASAKI",
    "white": "SCEEXE001 WHITE",
    "red": "SCEEXE001 RED",
    "yellow": "SCEEXE001 YELLOW",
    "blue": "SCEEXE001 BLUE",
    "green": "SCEEX001 GREEN",
}

# picker slot -> manifest kind.
SLOT_KIND = {"kigurumi": "full", "head": "head", "body": "body", "puchi": "puchi"}

CELL = 72
COLS = 16


def best_pack(rows, kind):
    """Pack id with the most icons for this kind (largest id range)."""
    by_pack = defaultdict(list)
    for r in rows:
        if r["kind"] == kind:
            by_pack[r["pack_id"]].append(r)
    if not by_pack:
        return None
    return max(by_pack.values(), key=lambda rs: max(r["index"] for r in rs))


def slot_icons(version_rows, slot):
    """Yield (id, source_png_path) for a slot, deduped by id (keep first)."""
    pack = best_pack(version_rows, SLOT_KIND[slot])
    if not pack:
        return
    seen = set()
    for r in sorted(pack, key=lambda r: r["index"]):
        cid = r["index"] // 1000 if slot == "puchi" else r["index"]
        if cid in seen:
            continue
        seen.add(cid)
        yield cid, SRC / r["png"]


def build(version, dump):
    rows = [r for r in MANIFEST if r["color"] == dump]
    base = TAIKONLINE / "public" / "costumes" / version
    if base.exists():
        shutil.rmtree(base)

    items = []  # (slot, id, Path) in sheet order
    report = {}
    for slot in SLOT_KIND:
        d = base / slot
        n = 0
        for cid, src in slot_icons(rows, slot):
            im = Image.open(src).convert("RGBA")
            if slot == "puchi" and im.width == 2 * im.height:  # two anim frames
                im = im.crop((0, 0, im.width // 2, im.height))
            d.mkdir(parents=True, exist_ok=True)
            im.save(d / f"{cid}.png")
            items.append((slot, cid, d / f"{cid}.png"))
            n += 1
        if n:
            report[slot] = n

    if not items:
        print(f"{version:9} (no data)")
        return

    rows_n = (len(items) + COLS - 1) // COLS
    sheet = Image.new("RGBA", (COLS * CELL, rows_n * CELL), (0, 0, 0, 0))
    slots = defaultdict(list)
    for i, (slot, cid, path) in enumerate(items):
        x, y = (i % COLS) * CELL, (i // COLS) * CELL
        icon = Image.open(path).convert("RGBA")
        icon.thumbnail((CELL, CELL), Image.NEAREST)
        off = ((CELL - icon.width) // 2, (CELL - icon.height) // 2)
        sheet.paste(icon, (x + off[0], y + off[1]), icon)
        slots[slot].append({"id": cid, "x": x, "y": y})

    sheet.save(base / "sheet.png")
    (base / "sheet.json").write_text(json.dumps({
        "cell": CELL, "sheet": [sheet.width, sheet.height], "slots": slots,
    }))
    print(f"{version:9} {report}")


if __name__ == "__main__":
    MANIFEST = json.load(open(SRC / "manifest.json"))
    for version, dump in VERSION_DUMP.items():
        build(version, dump)
