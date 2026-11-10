Waddamburo is an independent, open-source Taiko no Tatsujin simulator for Windows x64 and Linux x64. It uses a new engine to recreate the Gen3 arcade experience, with TaikOnline accounts, extra song libraries, and tools for customising the gameplay screen.

## What you need

Download the release for your operating system from [Waddamburo releases](https://github.com/LucaSilva-r/Waddamburo-public/releases). You also need your own legitimately obtained compatible game files. Game data and commercial fonts are not included.

Development and testing focus on **Taiko no Tatsujin Green Ver.** Other Gen3 versions are unverified as the base installation. Adding another version's songs is a separate feature; it does not make that version a supported base installation.

## Install and launch

1. Find your game installation's **USRDIR** folder, which contains a folder named **data**.
2. Place **Waddamburo.exe** on Windows, or **Waddamburo-x86_64.AppImage** on Linux, inside USRDIR, beside data.
3. On Linux, allow the AppImage to run as a program in your file manager's permissions. Alternatively, run the commands below from USRDIR.
4. Launch Waddamburo. Let the startup checks finish; **Space** can skip the startup screens in Home mode.
5. Hit a drum key to start, choose **Guest** or your account, and confirm with a centre hit. Use the rims to browse and a centre hit to select a song and difficulty.

```sh
chmod +x Waddamburo-x86_64.AppImage
./Waddamburo-x86_64.AppImage
```

The default Player 1 keys are **D / F / J / K**: rim, centre, centre, rim. Arrow keys and Enter also navigate menus. See [Controls and drums](controls) for remapping and two-player setup.

## Fonts

The bundled Mochiy Pop P One font works without extra setup. To use the original title font instead, place your legitimately obtained **DFPKanTeiRyu-XB** font at **USRDIR/data/font/font.ttf**, creating the font folder if necessary. Restart the game after adding it.

## Choose how to play

**Home** is the normal PC mode: free play, pause and restart, local accounts, extra song libraries, layouts, and editing tools. Choose Guest to play without signing in, or follow [Accounts and online play](online-play) to save eligible scores to TaikOnline.

**Arcade** is the cabinet mode, with credits, a service menu, and a different online login workflow. Read [Home and Arcade modes](home-and-arcade) before switching.

## Updates and your files

Release builds check for updates at startup and can download and restart into a newer release. Without a connection, the game starts normally. To disable automatic updates, close the game and set `auto_update = false` in `USRDIR/waddamburo/config.cfg`.

Waddamburo creates its own **waddamburo** folder inside USRDIR. Your settings, remembered accounts, local scores, layouts, logs, and caches live there. Keep this folder when updating. Close the game before copying it for a backup; remembered account files contain login information, so keep backups private.

For source builds and contributions, use the [build and development guide](https://github.com/LucaSilva-r/Waddamburo-public/blob/master/BUILDING.md).
