Your base installation supplies the game's scenes and songs. In Home mode, you can add more song libraries without replacing the base game data.

## Add a library

Open **Escape → Settings → Song Libraries** and select the folder for the library you want to add. Follow any restart prompt so the game can scan the new selection. Keep the source files available while playing: adding a folder does not copy its whole library into Waddamburo.

| Library             | What to select                                        | What appears                                    |
| ------------------- | ----------------------------------------------------- | ----------------------------------------------- |
| Custom TJA          | Your TJA library folder                               | Supported TJA charts and their referenced audio |
| osu!lazer           | Your osu!lazer data folder                            | Native osu!taiko beatmaps                       |
| Official game songs | A compatible game data folder or Nijiiro installation | Songs the selected installation can provide     |

Extra libraries are a Home feature. Arcade mode uses its own game's library for the cabinet experience.

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

UTF-8, UTF-16 with a byte-order mark, and Shift-JIS charts are supported. Files sharing the same audio can be grouped as one song with several difficulties. A malformed chart or missing WAVE file can keep a song out of the browser; see [Troubleshooting](troubleshooting).

TJA compatibility varies by command and simulator extension. Do not assume every gimmick from another simulator behaves identically. Effects authored in Waddamburo can be stored beside a TJA in a `.wddmod` file; see [Modchart editor](modchart-editor).

## Browse osu!lazer songs

Only native osu!taiko beatmaps are listed; standard, catch, and mania maps are left out. Close osu!lazer while troubleshooting a scan so its library is not changing underneath it.

Waddamburo uses Easy, Normal, Hard, and Oni as slots for osu! difficulties in star order. More than four charts produce additional song entries. Those slot names do not turn the beatmaps into the equivalent arcade difficulties: osu! charts use Oni judgement windows regardless of the displayed slot.

The library's **Group** and **Sort** spines change how songs are organised. Collections are available as a grouping option.

## Add official song libraries

Use your own legitimately obtained files. Selecting another game's song folder expands the catalog; the Green base data still supplies the scenes. When several official libraries contain a song, the catalog merges those installations and prefers the newer available version.

Nijiiro music additionally needs **vgmstream-cli**, obtained separately from its [official releases](https://github.com/vgmstream/vgmstream/releases). Put it beside Waddamburo, or in a **vgmstream** subfolder beside it. If a library is rejected, read the reason in Song Libraries; an installation that cannot provide readable songs or audio will be left out.

## Find a song or difficulty

Press **Tab** in Song Select, type words from a title, subtitle, artist, or mapper, and press Enter. Results appear in a search folder. Escape closes the search field.

**Fast Song Scrolling** lets the list keep up with quick rim hits and the mouse wheel. Page Up and Page Down skip ten songs inside a folder. Turn on **Show Oni** to display Oni immediately; with it off, reveal Oni with repeated right-rim hits as in the arcade flow.

Scores follow the selected chart and course. A changed chart can have a different score history even when its title is unchanged.
