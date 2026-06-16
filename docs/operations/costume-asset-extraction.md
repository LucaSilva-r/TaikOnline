# Costume Asset Extraction

How the costume picker's icons are produced from arcade dumps: the nutdata file
formats, where each costume part lives, how the per-version catalogs are
extracted, and how they are packed into the spritesheets the web app serves.

This is reference material for re-running or extending the pipeline (new version,
re-extract, add a slot). The runtime side — how the picker stores and sends the
chosen costume — is in [cosmetics](../features/cosmetics.md).

> **Scope.** Everything here operates on a PS3/RPCS3 arcade dump. None of it runs
> in the Laravel app at request time; it is an offline asset pipeline whose only
> output committed to this repo is `public/costumes/<version>/`.

---

## 1. Where the dumps live

Each arcade release is a separate game folder under the RPCS3 HDD, e.g.
`…/dev_hdd0/game/SCEEX001 GREEN/USRDIR/data/`. The folders map to versions:

| dump folder | version | dump folder | version |
|---|---|---|---|
| `SCEEX001 GREEN` | green | `SCEEXE001 RED` | red |
| `SCEEXE001 BLUE` | blue | `SCEEXE001 MURASAKI` | murasaki |
| `SCEEXE001 YELLOW` | yellow | `SCEEXE001 KIMIDORI` | kimidori |
| `SCEEXE001 WHITE` | white | `SCEEXE001 MOMOIRO` | momoiro |
| | | `SCEEXE001 SORAIRO` | sorairo |

`SCEEXE001 WADAIKO` and `SCEEXE001 2011` exist but are not in `TaikoGameVersion`
and are skipped. **Dumps are not all complete installs** — some carry only a
subset of the data (this caused the false belief that several versions had no
costume icons; see §5).

The relevant subtrees inside a dump:

- `data/nutdata/` — 2D UI textures (icons, name plates, song titles, …).
- `data/don3d/` — 3D Donchan model parts (`parts/{head,body,acc,paint}`, `cos`,
  `face`, `ani`). The **puchi-chara** art lives here.
- `data/lumendata/packed/` — Lumen UI layouts + packed textures (shop chrome).

---

## 2. The texture file formats

Three container formats wrap the same underlying NUT textures.

### NUT (`NTP3`) — a bundle of textures

Big-endian. The atom we ultimately decode.

```
0x00  u16  (after 4-byte "NTP3" magic) — magic at 0x00
0x04  u16  version            (0x100 or 0x200)
0x06  u16  texture count
0x10  first texture chunk; each chunk:
        +0x00 u32 chunk_size      (bytes to next chunk)
        +0x08 u32 texture_size    (pixel-data bytes)
        +0x0C u16 header_size
        +0x12 u16 tex_type        (0=DXT1, 1=DXT3, 2=DXT5, 14=RGBA8(ARGB))
        +0x14 u16 width
        +0x16 u16 height
        +0x20 u32 tex_offset
      pixel data starts at: chunk + tex_offset  (version 0x200)
                            chunk + header_size  (otherwise)
```

Decode: for DXT (`0,1,2`) wrap the raw block in a DDS header and open with
Pillow; for RGBA8 (`14`) the bytes are stored ARGB and must be channel-swapped to
RGBA. Costume icons are 96×96 DXT5 (`tex_type 2`); puchi-chara are RGBA8 (`14`).
`tex_type 17` appears on some 3D model skins (`don3d/cos`, `body`) and is **not
decoded** — it is not needed for icons.

### NDP (`NUT_PACK_TYPE1`) — older icon packs

A 14-byte magic, then a header of length-prefixed ASCII names (each ending
`.nut`), then the embedded NUTs back-to-back. Locate NUTs by scanning for the
`NTP3` magic; sizes are the gaps between successive magics. Names in the header
pair with NUTs by index. One NUT per icon, named `cos_icon_NNN.nut` etc., so the
**index is the in-game id**.

### `.nut` "appendable" catalogs — newer icon packs (IMPORTANT)

Newer versions ship the whole catalog as a **single multi-texture `.nut`**:

```
data/nutdata/<STxxxx>/appendable/00/costume_icon/costume_icon.nut
```

This file is one `NTP3` with N textures (one per costume); the **texture index
is the id**. This is the *authoritative* per-version catalog and is easy to miss
because it is a bare `.nut`, not an `.ndp`. Both schemes must be scanned.

### DDP (`LM_NUT_TYPE1`) — Lumen packed data

`packeddata.ddp` + `packlist.txt` (e.g. `lumendata/packed/reward_shop/`). Header
lists `lm` layout entries and `nut` entries with offsets; the packlist names them.
Only relevant for UI chrome (the reward shop is *not* an item-icon source), but
the parser exists in the texturer tool if needed.

---

## 3. Where each costume part lives

The donderhiroba きせかえ selector has six categories. Mapping to data:

| category | meaning | source | notes |
|---|---|---|---|
| Iro (色) | body colour | — | a shader tint, not a texture; handled by the colour page on the shared `players` row |
| Kigurumi (着ぐるみ) | one-piece full body | `costume_icon` | slot 1 / `costume_1` |
| Atama (頭) | head | `costume_head_icon` | slot 2 / `costume_2` |
| Body (からだ) | body | `costume_body_icon` | slot 3 / `costume_3` |
| Make-up (けしょう) | face | `costume_*` slot 4 | empty in the dumps; not shipped |
| Puchi-chara | floating mascot | `don3d/parts/acc/acc_*.nut` | slot 5 / `costume_5` |

Name plates (`costume_name`, `costume_head_name`, …) are text-label images paired
1:1 by id; extracted but not used by the picker yet.

### Puchi-chara specifics

The puchi-chara catalog is **3D billboards**: `don3d/parts/acc/acc_NNNNNN.nud` is
a trivial quad and the art is entirely in the sibling `acc_NNNNNN.nut` texture.
**No 3D rendering is required.** Texture layouts:

- **512×256** — two animation frames side by side; the **left half** is the icon.
- **256×256** — a single frame; use whole.
- **16×16** — the empty/placeholder slot.

The id is `NNNNNN / 1000` (files step by 1000: `acc_000000`, `acc_001000`, …).
Crop rule: take the left half **only when `width == 2 × height`**, else keep
whole. (Cropping every frame blindly slices the single-frame puchi.)

The `rewardgasha/acc_image_*.nut` files are **gacha-feature banners** (16 of 64
slots used, repeated across versions) — *not* the full catalog. Do not use them
as the puchi source.

---

## 4. Extraction tool & script

Two pieces, both outside this repo, in the RPCS3 dump root
(`…/dev_hdd0/game/`):

- **`taiko texturer/taiko_texturer.py`** — a Qt GUI tool with reusable parsers
  (`_parse_ndp`, `_parse_nut`, `nut_to_png`, `_parse_ddp_header`, `ddp_extract`,
  `_DXT_TYPES`). Reference implementation for the formats above.
- **`extract_costumes.py`** — the batch extractor. Walks every
  `SCEEX*/USRDIR/data/nutdata`, decodes all costume/tone/title/puchi textures to
  PNG, and writes two manifests under `costumes_out/`:
  - `manifest.json` — one row per texture: `{color, pack_id, kind, index, w, h,
    tex_type, src, nut_name, tex_in_nut, sha1, png}`.
  - `dedup.json` — pixel `sha1` → stable uid + every occurrence (cross-version
    identity).

`kind` is one of `full` (kigurumi), `head`, `body`, `puchi`, plus `*_name`,
`tone`, `title`, `monthly`, `gasha`. `pack_id` is the `STxxxx`/`Sxxxxx` pack
folder (or `base`/`don3d`). The extractor scans **both** `*.ndp` and `*.nut` in
each kind directory (the appendable catalogs are bare `.nut`).

Index rules inside the extractor:

- Named single-texture NUT (`cos_icon_NNN.nut`) → id = the embedded number.
- Multi-texture NUT / `image.nut` / appendable `.nut` → id = running texture
  index across the ordered sub-pack folders.

Run:

```bash
cd "…/dev_hdd0/game"
python3 extract_costumes.py          # → costumes_out/{manifest,dedup}.json + PNGs
```

---

## 5. Per-version catalogs

Costume ids are **cumulative and stable from Momoiro onward** (Blue/Murasaki
match Green's kigurumi ids 100%, Momoiro 98%, Kimidori 86%). **Sorairo uses a
different, older id scheme** (≈4% overlap) and must use its own data.

Each version's catalog is the **most complete pack** for each kind in *its own
dump* — `best_pack` = the pack id with the largest index range. Counts:

| version | kigurumi | head | body | puchi | kigurumi source |
|---|---|---|---|---|---|
| sorairo | 91 | – | – | – | `base` (own id scheme) |
| momoiro | 78 | 23 | 33 | – | `ST4100-1` |
| kimidori | 107 | 57 | 66 | – | `ST5100-1` |
| murasaki | 112 | 110 | 124 | 4 | `ST6100-1` |
| white | 120 | 122 | 136 | 49 | `ST7100-1` |
| red | 129 | 140 | 156 | 86 | `ST8100-1` |
| yellow | 135 | 140 | 156 | 96 | `ST9100-1` |
| blue | 149 | 140 | 156 | 108 | `S10100-1` |
| green | 151 | 140 | 156 | 121 | `S11100-1` |

Versions whose kigurumi/head/body live only in appendable `.nut`s (white, red,
yellow, blue, green) were initially thought to lack icons — they do not; the
extractor just had to scan `.nut` as well as `.ndp`. Older versions hide empty
categories in the picker (Sorairo shows only Full body; pre-Murasaki has no
puchi).

---

## 6. Spritesheet packing (what the web app consumes)

Loading hundreds of PNGs per page is wasteful, so each version is packed into one
spritesheet addressed by CSS `background-position`.

**`TaikOnline/scripts/build_all_costumes.py`** reads `costumes_out/manifest.json`
and, per version, copies that version's own sprites and builds the sheet:

```bash
cd /…/TaikOnline
python3 scripts/build_all_costumes.py     # all versions
```

Output per version under `public/costumes/<version>/`:

- `<slot>/<id>.png` — the source icons (kept for re-packing / inspection).
- `sheet.png` — all slots packed into a 16-column grid of **72×72** cells.
- `sheet.json`:
  ```json
  { "cell": 72, "sheet": [width, height],
    "slots": { "kigurumi": [{ "id": 0, "x": 0, "y": 0 }, …], "head": […], … } }
  ```

The icon for cell `i` sits at `x = (i % 16) * 72`, `y = (i // 16) * 72`; icons are
NEAREST-scaled to keep the pixel art crisp.

`CostumeController::spritesheet()` serves `sheet.json` as the `sheet` prop;
`settings/Costumes.svelte` renders each item as a 72×72 `<button>` with
`background-image: url(sheet.png); background-position: -x -y`. Slots absent from
`sheet.json` are hidden (no tab).

To re-generate after a re-extract or a fix: re-run `extract_costumes.py`, then
`build_all_costumes.py`. (`scripts/build_costume_spritesheet.py` is an older
single-version helper, superseded by `build_all_costumes.py`.)

---

## 7. The 3D model format (NUD / `NDP3`) — partial

`don3d/**/*.nud` are **`NDP3`** models — the Namco/Smash-style NUD format
(the same family parsed by SmashForge). They were investigated for puchi-chara
rendering but **not fully reverse-engineered**, because puchi-chara turned out to
be 2D billboard textures (§3), so no model rendering was needed.

What is known:

- Header is big-endian, 0x30 bytes: `"NDP3"`, `u32 fileSize @0x04`,
  `u16 version (0x0200) @0x08`, mesh/bone counts and clump offsets follow, then a
  bounding box and per-mesh objects with materials and polygon lists.
- `acc` models are ~490-byte single-quad billboards; `head`/`body`/`cos` models
  are full meshes (tens–hundreds of KB).
- Skin textures are the sibling `.nut`; some use `tex_type 17` (undecoded).

If full 3D is ever needed (e.g. rendering head+body combos the game composites at
runtime), the geometry/material parse still has to be written — start from the
SmashForge NUD reader and the header notes above.

---

## 8. Quick re-run checklist

1. New/updated dump under `…/dev_hdd0/game/SCEEX* …`.
2. `python3 extract_costumes.py` (in the dump root) → refresh `costumes_out/`.
3. `python3 scripts/build_all_costumes.py` (in TaikOnline) → refresh
   `public/costumes/<version>/`.
4. Commit `public/costumes/`. No app code change needed — the picker is
   version-generic and reads `sheet.json`.
