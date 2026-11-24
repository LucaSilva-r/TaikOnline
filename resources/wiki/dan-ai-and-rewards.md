Besides normal play, Waddamburo has Green's Dan Dojo and AI battle, your own dan courses, and Green's rewards: Don Medals, the reward shop and titles.

## Dan Dojo

Open the **Dan Dojo** folder in Song Select. Each series is a dojo year: Green's own courses, the earlier arcade lists Green's data carries, and, with a Nijiiro library, Nijiiro's yearly courses. A course plays its songs back to back on one soul gauge, with the requirements shown on its board (soul gauge, Goods, OKs, Bads, combo, hits, score or rolls). Pass at the red values for a red pass, at the gold values for a gold pass.

A requirement that can no longer be met (too many OKs or Bads, a broken combo requirement) ends the course after the current song. Gauge, score, roll and hit requirements are judged at the end.

A course whose songs are not all in your libraries is shown locked. Signed-in results upload to TaikOnline, where each series shows your passes and every run has its own page.

## Your own dan courses

You can build your own dan series from any songs in your libraries. Every `.json` file in the **Dani** folder of your TJA library (`custom_songs/Dani` by default) is one series; it appears in the Dan Dojo folder after the official series. Changes are read when the game starts.

The game writes two files into that folder at every start: **README.txt**, the full reference, and **Example.json.txt**. Copy the example, rename the copy to something like `My Dojo.json`, and edit it in any text editor. Comments (`// ...`) and trailing commas are allowed.

Custom courses are unranked: they are never uploaded to TaikOnline and earn no Don Medals. Your results are kept on your computer and show on the course boards (red or gold pass) like any course's.

### The file

```json
{
  "name": "My Dojo",
  "courses": [
    {
      "name": "五段",
      "songs": [
        { "path": "Vocaloid/Senbonzakura/Senbonzakura.tja", "course": "oni" },
        { "path": "Pop/Some Song/Some Song.tja", "course": "hard" },
        { "hash": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef", "title": "Third song (Oni)" }
      ],
      "requirements": [
        { "type": "soul_gauge", "red": 98, "gold": 100 },
        { "type": "goods", "red": [600, 700, 800], "gold": [700, 800, 900] },
        { "type": "bads", "red": 40, "gold": 20 }
      ]
    }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `name` (series) | The series' name in the Dan Dojo folder. Optional: the file name otherwise. |
| `courses` | The courses, in the order they are listed. |
| `name` (course) | A dan rank (初級, 十級 to 一級, 初段 to 十段, 玄人, 名人, 超人, 達人) uses that rank's board and art. Any other name ("Speed Run", "Challenge 1"...) makes a challenge course, its name written on the challenge plates. |
| `songs` | The songs, played in this order. |
| `requirements` | What passing asks. |
| `rules` | Optional: `"nijiiro"` uses Nijiiro's soul gauge instead of Green's. |

### Songs

Each song is one of:

- `{ "path": "Vocaloid/Senbonzakura/Senbonzakura.tja", "course": "oni" }`: a chart of your TJA library, by its path from the TJA library's folder, and its course: `easy`, `normal`, `hard`, `oni` or `ura` (`oni` when left out).
- `{ "hash": "..." }`: any chart of any of your libraries (official songs, TJA, osu!, Wii...) by its **chart hash**. A hash names one course of one chart, so no course is needed.

Either may add `"title": "..."`, the name shown while the chart is missing from your library. A course whose songs are not all in your library is shown locked.

### Chart hashes: press H

A chart hash is Waddamburo's identity for a chart, the same one TaikOnline uses for scores: a fingerprint of how the chart plays for one course (its notes and their timing, rolls, balloons, BPM and scroll changes, Go-Go time, bar lines, branches, level and scoring). It is not the hash of the file: renaming, moving or re-tagging the file keeps it, and the same chart in two formats or libraries has the same hash. Changing anything about how it plays gives a new hash.

To get one, in Song Select put the cursor on a song (or open its course select) and press **H**. Its charts are copied to the clipboard, one line per course, ready to paste into `"songs"`:

```json
{ "hash": "3f6c...e91a", "title": "Senbonzakura (Oni)" },
```

Keep the line of the course you want. The first time a file names a hash the game has not seen, it works out the whole library's hashes once, in the background (a large library takes a while), then shows the course. H is a keyboard key; the Switch has no clipboard, so make dan files on a PC.

### Requirements

```json
{ "type": "goods", "red": 900, "gold": 1000 }
```

`red` is what passing asks, `gold` what a gold pass asks. Each is either one number for the whole course, or a list with one number per song (`"red": [300, 300, 400]`): then each song must meet its own number on its own, and a song that misses ends the course after it. 0 means no requirement (for that song, or at that level).

| Type | Counts | Passing means |
| --- | --- | --- |
| `soul_gauge` | The soul gauge at the end, in percent | At least the value |
| `goods` | 良 (Good) hits | At least the value |
| `oks` | 可 (OK) hits | Fewer than the value |
| `bads` | 不可 (Bad) hits and misses | Fewer than the value |
| `combo` | The longest combo | At least the value |
| `hits` | 良 + 可 + roll hits | At least the value |
| `score` | The score | At least the value |
| `rolls` | Roll and balloon hits | At least the value |

`oks` and `bads` fail as soon as they reach their value; `goods` and `combo` fail once the notes left cannot reach it. The course then ends after the current song. The others are judged at the end.

The course panels have room for three requirements. With more, the soul gauge is left off the panel (the gauge bar shows it anyway); every requirement still counts.

A course's results belong to its exact content: changing its songs or requirements makes it a new course, and its board starts again with no result. A file the game cannot read is skipped; `waddamburo/logs/latest.log` says why, on lines starting with `Custom dan:`.

## AI battle

Choose **AI battle** on the mode select to play against Green's AI Don-chan, song by song. You win a song by beating the AI on more of its sections. Wins and rank points raise your AI battle rank; the farewell screen at the end shows your rank, the points to the next rank and the wins to the next reward.

The AI plays perfectly until your first OK or Bad. It learns your timing from your own replays, so it plays like you.

Your AI battle progress comes from your saved plays. Signed in, battles upload to TaikOnline (offline battles too, when the account reconnects) and the server checks each one by replaying it.

## Don Medals and the reward shop

Signed-in plays earn **Don Medals**, as in Green: per credit in Arcade mode, and for every three songs in Home mode, with Green's daily, weekly and monthly bonuses on top. A notice shows what you earned. Medals reset each season.

Spend them in the **reward shop** at the end of a credit (ごほうびタイム): Green's season goods, plus a weekly selection (changing on Mondays) of goods you can't otherwise earn.

## Titles

Plays earn Green's titles by their conditions (songs played, courses, results), checked at the end of each credit. Wear one on your TaikOnline Don-chan page. Wearing an official title you have not earned does not earn it.

## Only what I earned

By default your account only has what you earned, as a fresh card would: locked songs, tones, costumes and puchi charas stay locked until you unlock them through medals, the shops or events. Custom songs never lock. To unlock everything instead, change it on your TaikOnline **Don-chan** settings page.

## Katsu Medals, the Katsu Shop and the gacha

Every card play on a credit also earns **Katsu Medals**: 100 a credit. They never reset. Spend them on TaikOnline (sign in, then the Waddamburo menu):

- **Katsu Shop:** earlier versions' goods, any of them at any time: Green's Katsu songs (1,500 medals), costumes, tones and Katsu puchi charas (500 each). What you buy is yours at your next credit.
- **Title parts gacha:** a banner for each title gacha Green's predecessors ran, all of them available. A pull costs 20 Katsu Medals and gives one part of that banner; pull 1 or 5 at a time. You can draw a part you already own (no refund).
- **Title builder:** on your Don-chan page, join two parts with a connector (part + connector + part) into a title of your own, as on Donder Hiroba.

## Events

Green's collaboration campaigns come back as **events**, one at a time, two weeks each, in a fixed rotation that repeats: Touhou Project, THE IDOLM@STER, Puzzle & Dragons, Cinderella Girls, crossbeats REV., Love Live!, #Compass, Macross Δ, SideM, A3!, EXILE, Shiny Colors and Taiko Docodon. The running event has its own folder in Song Select, with its songs and its original folder art.

- While an event runs, every card play of one of its songs (normal play or AI battle, Green's or Nijiiro's chart) earns **one ticket**.
- Spend tickets on that event's puchi charas on TaikOnline's **Events** page: 5 tickets each, 10 for the ones that were raffle prizes. The page shows the event, its songs, your tickets and when it ends, in your time.
- A few puchi came from playing one song during the campaign (SideM, crossbeats REV.): play it while the event runs and the puchi is yours.
- Tickets you don't spend carry over to that event's next run; what you bought stays. Plays before the events started don't count.

Event puchi are locked in **Only what I earned** until you get them.
