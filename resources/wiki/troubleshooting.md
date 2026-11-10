Start with the symptom below. Keep your game files, Waddamburo release, and selected library paths separate when checking a problem: an extra song library does not replace the Green data needed to draw the game.

## The game does not start

Check that Waddamburo is inside **USRDIR**, beside **data**, and that the base installation is Green Ver. On Linux, make the AppImage executable. Use the Windows x64 or Linux x64 release matching your system.

The startup screen shows loading checks. If one stops with **NG**, record the check name, error number, and message. A crash screen also gives a crash-log path. Keep that information when asking for help.

If you built from source and see an incompatible native-library ABI or a missing decoder message, use native libraries matching that source revision. Follow the [build guide](https://github.com/LucaSilva-r/Waddamburo-public/blob/master/BUILDING.md); changing song folders will not fix a native-library mismatch.

## A song or library is missing

Check **Settings → Song Libraries** for a rejected folder and its reason. Follow the restart prompt after changing libraries.

For TJA, verify the course declaration, positive BPM, and WAVE filename. Audio paths are relative to the chart and must remain inside the selected library. On Linux, filename case matters. Missing audio or an unsupported or incomplete chart can keep the song out of the catalog.

For osu!lazer, select its data folder and check the beatmap is native osu!taiko. Close lazer while retrying the scan. For Nijiiro audio, install vgmstream-cli separately as described in [Songs and libraries](song-libraries).

## Audio crackles or a song stops

Raise **Audio Buffer** and restart. Check Master and Music volumes, the system output device, and whether Mute in Background is active. A warning that song audio could not keep up means the play was stopped; include the session log if it repeats.

For consistent early or late timing, use [Settings and timing](settings-and-timing). For one hit registering twice, check the drum and then try a small Drum Debounce value. Increasing debounce too far also suppresses valid fast hits.

## Sign in or scores do not work

Confirm the server is configured and reachable, and that you accepted the current TaikOnline Rules. Generate a fresh code if the previous one expired. Check that the expected account is selected rather than Guest.

In Home mode, **Join with code** needs an account already signed in on that installation. In Arcade, the operator must configure a valid cabinet token; a Home login does not register a cabinet. Read [Home and Arcade modes](home-and-arcade).

Guest plays are not uploaded. Failed plays remain local. Auto Play, replays, practice, and a run that entered an editor do not create a normal ranked result. Closing the editor does not restore score saving for that run. Eligible offline plays for a remembered account can upload after reconnecting.

For room-specific failures, see [Accounts and online play](online-play). Check the current room code, password, both players' song/course availability, and the room banner's status.

## The editor is missing or a layout does not apply

**F8** requires a local single-player Home song. **Escape → Layouts** supports ordinary local one- or two-player Home songs. Neither editor opens during Arcade, online matches, Dan courses, demos, or Waiwai.

One- and two-player layout selections are separate. Check the matching Layouts menu and choose **Use**. A chart's authored drawing effects normally replace a regular layout; 4:3 layouts have a special fitting rule. See [Layout editor](layout-editor).

For modcharts, check the selected course and the save filename in **File**. Import is not a save: press Ctrl+S afterwards. An existing player override can take precedence over a TJA's adjacent file. Reload saved effects only after deciding what to do with unsaved work.

## Performance and logs

Lower upscaling and resolution first if drawing is slow. **Settings → Record Performance** writes measurements until disabled or the game closes. **Run Benchmark** produces a longer report through its own restart workflow.

Session logs are in **USRDIR/waddamburo/logs/latest.log**; the previous session is **previous.log**. Include the game version, operating system, Home or Arcade mode, affected song library type, steps to reproduce, and relevant error text when opening an issue on the [Waddamburo issue tracker](https://github.com/LucaSilva-r/Waddamburo-public/issues).

Review logs before sharing them, and remove personal paths or account information. Do not attach original game files, commercial fonts, accounts.json, cabinet tokens, or your local score database to a public report.

## Back up before changing files

Close the game and copy the **waddamburo** folder somewhere private. **config.cfg** contains settings, **scores.db** contains local results, **accounts.json** contains remembered login data, and **modcharts** contains saved effects and layouts. TJA effects may also live beside the chart, so include those files when backing up your own song projects.

Use **Stock layout** to disable a preset or **Reset layout → Save** to clear it. Avoid deleting the whole Waddamburo folder as a first troubleshooting step: that also removes accounts, local scores, and customisation.
