# Section 91B13A — native Agent Canvas page rail

The public Agent Canvas quick-link menu now opens as a slim vertical stack of mesh-overlay links. Clicking **Create Record** executes the existing `navigate('builder')` action, which opens the canonical `?view=builder` route as a **full-page studio**, outside the left rail. The Agent Canvas is cleanly unmounted first, returning the single audio player to its normal footer; returning Home remounts the Agent Canvas.

The rail is native DOM, rendered by existing Stonefellow page functions into `#sfAgentNativePage`. It does **not** embed an iframe, clone the app, or load a separate site. Interactive workflows (catalogue, show and store actions, forms, playlist states) therefore keep their original event handlers and data access. The single audio player stays with the orb and footer lifecycle. Closing the panel restores the agent canvas and Home URL; browser back/forward can reconstruct a deep-linked native page in the panel.

## Visual design

- 354px desktop / up to 340px mobile viewport width, inset from the left, with one vertical content scroller.
- Deep translucent navy finish, cyan/violet gradient illumination, mesh and monospaced status labeling.
- Native pages reflow to one column, with compact controls and cards. Controls and content are restyled only inside this rail; ordinary full-page routes keep the published theme.
- All actions remain keyboard operable; the rail has a named page region, close control, and reduced-transparency support.

## QA

Run `node tests/section91b12e-lyric-interpretation.cjs`, `node tests/section91b13a-native-page-rail.cjs`, and the GitHub release gate. Confirm Create Record opens `?view=builder` full screen, add a song to a draft, return Home, open Store in the left rail, close the rail, and verify music playback and lyric capture remain available.
