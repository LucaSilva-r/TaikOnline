Your base installation supplies the game's scenes and songs. In Home mode, you can add more song libraries without replacing the base game data. Each library gets its own folder in Song Select, with genre folders inside.

## Add a library

Open **Escape → Settings → Song Libraries**. Follow the restart prompt after a change so the game can scan the new selection. Keep the source files available while playing: adding a folder does not copy its library into Waddamburo.

| Row              | What to select                                                         | What appears                                       |
| ---------------- | ---------------------------------------------------------------------- | -------------------------------------------------- |
| Custom TJA Songs | Your TJA library folder (default **USRDIR/custom_songs**)              | Supported TJA charts and their referenced audio    |
| osu!lazer Songs  | Your osu!lazer data folder; the default install is found by itself     | Native osu!taiko beatmaps                          |
| osu!stable Songs | Your osu! install (the folder with osu!.db); found by itself too       | Native osu!taiko beatmaps, as a library of its own |
| Official Songs   | Another arcade release, a Nijiiro installation, or a Wii or Wii U game | Their songs, in their own libraries (see below)    |

A green osu! row means the install was found automatically; Enter picks another folder. A red row means the library was left out at the last start; its hint gives the reason.

**Official Songs** is split into three pages so long lists stay readable: **Arcade releases**, **Wii / Wii U**, and **Nijiiro**. Each page lists its folders (Enter removes one) and has **Add a release or Nijiiro**, which accepts any of the three kinds and files it on the right page.

Extra libraries are a Home feature. Arcade mode plays the game's own songs only, as the cabinet does.

The first start after adding a large library takes longer while the song cache is built. Later starts reuse it, and the game checks the libraries for changes in the background after starting.

## Organise TJA songs

The default custom library is **USRDIR/custom_songs**. You can create subfolders for categories:

```text
USRDIR/
  custom_songs/
    My songs/
      Example/
        Example.tja
        Example.ogg
```

The TJA should reference its audio with a relative path, such as `WAVE:Example.ogg`. Both the chart and audio must stay inside the selected library. For nested charts, the first folder below the library root becomes the category. Root-level charts use their GENRE header, or Uncategorized.

UTF-8, UTF-16 with a byte-order mark, and Shift-JIS charts are supported. Files sharing the same audio can be grouped as one song with several difficulties. A malformed chart or missing WAVE file keeps that song out of the browser, not the whole library; see [Troubleshooting](troubleshooting).

TJA compatibility varies by command and simulator extension. Do not assume every gimmick from another simulator behaves identically. Effects authored in Waddamburo can be stored beside a TJA in a `.wddmod` file; see [Modchart editor](modchart-editor).

## Background videos

A chart can play a video behind the lane. Waddamburo reads TJA's `BGMOVIE` (as TJAPlayer3 and OpenTaiko do) and osu! beatmaps' video event, so osu! maps with a video play it with no setup. Most video formats osu! maps use work (MP4/H.264, AVI, FLV, WMV, MPEG, MKV...).

In a TJA, put the video in the chart's folder and add, before the first `COURSE:`:

```text
BGMOVIE:video.mp4
MOVIEOFFSET:0.5
```

| Header | What it does |
| --- | --- |
| `BGMOVIE:file` | The video file, next to the chart. |
| `MOVIEOFFSET:seconds` | Starts the video that many seconds after the song's audio; negative starts it before. Use it to line the video up with the music. |
| `BGMOVIEBLEND:luma` or `BGMOVIEBLEND:luma,curve` | **Luma key**, for videos of light on black, such as hologram concert videos: black becomes see-through, bright parts stay solid, dark parts are partly see-through, so the game's backgrounds show around the figure. The curve (8 by default) sets how solid dark parts stay: bigger keeps dark clothing and hair more solid, smaller makes more of the dark see-through. |
| `BGMOVIEKEY:RRGGBB` or `BGMOVIEKEY:RRGGBB,tolerance` | **Colour key** (green screen): that colour becomes see-through where it joins the edge of the picture, so the same colour inside the figure stays. The tolerance (60 by default) is how far from the colour still counts as background: raise it if a fringe of background remains, lower it if parts of the figure disappear. Example: `BGMOVIEKEY:00FF00,80`. |
| `BGMOVIEBLEND:add` | Adds the video's light onto the backgrounds (light on black). Simple, but it washes out over bright backgrounds (Go-Go time, a full gauge); the luma key usually looks better. |
| `BGMOVIERECT:x,y,width,height` | Places the whole video in that box, in 1280×720 screen pixels. Without it the video fills the screen. |

The luma key, colour key and `add` hide the dancers, whose place the video takes. For a hologram on black, the luma key is the best choice; use the colour key for a real green (or blue) screen. With a key, the backgrounds keep animating around the video, Go-Go time included.

`BGMOVIERECT` lets you size and place the video. Some useful boxes:

| Box | Where |
| --- | --- |
| `0,0,1280,720` | The whole screen |
| `309,348,662,372` | The 16:9 area below Player 1's lane, between the drum and the screen's right edge |

A complete hologram example:

```text
TITLE:World is Mine
WAVE:World is Mine.ogg
BGMOVIE:hologram.mp4
BGMOVIEBLEND:luma,8
BGMOVIERECT:309,348,662,372
MOVIEOFFSET:0
```

Players choose how videos show in **Settings → Game Options**:

| Setting | What it does |
| --- | --- |
| Background Video | **On**: over the backgrounds, the whole 16:9 screen. **Under the lane** (single player): only the area below the lane, so the top keeps the game's own animations. **Off**: no videos. A chart's `BGMOVIERECT` box applies in both On modes. |
| Video Dim | Darkens the video so the notes stay easy to read (20% by default). |
| Video Frame Rate | The most frames a second a video shows: 30 (default) halves the decoding work, for slower devices; 60 for smooth video on a fast PC. |

Big videos cost CPU. For charts you share, 720p or 1080p at 30 or 60 fps in H.264 (MP4) plays well on PCs and the Switch; phones may need videos off. OpenTaiko's switching `BGA` videos and `BGIMAGE` are not read.

## Browse osu! songs

osu!lazer and osu!stable are separate libraries. Only native osu!taiko beatmaps are listed; standard, catch, and mania maps are left out. Close osu! while troubleshooting a scan so its library is not changing underneath it.

Waddamburo uses Easy, Normal, Hard, and Oni as slots for osu! difficulties in star order. More than four charts produce additional song entries. Those slot names do not turn the beatmaps into the equivalent arcade difficulties: osu! charts use Oni judgement windows regardless of the displayed slot.

The library's **Group** and **Sort** folders change how songs are organised: open one and pick a grouping (or a sort order) from the folder, like a dan series. Collections are available as a grouping option.

## Other arcade releases

Use your own legitimately obtained files. Select another Gen3 release's game data: its game folder, USRDIR, or USRDIR/data. Its songs join the game's own library, and the Green base data still supplies the scenes. When several releases have a song, the newest release's version is the one listed and played.

## Nijiiro

Select a Nijiiro installation. It gets its own **Nijiiro** library; several installations merge into it, the newest version of a song first.

Nijiiro music needs **vgmstream-cli**, obtained separately from its [official releases](https://github.com/vgmstream/vgmstream/releases). Put it beside Waddamburo, in USRDIR, or in a **vgmstream** subfolder of either; a vgmstream-cli on your PATH works too. A song is decoded the first time it plays and kept in compressed form in the cache afterwards, so later plays start quickly.

Installations with encrypted data also need their decryption keys in **USRDIR/waddamburo/config.cfg**: set `nijiiro_datatable_key` and `nijiiro_fumen_key`, then add the installation again. Without them, or without vgmstream-cli, the installation is left out and its row says why.

## Wii and Wii U

The Wii and Wii U releases each get their own library, **WII** and **WII U**, with genre folders inside. All five Wii releases (the 2008 disc, ドドーンと2代目!, みんなでパーティ☆3代目!, 決定版, and 超ごうか版) and all three Wii U releases (Wii Uば～じょん!, 特盛り!, and あつめて★ともだち大作戦!) are supported. Their music plays with the built-in decoder: no vgmstream-cli needed.

**Wii:** extract the disc you own and select the extraction's folder. Any of these layouts works:

- the disc's **files** folder itself,
- a wit or nkit extraction, which has **game-1/files**,
- a Dolphin extraction (right-click the game → Properties → Filesystem → right-click the disc → Extract Entire Disc), which has **DATA/files**.

**Wii U:** select the game's folder in the decrypted layout Cemu uses, with **code**, **content**, and **meta** inside. Raw encrypted downloads (`.app` and `.tmd` files) cannot be read. The game's update and DLC songs are picked up automatically when they sit next to the game folder, or in Cemu's own **mlc01/usr/title** folders. Add only the game itself, not the update or DLC folders.

The Wii and Wii U libraries have a **Group** folder: **Genre** (the default) shows genre folders, **Game** a folder for each disc.

When several discs of one platform have a song with the same charts, it is listed once, from the newest disc. When they chart it differently, it is listed once per disc, with the release in its title, such as "(Wii Ketteiban)". A song on both a Wii and a Wii U disc appears in both libraries. A Wii ura song, marked （裏）, is a song of its own, as on the disc.

Wii and Wii U scores are official on TaikOnline. Where a Wii or Wii U chart is identical to the arcade one, it shares the arcade chart's leaderboard; where it differs, the song's page lets you switch to that version. In a search folder, Wii and Wii U songs use the white board style so you can tell where they come from.

## Find a song or difficulty

Press **Tab** in Song Select, type words from a title, subtitle, artist, or mapper, and press Enter. Results appear in a search folder. Escape closes the search field.

**Fast Song Scrolling** lets the list keep up with quick rim hits and the mouse wheel. Page Up and Page Down skip ten songs inside a folder. Turn on **Show Oni** to display Oni immediately; with it off, reveal Oni with repeated right-rim hits as in the arcade flow. **Random Song Spine** adds a spine that picks a random song each time you open it.

Scores follow the selected chart and course. A changed chart can have a different score history even when its title is unchanged.
