Waddamburo also runs on Android phones and tablets and, as homebrew, on a modded Nintendo Switch. It is the same game as on a PC: the same songs, accounts and online play, with the differences below.

## Android

1. Download **Waddamburo.apk** from [Waddamburo releases](https://github.com/LucaSilva-r/Waddamburo-public/releases) and install it. Your phone may ask you to allow installs from your browser or file manager first.
2. Start Waddamburo. Without game files it opens the setup, which offers two ways to get them:
   - **I downloaded it on this phone:** pick your dump (one .zip, .7z or .rar file, usually in Downloads). Don't extract it first: Waddamburo unpacks it and copies only the files the game needs. Keep the game open while it imports; a big dump takes 10 to 30 minutes. You can delete the download afterwards.
   - **Connect to a PC:** receive the game files from Waddamburo on your PC (see [Sync songs from your PC](#sync-songs-from-your-pc)).
3. The game starts by itself when the files are in place.

If an import goes wrong, the setup says why and writes a report in `Waddamburo/logs`; send it in #bug-reports on Discord if you need help.

### Your files on the phone

The game keeps everything in its own folder, `Android/data/org.waddamburo.game/files`: **Green** (the game files), **Custom Songs** (your TJA library), the official song libraries (**Arcade**, **Wii**, **Wii U**), and **Waddamburo** (`config.cfg`, logs and screenshots). No storage permission is needed.

Since Android 11 most file managers can't open `Android/data`. Use **Game Files** in the pause menu outside songs (title screen, Song Select): it opens the folder in the system's file screen. The folder also appears as a **Waddamburo** location in any app's file picker. From a PC, connect the phone by USB and copy files in with the file manager.

Phones have no **Song Libraries** page: libraries arrive by [sync from your PC](#sync-songs-from-your-pc), Nijiiro included (its audio is converted on the PC), or by copying them into the folders above.

### Touch drum

On a touchscreen a drum is drawn at the bottom of the screen: its face is Don, anywhere else is Ka. In menus the screen shows touch buttons. Change it in **Settings → Drum Controls**:

| Setting | What it does |
| --- | --- |
| Touch Drum | On, Off, or Auto (shown while you use the touchscreen, hidden with a keyboard, controller or drum) |
| Touch Drum Size | The drum face's size: smaller leaves more Ka area around it |
| Touch Drum Layout | **Single**: one drum at the bottom. **Split**: two Don circles you place yourself; anywhere else is Ka, left or right by the side of the screen you hit |
| Edit Touch Drums | Split layout: drag a circle to move it, its blue knob to resize it. Back saves |
| Reset Touch Drums | Puts the two Don circles back where they started |

### Performance on phones

Phones vary a lot. If a phone stutters, turn **Settings → Game Options → Background Video** off (or set **Video Frame Rate** to 30) and keep upscaling off. Don-chan renders at 60% resolution on phones by default; `don_resolution` in `config.cfg` (25 to 100) changes it.

## Nintendo Switch

Waddamburo runs as homebrew on a Switch with Atmosphère. Launch it with **title takeover** (hold **R** while starting a game, then open hbmenu), not from the album applet: only title takeover gives it the memory it needs.

1. Download **Waddamburo.nro** and copy it to the SD card, in `/switch/` (for example `/switch/taiko/Waddamburo.nro`).
2. Your game files go in `/switch/taiko/USRDIR`, laid out as on a PC (with the **data** folder inside). Without them, the first start opens the setup, which can receive them from Waddamburo on your PC (see below).
3. Start Waddamburo from hbmenu.

The HOME button pauses a song, as on the original hardware. Text fields open the console's keyboard with **A**.

### Buttons

The defaults put Don and Ka in the same places as on a PC controller:

| Pad | Buttons |
| --- | --- |
| Left Ka | D-pad left, L, ZL |
| Left Don | D-pad down, D-pad right, left stick press |
| Right Don | B, Y, right stick press |
| Right Ka | A, R, ZR |

In menus, **A** confirms and **B** goes back. Rebind them in **Settings → Drum Controls** like any controller (see [Controls and drums](controls)). Drum controllers recognised as a controller work too.

### HOME menu icon

**Settings → Others → HOME Menu Icon** makes `/switch/taiko/Waddamburo.nsp`, an icon that starts Waddamburo from the HOME menu with the full memory, without hbmenu. It needs your console's keys at `/switch/prod.keys` (dump them with Lockpick_RCM from hekate). Install the NSP with DBI, Tinfoil or Goldleaf (sigpatches needed). Make it again if you move the .nro.

### On the Switch

- Background videos up to 1080p60 play on the console's video hardware.
- Libraries come by [sync from your PC](#sync-songs-from-your-pc) (Nijiiro included: its audio is converted on the PC) or copied to the SD card and added in **Settings → Song Libraries**.
- The Switch build is distributed under the GPL (its FFmpeg build includes GPL hardware decoding); the other builds are not affected.

## Sync songs from your PC

A phone or Switch can mirror song libraries from Waddamburo on your PC over your home network. The PC keeps every library; the other device downloads the ones you choose and keeps them identical.

1. On the phone or Switch: **Settings → Devices → Connect to a PC**. It shows a code.
2. On the PC: **Settings → Devices → Add a device**, choose the device, and enter the code. You pair only once.
3. On the phone or Switch, open the PC under **Sync Songs**, turn on the libraries you want, and choose **Sync Now**. You can keep playing while it runs (**Continue in the background**).
4. Restart the game when it says Done to see the changes.

A library the device can't use is marked **can't take it**. Sync is a true mirror: songs removed on the PC are removed on the device at the next sync, and turning a library off removes it after asking. A sync that would remove more than half of a library asks first. Nijiiro audio is converted on the PC before it is sent.

Waddamburo must be running on the PC, on the same network. Windows asks once to allow Waddamburo through the firewall; on Linux, allow port **8381** (TCP and UDP) if the device does not find the PC.
