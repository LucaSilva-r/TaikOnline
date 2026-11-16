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

## Browse osu! songs

osu!lazer and osu!stable are separate libraries. Only native osu!taiko beatmaps are listed; standard, catch, and mania maps are left out. Close osu! while troubleshooting a scan so its library is not changing underneath it.

Waddamburo uses Easy, Normal, Hard, and Oni as slots for osu! difficulties in star order. More than four charts produce additional song entries. Those slot names do not turn the beatmaps into the equivalent arcade difficulties: osu! charts use Oni judgement windows regardless of the displayed slot.

The library's **Group** and **Sort** spines change how songs are organised. Collections are available as a grouping option.

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

When several discs of one platform have a song with the same charts, it is listed once, from the newest disc. When they chart it differently, it is listed once per disc, with the release in its title, such as "(Wii Ketteiban)". A song on both a Wii and a Wii U disc appears in both libraries. A Wii ura song, marked （裏）, is a song of its own, as on the disc.

Wii and Wii U scores are official on TaikOnline. Where a Wii or Wii U chart is identical to the arcade one, it shares the arcade chart's leaderboard; where it differs, the song's page lets you switch to that version. In a search folder, Wii and Wii U songs use the white board style so you can tell where they come from.

## Find a song or difficulty

Press **Tab** in Song Select, type words from a title, subtitle, artist, or mapper, and press Enter. Results appear in a search folder. Escape closes the search field.

**Fast Song Scrolling** lets the list keep up with quick rim hits and the mouse wheel. Page Up and Page Down skip ten songs inside a folder. Turn on **Show Oni** to display Oni immediately; with it off, reveal Oni with repeated right-rim hits as in the arcade flow. **Random Song Spine** adds a spine that picks a random song each time you open it.

Scores follow the selected chart and course. A changed chart can have a different score history even when its title is unchanged.
