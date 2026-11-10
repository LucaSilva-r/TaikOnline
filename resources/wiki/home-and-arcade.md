Home and Arcade are two play modes in the same engine. Choose Home for normal PC play and its customisation tools. Choose Arcade for a cabinet's credit, entry, and service-menu flow.

## Compare the modes

| Feature                     | Home                                                | Arcade                                                 |
| --------------------------- | --------------------------------------------------- | ------------------------------------------------------ |
| Sessions                    | Free play without cabinet countdowns                | Free play or coins, credits, and songs per session     |
| Escape                      | Pause or open the Home menu                         | Test switch: stops the game and opens the service menu |
| Player login                | Remembered accounts, Guest, or Join with code       | Card or cabinet pairing for the upcoming credit        |
| Online credentials          | Each player's account token                         | An operator-issued cabinet token                       |
| Extra song libraries        | TJA, osu!lazer, and additional official libraries   | The cabinet's own game library                         |
| Online rooms                | Quick Play, public rooms, and joining by code       | Unavailable                                            |
| Layouts and editing         | Named layouts and local editing tools               | Player layouts and editors are unavailable             |
| Practice and replay browser | Available in the supported local single-player flow | Unavailable                                            |

The **Arcade Timer & Song Number** display option in Home mode changes the HUD. It does not switch the game to Arcade mode or start charging credits.

## Switch modes

Close the game, open **USRDIR/waddamburo/config.cfg**, and set:

```ini
mode = home
```

or:

```ini
mode = arcade
```

Start Waddamburo again. Settings are saved in the same configuration file, but some features apply only in one mode.

## Home online accounts

Choose **Sign in** on the player-select screen and authorise the displayed code on TaikOnline. Waddamburo remembers that account on this PC. Each player in local two-player mode can choose a separate account.

**Join with code** is for a visitor using a PC that already has a signed-in account. The visitor's login stays in memory for that session instead of being added to the remembered accounts.

Home stores Guest plays locally, but does not upload them to an account. Playing offline under an already remembered account can queue eligible plays for upload when that account reconnects. Guest history is not automatically assigned to a later login.

## Arcade online setup

Arcade online play needs both the server setting and a valid **cabinet token issued by a TaikOnline administrator**. Changing `mode` alone does not register a cabinet. Operators obtain a token through TaikOnline's Waddamburo cabinet administration and configure `cabinet_token` in config.cfg. Keep that token private.

While the attract loop is accepting players, an online cabinet displays a pairing code. Sign in on TaikOnline, open **Play**, enter that code, and wait for your name to appear on the cabinet. Start the next credit promptly: pairing is temporary and is consumed for that credit. A supported card reader can provide the player identity instead.

The cabinet sends eligible results using its cabinet credentials; players do not leave remembered Home logins on it. This is separate from Home's online rooms: a registered Arcade cabinet does not gain Quick Play or peer-to-peer matches.

## Credits and the test switch

**F2** inserts a coin and **F3** adds a service credit by default. The operator can configure free play, credits per coin, player costs, and songs per session in the service menu.

**Escape is the test switch in Arcade mode.** It stops the current game, drops the credits, and opens service settings. Choose **EXIT** to return to the attract loop. It is not a way to pause a song and resume the same play.

## What gets saved

Normal Home plays are saved locally, including Guest and failed plays. Failed plays stay local; replays, Auto Play, practice, and runs that enter an editor do not create normal ranked results. Eligible signed-in clears can upload to TaikOnline. In Arcade, local score storage and upload depend on online cabinet configuration, and the player must be identified for an account result.

A saved layout or drawing effect changes presentation, not note judgement. Using it in a normal play is different from opening an editor during that play, which makes the current run unranked. Read [Layout editor](layout-editor) and [Modchart editor](modchart-editor) for their save workflows.
