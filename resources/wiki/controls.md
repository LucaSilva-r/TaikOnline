Each player has four drum pads. Don hits the centre; Ka hits the rim. The left and right pads can be bound separately, even when they make the same sound.

## Default drum keys

| Pad                 | Player 1 | Player 2 |
| ------------------- | -------- | -------- |
| Left rim or Ka      | D        | Z        |
| Left centre or Don  | F        | X        |
| Right centre or Don | J        | C        |
| Right rim or Ka     | K        | V        |

In menus, the rims move and a centre hit confirms. Arrow keys and Enter also work. With Mouse Controls enabled, the wheel moves through menus and a left click acts as a Don.

The second player can join from Song Select by hitting their drum. In Home mode, each side can choose its own account or Guest.

## Shortcuts in Home mode

These are the defaults; the buttons beside the drum can be rebound in **Settings → Drum Controls → Buttons**. The same button can have different jobs on different screens, and the on-screen hints follow your bindings.

| Where                    | Default             | Action                                              |
| ------------------------ | ------------------- | --------------------------------------------------- |
| Menus or a song          | Escape              | Open the menu; pause a local song                   |
| Anywhere                 | F11                 | Switch fullscreen and windowed display              |
| Startup                  | Space               | Skip startup screens                                |
| Song Select              | Tab                 | Search songs                                        |
| Song Select              | L                   | Open or close scores and rankings, in single-player |
| Song Select              | P                   | Toggle a favourite                                  |
| Song Select              | Page Up / Page Down | Skip songs with Fast Song Scrolling enabled         |
| Song or results          | Hold Q              | Restart the song; the hold duration is configurable |
| Local single-player song | F8                  | Open the modchart editor; this run becomes unranked |
| Gameplay                 | F12                 | Save a screenshot in the local screenshots folder   |

See [Practice and replays](practice-and-replays) for playback and loop controls. Escape works as a **test switch** in Arcade mode; see [Home and Arcade modes](home-and-arcade).

## Bind a keyboard or drum

1. Open **Escape → Settings → Drum Controls** in Home mode.
2. Choose **Player**, then **Device**: Keyboard, Controller, or MIDI.
3. Select a pad and press Enter, then press the key, controller button, trigger, or MIDI note you want to use.
4. Repeat for the other pads, or choose **Set Up All Pads** to bind all four in order.

Capturing an individual pad adds a binding. Capturing the same input again removes it; an input already on another pad moves to the selected one. Set Up All Pads replaces that device's bindings for the four pads. Other device types keep their bindings. Escape cancels capture, and **Reset to Default** restores that player's defaults for the selected device.

Keyboard bindings use physical key positions, so the labels may differ on another keyboard layout. Enter, arrows, and keys needed by the menu cannot be assigned as drum pads.

## Controllers and MIDI

For an ordinary gamepad, **In Menus: Gamepad** uses the D-pad to move, the bottom face button to confirm, and the right face button or Start to go back. The drum bindings then apply during gameplay.

For a drum recognised as a controller, select **In Menus: Drum** so the bound pads navigate as well as play. Check the controller number if you reconnect several devices: controllers take the lowest available slot.

MIDI bindings accept note-on messages from connected MIDI inputs, on any channel. Select MIDI when capturing them. A MIDI hit is a press; it does not depend on holding the note.

## Double hits and debounce

If one physical hit produces two inputs, first check the drum's own settings. **Settings → Drum Debounce** makes each pad ignore inputs arriving too soon after its previous accepted hit. It is off by default.

Use the smallest value that fixes the unwanted repeats. Debounce also limits intentional rolls: a value of 20 ms allows at most 50 hits per second on one pad. Each pad has its own limit; the menu displays the resulting two-hand roll limit.
