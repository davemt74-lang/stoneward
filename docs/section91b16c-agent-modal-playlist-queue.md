# 91B16C–D — Agent Modal and Persistent Playlist Ring

Stonefellow candidate version 1.3.206.

## Agent Canvas navigation

- The original particle orb, chat input, music player and atmosphere remain mounted and visible in the underlying Agent Canvas.
- Regular non-Home routes render with the existing application route functions inside an independent, body-level modal layer. The route is **not** a new page shell and does not use an iframe.
- The page grows from the orb and overlays the complete visual viewport, including the fixed header on iOS. The X at upper right, Escape and Home navigation close back to the unchanged Agent Canvas.
- The modal inherits the current atmosphere palette and styles native route modules as glass surfaces. If a route throws or renders nothing, a branded retry panel appears instead of leaving a blank destination.
- `?view=builder` is still a separate, full-page record studio.

## Playlist ring and queue

- The playlist icon is always present in the orb's orbital action ring, including immediately after a reload and with no song playing.
- Tapping the icon opens the right-side **Your Playlist / Up Next** drawer directly. For guests with no queue, the drawer offers **Create random playlist** rather than requiring sign-in.
- A generic spoken or typed command such as *make me a playlist* or *create a random playlist* builds a browser-owned, eight-song-or-fewer mix from the currently playable published catalog, begins playback when allowed, and opens the queue. This avoids model-driven navigation to a blank `/music` search page.
- The temporary playlist name, track IDs and current index are stored locally on that device and restored after reloading. It is **not** saved to the Stonefellow account and is removed when its playback context is explicitly replaced or the player is closed.
- The existing authenticated persistent Up Next queue and saved account playlists remain separate. The native `/music` catalog is still available through normal navigation.
- If a page modal is open when the queue is requested, the modal closes first so the queue can appear above the canvas.

## Deployment verification

1. Install the complete v1.3.206 package, then verify the version header. The service worker has a new versioned cache to prevent mixing old JavaScript and new CSS.
2. From the + menu select Music, Releases, Store and About. Confirm the canvas remains behind the full-viewport modal, content renders, and the X returns to the orb.
3. Open Create Record. Confirm `?view=builder` remains an independent page.
4. Reload on mobile and desktop. Confirm the playlist icon stays in the orb ring.
5. Say or type *make me a playlist*. Confirm the right-hand queue shows numbered songs without navigating to `/music`; tap a song to play it.
6. Reload on the same device. Confirm the temporary song list and selected index remain visible in the queue. Test an unauthenticated guest too.

## Local checks

- Full Node browser-source suite ran in the candidate workspace. PHP CLI and a live iPhone browser were not available; final production behavior must be checked after deployment.
