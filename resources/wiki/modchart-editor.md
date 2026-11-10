The modchart editor animates the gameplay presentation with keyframes. You can move the lane or other elements, change scale and rotation, turn Don-chan, and shape transitions with easing curves. These drawing effects do not change which notes must be hit or how they are judged.

## Open the editor

Start a normal **local single-player song in Home mode**, then press **F8**. You can enter directly during a song without first opening a replay. Online matches, two-player songs, Arcade, Dan courses, demos, and Waiwai do not support this entry.

**The current run becomes unranked as soon as editing starts.** Closing with F8 or Escape does not make it ranked again. Save your work, leave the editor, and restart normally to record another play.

For a fixed arrangement reused across songs, use the [Layout editor](layout-editor).

## Make your first animation

1. Choose an element from **Targets**, such as Don-chan.
2. Move the playhead to the beat where the change should begin.
3. With **Auto-key** enabled, move, rotate, or scale the element in the preview. This writes keys for the affected properties at the playhead.
4. Move the playhead to a later beat and set another value.
5. Play or scrub between those beats to preview the motion. Select a key and choose its easing curve to change how the transition arrives.
6. Press **Ctrl+S** to save.

The inspector lets you enter exact values. With Auto-key off, viewport edits require keys already present at that beat; if they are missing, the editor reports it instead of silently adding them.

## Timeline and curves

The timeline shows chart notes, long notes, measures, BPM and meter changes, delays, and branch markers. Select a reference branch to inspect its notes and snapping positions. This changes the editor's reference display, not the route being judged in live practice.

Expand tracks to show their curves. Drag a key to change its beat and value. Double-click a curve to add a sampled key; double-click a key to enter an exact value. Select Bezier interpolation in the inspector to expose curve handles. Named easing curves are also available.

Use the snapping selector for regular beat positions or **Chart notes**. Hold **Alt** to bypass snapping temporarily. Wheel zoom and middle-button pan move through the timeline; Shift plus wheel scrolls the track list. **File → Frame entire chart** fits the chart's extent.

## Copy and repeat motion

Select keys and use **Ctrl+C / Ctrl+V**, or the right-click menu. Copied keys retain relative beat offsets and interpolation. The key clipboard is internal to the editor; it is not a portable effects file.

**Repeat** creates additional copies of a selected motion at a chosen period. Check the copy count and interval before applying it. Collisions are rejected unless replacement is enabled. Undo reverses the operation as one edit.

Use **Ctrl+Z** to undo and **Ctrl+Y** to redo. Escape can cancel an active gesture; otherwise F8 or Escape requests closing, with a prompt if work is unsaved.

## Work alongside a TJA editor

Use **File → Open TJA** to open an external chart while keeping the running game as its preview. The editor watches the TJA for saves and reloads supported changes to notes, timing, courses, title, and audio. **Reload TJA now** requests a refresh manually.

The **Course** menu selects a course in that file. Effects are stored per course, so check the selected course before saving. An incomplete TJA save reports a problem while keeping the working preview; finish saving the chart and let it reload.

If a chart's effects come from chart commands rather than editable keyframe tracks, the editor shows a read-only preview. **Start keyframe modchart** explicitly replaces those effects with editable keyframes; Undo can restore the previous state.

## Save destinations

For a TJA with no existing player override, Save writes a `.wddmod` beside the TJA, such as **Example.tja → Example.wddmod**, preserving other courses in that file. For a stock chart, Save writes a player override inside **waddamburo/modcharts**, identified by the chart and course.

An existing player override takes precedence and can be the save destination even for a TJA. The **File** menu shows the actual save filename. Use **Reload saved effects** to return to the saved version, following the unsaved-changes prompt when needed.

## Share effects

For a stock chart, choose **File → Export effects** and share the resulting `.wddmod`. The recipient selects the same song and course, presses F8, chooses **File → Import effects**, then **Save**. Import changes the working document; Save installs it for future plays.

For your own TJA, distribute its adjacent `.wddmod` with the chart and audio you are authorised to share. A portable effects export contains the keyframe tracks, not original game assets. Custom files are local to the installation; signing into TaikOnline does not upload your editor project or layout library.
