A layout is a saved arrangement of the gameplay screen. Use it to move, rotate, resize, and reorder elements or position Don-chan. Its values stay constant through the song; use the [Modchart editor](modchart-editor) for animation over time.

## Create or select a layout

1. Start a normal local song in **Home mode**.
2. Press **Escape → Layouts**. For two local players, the menu says **Layouts (2 players)**.
3. Choose **New layout** to create a preset and open the editor. To change an existing preset, select its name and choose **Edit**.
4. To activate a saved preset without editing it, select it and choose **Use**. Choose **Stock layout** to return to the standard arrangement.

Layouts are available during ordinary local one- and two-player songs. The entry is unavailable for Arcade mode, online matches, Dan courses, demos, and Waiwai.

**Creating or editing a layout makes the current run unranked.** Save, close the editor, and restart normally when you want a recorded play. Using an already saved layout during a normal play does not by itself make that play unranked.

## Arrange the screen

Click an element in the preview or choose it in **Targets**. Select the Move, Rotate, or Scale tool, then drag the selection or its handles. Use the inspector for precise values. Don-chan also has yaw, pitch, and roll controls for turning the model.

Ctrl-click to select several elements and adjust their shared properties together. In a two-player layout, targets ending in `_p2` control the second lane. **Send back** and **Bring forward** change draw order; higher depth draws in front. If a large background gets in the way, select the target by name.

The bottom scrubber moves through the chart so you can check the arrangement against incoming notes. Layout mode has no animation timeline: the arrangement remains the same at every song position.

## Save and manage presets

Press **Ctrl+S** or **Save** to write the layout. Change the name in the top name field and press Enter to rename it. Undo and Redo let you reverse edits before saving.

**Reset layout** clears the arrangement in the editor. It is undoable and only changes the saved file when you save. To turn a preset off, choose **Stock layout** from the Layouts menu. To delete one, select its name, choose **Remove**, and confirm.

Single-player presets live in **waddamburo/modcharts/layouts/Name.wddmod**. Two-player presets live in **waddamburo/modcharts/layouts-2p/Name.wddmod**. The active choices are separate, so a layout made for one player does not replace your two-player arrangement. They belong to this installation, rather than following your TaikOnline account.

To share a layout, copy its `.wddmod` file into the matching layouts folder on the other installation, then select it in the Layouts menu. Keep one- and two-player presets in their corresponding folders.

## Fit a screen with a 4 to 3 aspect ratio

Enable **4:3 screen** in the layout editor, then arrange the elements for the narrower stage. The toggle adjusts the stage and element proportions; you can refine the positions, sizes, and backgrounds afterwards. Save the preset and check it at several points using the scrubber.

This is different from choosing a 4:3 display resolution, which can simply show the normal stage with letterboxing. The layout toggle changes how the gameplay stage itself fits the screen.

## Layouts and modcharts

For songs without their own drawing modchart, the active layout supplies the arrangement. A song with drawing effects normally keeps those effects instead of merging a regular 16:9 layout into them.

A **4:3 layout is the exception**: it can sit underneath authored effects so those effects still fit the narrower screen. Editing a modchart previews its own screen rather than baking your personal layout into the exported effects.

Replays display using the viewer's layout. A replay does not carry the original player's layout preset.
