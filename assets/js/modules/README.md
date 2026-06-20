# Doniixify JS modules — migration plan

Currently `assets/js/app.js` is a single 4100+ line IIFE. This folder is the target for a progressive
split into focused modules. Migration is **not done yet** — this README documents the strategy so it
can be executed safely later.

## Strategy: progressive extraction with `window.__*` API surface

Each module:
1. Lives in its own IIFE (`(function () { 'use strict'; ... })()`)
2. Exports needed cross-module functions on `window.__*` namespace
3. Does NOT rely on closure-captured variables from the parent IIFE
4. Loaded via separate `<script>` tags in the right order

PHP `Layout.php` will render an ordered list of `<script src="/assets/js/modules/XX-name.js">` tags
OR a single concatenated `<script src="/assets/js/app-bundle.js">` (server-side concat for prod).

## Module breakdown (ordering by dependencies)

| # | File | Responsibility | Approx lines | Exports |
|---|------|----------------|--------------|---------|
| 00 | `00-bootstrap.js` | platform detection, CSRF, ICONS, `__platform` | 200 | `__platform`, `__csrfFetchPatched`, `__parseLrcBasic`, `__smartShuffle`, `__queueRetry`, `__viewTransition`, `__extractDominantColor`, `__applyAccentColor` |
| 10 | `10-audio-core.js` | `loadSong`, audio events, MediaSession, `setMetadata`, queue state | 600 | `__currentTrackMeta`, `doniixify` global |
| 20 | `20-ui.js` | dialogs, modals, toast, sidebar, volume | 400 | `__dialogAlert`, `__dialogConfirm`, `__dialogPrompt`, `__showToast`, `__closeSidePanel` |
| 30 | `30-lyrics.js` | parseLrc, renderLyrics, tickLyrics, fullscreen | 500 | `__openLyricsFullscreen`, `__loadLyrics` |
| 40 | `40-queue.js` | renderQueue, drag-drop, smart radio | 400 | (queue array shared via `doniixify.queue`) |
| 50 | `50-devices.js` | heartbeat, devices panel, jam | 500 | `__heartbeat`, `__refreshDevices` |
| 60 | `60-navigation.js` | SPA navigate, prefetch, history | 300 | `__navigate`, `__pageCache`, `__prefetchPath` |
| 70 | `70-context-menu.js` | long-press sheet, popup, playlist add | 400 | `__refreshSidebarPlaylists` |
| 80 | `80-screensaver.js` | butterchurn visualizer, idle detection | 400 | `__getScreensaverEnabled`, `__setScreensaverEnabled`, `__audioCtx`, `__audioSrcNode` |
| 99 | `99-main.js` | glue: event listeners, init, audio settings, restore state | 500 | — |

## Risks

1. **Closure captures** — many helpers in app.js access shared state (`audio`, `queue`, `queueIndex`,
   `currentSongId`, `ui`) which is closure-scoped in single IIFE. Extracting to separate IIFEs
   requires either:
   - Moving shared state to `window.doniixify = { ... }` namespace
   - Or passing as init args from glue layer
2. **DOM lifecycle** — some listeners attached only once (audio events). Re-init on SPA navigation
   must not double-bind.
3. **Order of initialization** — `30-lyrics.js` calls `karaokeWordIdx` from `00-bootstrap.js`. If
   bootstrap fails to load, lyrics break silently. Need feature detection guards.

## Execution checklist (when ready)

- [ ] Start with `80-screensaver.js` (most self-contained, communicates via 2 window APIs)
- [ ] Extract `00-bootstrap.js` next (no upstream dependencies)
- [ ] Smoke-test full app after EACH extraction
- [ ] Update `Layout.php` to load both `app.js` and the extracted module
- [ ] Delete the extracted code from `app.js`
- [ ] Repeat for next module

Each module extraction is 1-2 hours of focused work + smoke test. Total: 10-15 hours for full split.

## Why we haven't done it yet

Single-file approach is **fine** for ~5000 lines if:
- Single developer or small team
- No build pipeline (avoid bundler complexity)
- Browser parses once, no module loading overhead

Splitting becomes worth it when:
- Multiple developers editing concurrently (merge conflicts)
- IDE performance suffers (very large file)
- Code review becomes hard
- Testing individual modules is desired

For now, the inline TOC in app.js header lets you Ctrl+F to any section in under a second.
