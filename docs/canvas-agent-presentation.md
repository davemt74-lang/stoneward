# Agent presentation on custom canvases

The orb stays visible while its ring navigation, status, chat preview, welcome actions, voice start, player and attached session controls share the existing 5.2-second inactivity fade. Their layout space stays stable so fading does not move the orb. Hidden controls are inert. The page footer composer and Coding Agent are independent.

A click or tap reveals the controls. A double click or double tap enables listening using the existing microphone/start-session path; an active session stays active. Keyboard focus also reveals controls, and the visible Start Voice Chat button provides keyboard activation. Hover alone does not reveal hidden controls. Dragging and canceled gestures do not start listening. Focused controls, lyric selection, recording, the guided tour and the expanded workspace prevent the inactivity fade. Explicit /hide-agent and /show-agent remain separate visibility commands. Both inactivity fading and explicit hiding affect presentation only: microphone listening, spoken replies, playback and canvas code continue according to their existing session settings.

Custom canvas output may include `agent_palette` with exactly `accent`, `secondary` and `surface`, each a six-digit hex color. It is validated as data and stored with the private canvas history; revisions inherit it unless the model replaces or clears it. The existing `agent_design` enum remains independent.

The client uses the saved palette first, then approved color descriptions for images actually used by the background or scene, then literal colors from the authored CSS. It adjusts contrast and applies only the host's color variables to the orb and attached controls. The particle drawing and CSS use the same colors. Closing the canvas clears these overrides and restores the selected atmosphere.

Approved image color, style and scene metadata is provided to the coding prompt and reopened image bindings through the existing account-authorized inventory. This does not start new image analysis or add generated controls to a canvas. Missing metadata leaves the existing atmosphere as the final fallback.

Canvas image clicks and key presses belong to the authored experience. To edit or replace a placed image, open **Edit images** in the footer and choose the image. Coding Agent image previews still open a larger preview with explicit Edit and Replace controls.

The entire floating agent group scales together, including the orb, ring navigation, status, chat preview and attached player/voice controls: idle 50%, thinking 75%, and active listening/speaking/music/recording 100%. Explicit control engagement expands an idle orb until the controls fade again. Navigation targets scale with the group (22px at idle, 33px while thinking, 44px when engaged); activating an idle orb expands the group to full size. Listening adds a gentle pulse.

The orb roams smoothly on the basic and custom canvases, including mobile. Its travel area reserves the attached controls' geometry so revealing them does not move the orb away between taps. Actual header/footer rectangles bound travel, and resize/layout changes recheck the bounds. Hover, keyboard focus, dragging, capture controls, tours and open workspaces pause travel; it resumes from the current position. Reduced-motion preference disables continuous travel and pulses while preserving state sizes.

Whole-group scaling is anchored at the orb center, so the first tap does not move its target before a second tap. Motion bounds reserve the full-size footprint and normalize measured positions during scale transitions.
