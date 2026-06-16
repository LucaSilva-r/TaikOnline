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

## 7. The 3D model format (NUD / `NDP3`)

`don3d/**/*.nud` are **NUD** models — the Namco "NU3G" format, the same one
SmashForge reads (`Smash Forge/Filetypes/Models/Nuds/NUD.cs`). The picker did not
need this (puchi-chara are 2D billboards, §3), but the format is fully documented
below for any future 3D work (e.g. compositing head+body the way the game does).

All multi-byte fields are **big-endian** for `NDP3` (a little-endian `NDWD`
variant exists; `version` is always read big-endian regardless).

### 7.1 Header (0x30 bytes)

```
0x00  char[4]  "NDP3"            (big-endian) | "NDWD" (little-endian)
0x04  u32      fileSize
0x08  u16      version           (0x0200; always read BE)
0x0A  u16      polysetCount      (number of mesh objects)
0x0C  s16      boneIndexStart
0x0E  s16      boneIndexEnd
0x10  u32      polyClumpStart    (add 0x30 → absolute)
0x14  u32      polyClumpSize
0x18  u32      vertClumpSize     (vertClumpStart  = polyClumpStart  + polyClumpSize)
0x1C  u32      vertAddClumpSize  (vertAddClumpStart= vertClumpStart + vertClumpSize)
0x20  f32[4]   bounding sphere   (nameStart = vertAddClumpStart + vertAddClumpSize)
```

Derived bases: `vertClumpStart`, `vertAddClumpStart`, `nameStart` as above.

### 7.2 Object (mesh) descriptors — `polysetCount` of them, right after header

```
f32[4]  bounding sphere
f32[3]  bounding sphere XYZ repeated (ignored)
f32     sortBias
u32     nameOffset     (relative to nameStart; null-terminated ASCII name)
u16     0              (always 0)
u16     boneFlag       (single-bound / weighted / unbound)
s16     singleBind     (bone index if single-bound, else -1)
u16     polyCount      (polygons in this mesh)
u32     positionb
```

### 7.3 Polygon descriptors — `polyCount` per mesh, read sequentially

```
u32  polyStart     (+ polyClumpStart    → face-index buffer)
u32  vertStart     (+ vertClumpStart    → vertex buffer)
u32  vertAddStart  (+ vertAddClumpStart → extra vertex buffer)
u16  vertCount
u8   vertSize      (nibble-packed, see 7.4)
u8   uvSize        (nibble-packed, see 7.4)
u32  texProp1      (offset to material chain; see 7.6)
u32  texProp2..4
u16  faceCount
u16  polyFlag      (high byte = primitive type, low byte = flags)
…    skip 0xC
```

### 7.4 Vertex/UV format nibbles

`vertSize`: high nibble = **bone type**, low nibble = **normal type**.

| bone type | meaning | | normal type | meaning |
|---|---|---|---|---|
| 0x00 | no bones | | 0x0 | no normals |
| 0x10 | float (4×id int + 4×weight f32) | | 0x1 | normals f32 |
| 0x20 | half-float (4×id u16 + 4×weight half) | | 0x3 | normals + tan + bitan f32 |
| 0x40 | byte (4×id u8 + 4×weight u8/255) | | 0x6 | normals half |
| | | | 0x7 | normals + tan + bitan half |

`uvSize`: high nibble = **uv channel count**; low nibble = **colour type**
(`0` none, `2` byte RGBA, `4` half-float RGBA) OR'd with **uv type**
(`0` half-float, `1` float).

### 7.5 Vertex buffers

Two layouts depending on bone type:

- **Weighted (`boneType > 0`)**: `vertStart` buffer holds colour+UV per vertex;
  `vertAddStart` buffer holds position, normal (+tan/bitan), and bone ids/weights.
- **Single-bound (`boneType == 0`)**: everything interleaved in `vertStart` —
  position+normal then colour+UV per vertex; every vertex is bound to the mesh's
  `singleBind` with weight 1.

Per-vertex read order: position `f32[3]`; then the normal block per the normal
type (note the float normal variants store a leading `f32` ≈100.0 before XYZ);
then bones per the bone type; colour/UV per the uv nibble.

### 7.6 Faces

At `polyStart`, `faceCount` × `u16` indices. The primitive type is
`polyFlag >> 8`: `0x40` = triangle list (use as-is); `0x00` = triangle **strip**
with `0xFFFF` as the restart marker and alternating winding (see
`Polygon.GetTriangles`).

### 7.7 Materials & textures

`texProp1` points to a material; materials form a linked chain (each ends with
the offset of the next, `0` = end). A material has flags, blend src/dst factors,
alpha func, cull mode, then `texCount` textures. Each texture entry carries a
**`hash` (int)** plus wrap/filter modes; the hash links to a texture in the
sibling `.nut` (NUT entries are keyed by the same hash / GIDX). So to texture a
model you pair its material texture hashes with the model's `.nut`.

### 7.8 Practical notes for Taiko

- `acc` models are ~490-byte single-quad billboards (the puchi-chara art is in
  the `.nut`, not the mesh).
- `head`/`body`/`cos` are full meshes (tens–hundreds of KB).
- Some skin textures use NUT `tex_type 17` (undecoded by our extractor — only
  DXT1/3/5 and RGBA8 are handled; add a decoder if rendering these).
- Reference reader: `Smash Forge/Filetypes/Models/Nuds/{NUD,Polygon}.cs`.

---

## 8. Quick re-run checklist

1. New/updated dump under `…/dev_hdd0/game/SCEEX* …`.
2. `python3 extract_costumes.py` (in the dump root) → refresh `costumes_out/`.
3. `python3 scripts/build_all_costumes.py` (in TaikOnline) → refresh
   `public/costumes/<version>/`.
4. Commit `public/costumes/`. No app code change needed — the picker is
   version-generic and reads `sheet.json`.
