In Home mode, open **Escape → Settings**. Rims or arrow keys move through the menu and change values; centre or Enter confirms. Leave the page to save, and follow the restart prompt for settings that need a new launch. In Arcade mode, use the service menu.

## Calibrate audio first

Use **Timing → Calibrate Audio**. Adjust until each click lines up with its note reaching the judgement circle. Use the left and right rims or arrow keys to adjust, then press Enter or hit both centres together to confirm. Escape leaves calibration. This sets **Audio Offset** for the sound device and display you are using.

A positive Audio Offset moves notes and judgement later to meet music heard late. A negative value moves them earlier when the music is heard early. Recheck after changing headphones, audio devices, buffers, or display settings.

## Adjust input timing

Once sound and picture line up, use familiar charts to check **Input Offset**. Keep the audio setup fixed while making small adjustments.

| Repeated timing result | Input Offset change                         |
| ---------------------- | ------------------------------------------- |
| Mostly LATE            | Increase the value: hits are judged earlier |
| Mostly EARLY           | Decrease the value: hits are judged later   |

The **Timing Indicator** glows blue for early hits and red for late hits. Choose whether it appears after Good/Bad judgements or after every hit. An offset can correct a consistent delay; it cannot fix unstable frame delivery or a drum that sends duplicate hits.

Timing changes apply from the next play or restart. Test with the same chart and equipment rather than changing both offsets together.

## Audio and volume

Master Volume scales everything. Music, Drum, Effects, and Don-chan Voice control their respective sounds; Menu and Attract Volume also let you quiet the game outside songs. Stereo Panning separates two players' sounds between speakers. Mute in Background quiets the game when its window is inactive.

A smaller **Audio Buffer** reduces latency. Raise it if sound crackles or cannot keep up, then restart. **Exclusive Audio** is a Windows option for letting the game use the audio device alone; it also needs a restart.

## Graphics and performance

**F11** switches between windowed and fullscreen display. Settings include borderless or exclusive fullscreen, resolution, refresh rate, VSync, and a frame limit. The frame limit applies to windowed and borderless modes.

Upscaling offers three levels:

- **Off:** render the game at 720p and scale to the screen; the lightest option.
- **Don-chan & Text:** render those elements at the display resolution.
- **Everything:** also upscale game textures on this PC and keep the results in a cache.

Resolution affects which upscaled texture sizes load for subsequent screens. **Background Upscaling** chooses how much CPU work to use while outside songs. **Bake All Textures** processes the cache ahead of time; stopping keeps completed work.

For a weak PC, start with Upscaling Off, a lower resolution, and a practical frame limit. Change one setting at a time. For a reportable performance problem, use **Record Performance** or **Run Benchmark** and include the generated report; the benchmark restarts into a roughly five-minute run before returning to normal startup.

## Cache and personalisation

**Cache Folder** selects the game folder, this PC's own cache, or a custom path configured in config.cfg. This PC is useful when the installation lives on removable storage. The cache location changes after restarting.

Language changes the menus; Song Titles chooses translated titles when available. Both need a restart. Gameplay skin chooses an automatic song theme, the original presentation, or a fixed available skin. Layout presets control element placement separately; see [Layout editor](layout-editor).
