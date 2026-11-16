In Home mode, open **Escape → Settings**. Rims or arrow keys move through the menu and change values; centre or Enter confirms. Leave the page to save, and follow the prompt for settings that need a restart. In Arcade mode, use the service menu.

## Calibrate audio first

Open **Escape → Settings → Timing → Calibrate Audio** from Song Select; the entry appears when Settings is opened there, because calibration runs on the gameplay lane and returns to Song Select.

Don't drum along. Hit the **left rim** to move the notes earlier and the **right rim** to move them later, until each click lands as its note reaches the judgement circle. A **big don** (both centres) finishes. Escape leaves calibration. This sets **Audio Offset** for the sound device and display you are using.

A positive Audio Offset moves notes and judgement later to meet music heard late. A negative value moves them earlier when the music is heard early. Recheck after changing headphones, audio devices, output mode, buffers, or display settings.

## Adjust input timing

Once sound and picture line up, use familiar charts to check **Input Offset**. Keep the audio setup fixed while making small adjustments.

| Repeated timing result | Input Offset change                         |
| ---------------------- | ------------------------------------------- |
| Mostly LATE            | Increase the value: hits are judged earlier |
| Mostly EARLY           | Decrease the value: hits are judged later   |

The **Timing Indicator** glows blue for early hits and red for late hits. Choose whether it appears after Good/Bad judgements or after every hit. An offset can correct a consistent delay; it cannot fix unstable frame delivery or a drum that sends duplicate hits. For duplicate hits, see **Drum Debounce** in [Controls and drums](controls).

Timing changes apply from the next play or restart. Test with the same chart and equipment rather than changing both offsets together.

## Volume and sound

**Settings → Sound** holds the volumes, the sound options, and the **Advanced Audio** page; hitting the drum on it plays the drum's sound as a quick test.

Master Volume scales everything. Music, Drum, Effects, and Don-chan Voice control their respective sounds; Menu and Attract Volume also let you quiet the game outside songs.

- **Stereo Panning:** with two players, each side's sounds come from its own speaker.
- **Mute in Background:** fades the sound out while the window is in the background.
- **Drum Sound in Songs:** the don/ka sound of your own hits during a song. Turn it off to play with a silent drum; the menus keep their drum sound.

## Audio output

The **Advanced Audio** settings choose how the game reaches your sound device. Changing them asks **Apply Audio Settings?** when you leave the settings: **Apply Now** returns to the title screen with the new output, **Later** applies them at the next start.

| Setting          | What it does                                                                                                                                                                                            |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Output Mode      | Windows only. **Shared** plays alongside other programs; **Exclusive** takes the device for the game alone, for the lowest latency, and silences other programs; **ASIO** plays through an ASIO driver. |
| Output Device    | **System default** or a specific device (Shared and Exclusive).                                                                                                                                         |
| Audio Buffer     | Shared mode. Smaller is lower latency; raise it if the sound crackles.                                                                                                                                  |
| Exclusive Period | Exclusive mode. **Device minimum** is tested at start and falls back to **Device default** if it can't keep up; choose a larger size if it still stutters.                                              |
| ASIO Driver      | ASIO mode. Your interface's own driver, or ASIO4ALL; **First installed** picks the first one.                                                                                                           |
| ASIO Outputs     | ASIO mode. The output pair to play on, such as 3-4 for headphones on some interfaces.                                                                                                                   |
| ASIO Buffer      | ASIO mode. **Driver preferred** uses the size set in the driver's control panel; otherwise the nearest size the driver allows.                                                                          |

While Exclusive or ASIO output is running, Exclusive Period and ASIO Buffer also show the size the device actually took, in frames and milliseconds. Linux always uses the shared output; set its latency with Audio Buffer.

## Songs and Song Select

- **Fast Song Scrolling**, **Show Oni**, and **Random Song Spine** change how Song Select browses; see [Songs and libraries](song-libraries).
- **Auto Play** makes songs play themselves as a replay you can pause, seek, and speed up. Nothing is saved.
- **Arcade Timer & Song Number** shows the cabinet's timer, song number, and Free Play line. They don't count at home, and the game stays in Home mode.
- **Mouse Controls:** the wheel moves like the rims in menus and seeks in replays; a left click is a don.
- **Discord Status** shows what you're playing on your Discord profile while Discord runs on this PC.
- **Upload Custom Songs** uploads scores, replays, and charts for new TJA and osu! plays. Plays made while it is off stay local permanently, even after turning it back on.
- **Upload Disabled Banner** shows a line at the top when scores are not being uploaded (custom songs with uploads off, blocked charts, Auto Play).
- **Retry Hold** sets how long the retry key (Q) is held before the song restarts. **Hide tutorial folders** removes the tutorial folders at home.

## Graphics and performance

**F11** switches between windowed and fullscreen display. **Settings → Display** includes borderless or exclusive fullscreen, resolution, refresh rate, VSync, a frame limit, and the graphics options. The frame limit applies to windowed and borderless modes.

Upscaling offers three levels:

- **Off:** render the game at 720p and scale to the screen; the lightest option.
- **Don-chan & Text:** render those elements at the display resolution.
- **Everything:** also upscale game textures on this PC and keep the results in a cache.

Resolution affects which upscaled texture sizes load for subsequent screens. **Background Upscaling** chooses how much CPU work to use while outside songs. **Bake All Textures** processes the cache ahead of time; stopping keeps completed work.

For a weak PC, start with Upscaling Off, a lower resolution, and a practical frame limit. Change one setting at a time. For a reportable performance problem, use **Record Performance** or **Run Benchmark** and include the generated report; the benchmark restarts into a roughly five-minute run before returning to normal startup.

## Cache and personalisation

**Cache Folder** selects this PC's own cache, the game folder, or a custom path configured in config.cfg. This PC is useful when the installation lives on removable storage. The cache location changes after restarting.

Language changes the menus; Song Titles chooses translated titles when available. Both need a restart. Gameplay skin chooses an automatic song theme, the original presentation, or a fixed available skin. Layout presets control element placement separately; see [Layout editor](layout-editor).
