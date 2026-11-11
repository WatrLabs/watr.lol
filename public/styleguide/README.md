# the watr style

How my sites look, written down so every new one starts from the same place.

Copy this repo into any project, or load the kit straight from GitHub:

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/WatrLabs/watr-style@main/watr.css">
<script src="https://cdn.jsdelivr.net/gh/WatrLabs/watr-style@main/watr.js" defer></script>
```

Swap `@main` for a tag (like `@v0.4`) once there are releases, so a site doesn't change under you when the kit does.

| file | what it is |
| --- | --- |
| `watr.css` | the kit. tokens, base styles and components, inside `@layer watr` |
| `watr.js` | optional. makes modals, toasts, tabs, table sorting and the sidebar work without writing JS |
| `index.html` | live guide. open it in a browser, drag the knobs, copy the snippet, click around the mockup |
| `assets/` | the blobs and the watrlabs icon |

Version 0.6. Taken from [watrfiles](https://github.com/watrabi/watrfiles) (`public/assets/base.css`, the login, 404 and 500 pages).

---

## the idea

Every site gets the **keep** list unchanged. That's what makes it recognisably mine.
Every site changes the **knobs**. That's what stops them all looking the same.

If two of my sites sat side by side, you should be able to tell they're by the same person, and also tell them apart in under a second.

---

## keep (the signature)

1. **One hue, four depths.** Every accent comes from a single hue at four lightness steps. Nothing else gets a color, apart from red for danger and yellow for warning.

   | token | oklch | use |
   | --- | --- | --- |
   | `--watr-bright` | 78% · 0.13 · hue | buttons, highlights, selection |
   | `--watr-mid` | 69% · 0.11 · hue | button hover |
   | `--watr-bar` | 64% · 0.10 · hue | the full-hue frame (`--color` bars, sidebar, footer and table headers) |
   | `--watr-deep` | 41% · 0.07 · hue | disabled |

   At hue 196 these land on the original watrfiles colors (`#1dd0d1`, `#21afb0`, `#289e9f`, `#0b5657`).

2. **Light from the top-left.** Shadows are always offset down and to the right, with x, y and blur equal: `5px 5px 5px rgb(15 15 15 / .4)`. Never a centered glow, never a shadow going up.

3. **Framed by bars.** A full-width bar on top (45px), optional notice strips stacked directly under it (a line in red, yellow or blue under it), a footer at the bottom. The bars are the surface color with normal text and cast the drop onto the page, so the hue is saved for things you can press (`--color` brings back the full-hue frame). The site's mark sits at the left of the bar, on its own. The bar and its strips are one block: nothing inside the stack casts a shadow, only the bottom of it does. (The kit handles this with `:has()`, so just put strips straight after the bar.)

4. **Square buttons, soft fields.** Buttons have 0 radius, a calm deep fill (`--watr-fill`) with white text, 10px padding, a step lighter or darker on hover. The bright blue stays for small accents (focus rings, checks, dots, lines), never under words. Inputs are filled grey, no border, 0.3em radius. Keep that contrast.

5. **The waterline.** Two-sided layouts put words on the left and the thing (a form, a file, a picture) on the right, split by one thin solid line in the hue (`--watr-waterline-w`, 1px, `--watr-waterline`). On phones it turns horizontal. The same line sits under table headers and tabs. Thicker 3px bars (`--watr-rule-w`) are only for accents, like the current page in the bar or a highlighted post.

6. **The blob.** Error and empty states show a big expressive blob emoji beside the message, on the right. On phones it moves above the text.

7. **Montserrat, normal case.** Montserrat for everything. Nav links, labels and buttons are written in sentence case (Log in, Your apps), the same as headings and sentences. Nothing is forced to lowercase.

8. **Everything moves at 0.2s.** One speed for hover, open, close, and theme changes. Turned off for reduced motion.

9. **The voice.** See [voice](#voice).

---

## knobs (change per site)

Set these in the site's own CSS, after `watr.css`:

```css
:root {
    --watr-hue: 196;          /* 0-359. the one color */
    --watr-chroma: 1;         /* 0.4 calm, 1 normal, 1.3 loud */
    --watr-lift: 5px;         /* 3px flat, 5px normal, 8px dramatic */
    --watr-head-weight: 700;  /* 500 calm, 700 normal, 900 shouty */
    --watr-sidebar-w: 250px;  /* only matters if the site has a sidebar */
}
```

The neutrals (page, surface, fields, text) all take a tiny bit of the hue too, so changing the hue changes the whole feel of the page, not only the buttons.

Other things that change per site but aren't CSS:

- **layout**: bar + sidebar (`.watr-shell`) for an app, split for auth and landing pages, one column for docs and text
- **frame style**: surface (the default) or `--color` on the bar, sidebar and footer for a full-hue L-shaped frame. Don't mix them
- **sidebar style**: light (surface color) or `--color` (the bar's color, so bar and sidebar make one L-shaped frame)
- **the blob**: pick a face that fits the site. thinking for 404, panic for 500 is the watrfiles set
- **the quote**: one in-joke for the split panel, attributed like `- sword 2026`. never reuse one across sites
- **strips**: what the warning strip says, or whether the site has one at all

Rules for keeping sites different:

- Hues of live sites sit at least 40° apart. watrfiles is 196, the watrlabs logo is 237.
- Each new site changes at least one of intensity, lift or heading weight from the last one.
- Want one section of a page in a different color? Add `class="watr-scope"` and set `--watr-hue` on it.

---

## parts

All classes start with `watr-`. Almost everything is inside `@layer watr`, so any normal CSS you write overrides it without `!important`.

The exception is the page itself: the `body` background, text color and font, plus `color-scheme`. Resets like normalize.css (and some hosts) set those outside any layer, and an unlayered rule always beats a layered one, so if these lived in the layer the page would stay white in dark mode. They sit outside it instead. Anything you write after `watr.css` still wins, so link resets *before* it.

| part | classes | notes |
| --- | --- | --- |
| nav bar | `.watr-bar` > `.watr-bar__home` + `.watr-bar__toggle` + `.watr-bar__links` > two `.watr-bar__group` | surface color by default, `--color` for the full-hue bar. `aria-current="page"` gets a bright line under it. under 600px wide the links fold behind a "menu" button and open as full-width rows inside the bar (needs `watr.js`; without it they just wrap). `.watr-bar__me` for the signed-in person's avatar + name |
| bar extras | `.watr-mark` (in `__home`), `form.watr-bar__search` between the groups, `a.watr-bar__cta` | the mark: the site's icon, 28px, no tile (put a light and a dark version in a `<picture>` if it needs one per theme). search: a field welded to a square button; hidden 600-1000px wide, moves into the menu on phones. cta: one bright link, like "sign up" |
| notice strip | `.watr-strip` `--warning` `--info` | normal text over a red line by default. goes straight under the bar |
| footer | `.watr-footer` `--cols` `--color` | sticks to the bottom on short pages. plain, it's one centered line. `--cols`: `.watr-footer__cols` > `.watr-footer__brand` (mark, name, one line about the site) + up to three `<nav>`s with an `<h2>` each, then `.watr-footer__base` for the copyright. two columns on phones |
| button | `.watr-btn` `--quiet` `--danger`, `:disabled` | works on `<a>` and `<button>` |
| fields | plain `input`, `select`, `textarea` | styled by default, no class needed |
| message | `.watr-msg` `--warning` `--info` | a surface block with a 3px edge in the signal color on the left, normal text color, no icon |
| panel | `.watr-panel` | a raised block with a pale wash of the site's hue. use it for one thing per area, not every box |
| split | `.watr-split` `.watr-split__aside` `.watr-split__main` | the waterline layout |
| blob | `.watr-blob` `.watr-blob__face` | error and empty states |
| quote | `.watr-quote` + `<cite>` | cite gets the `- ` prefix automatically |
| shell + sidebar | `.watr-shell` > `aside.watr-side` (> `.watr-side__inner`) + `.watr-shell__main` | the app layout: sidebar under the bar, page beside it. sidebar is always on the left so its drop falls onto the page. inside: `.watr-side__label` section names, `.watr-side__nav` links (`aria-current="page"` gets the button fill, `.watr-side__count` for numbers, wrap an icon and the words in `.watr-side__item`), `.watr-side__foot` for a storage meter or the signed-in person (`.watr-side__me`), `.watr-side__promo` to nudge signed-out people, `.watr-side__links` for a few small links and the copyright at the bottom. `--color` gives it the bar's color |
| burger | `<button class="watr-burger" data-watr-shell="shell-id">` | first thing in the bar. desktop: folds the sidebar away and remembers it. under 800px: opens the sidebar as a drawer over the page; backdrop, escape or picking a link closes it |
| old sidebar | `.watr-sidebar` `.is-hidden` | the original fixed sidebar from watrfiles. use `.watr-shell` for new stuff |
| checks | `.watr-check` wrapping a checkbox or radio | square box (radios stay round), bright when on |
| switch | `.watr-switch` wrapping a checkbox | square track, square knob that casts a tiny drop |
| tabs | `.watr-tabs` + `.watr-tab` with `aria-selected` | tabs sit on a 3px bright line and the active one joins it |
| dropdown | `<details class="watr-dropdown">` + `<summary class="watr-btn">` + `.watr-menu` | no JS needed. `--right` opens toward the left edge. `.is-danger` on the delete item |
| tags | `.watr-tag` `--bright` `--deep` `--warning` `--danger` | small square labels |
| avatar | `.watr-avatar` `--small` `--big` + `.watr-avatar__status` `--online` `--away` `--busy` | square picture (a `background-image`, initials, or a default below). status is a bar along the bottom edge, never a dot: hue for online, yellow away, red busy, grey offline |
| default avatar | `.watr-avatar--blob` or `.watr-avatar--person` | for people with no picture, drawn in the site's hue. blobs have six faces: set `data-watr-blob="0"`-`"5"` yourself (`{{ user.id % 6 }}` in twig), or give it `data-watr-name` and `watr.js` picks one from the name, so the same person always gets the same blob |
| tooltip | `data-watr-tip="text"`, add `data-watr-tip-end` near the right edge | CSS only |
| progress | `<progress class="watr-progress">` | flat track, bright fill |
| loading | `.watr-loading` with three `<i>` | three squares taking turns. sits still for reduced motion |
| pagination | `.watr-pages` with `aria-current="page"` on the current one | `.is-gap` for the dots |
| breadcrumbs | `<ol class="watr-crumbs">` | slashes between |
| table | `.watr-table-wrap` > `table.watr-table` `--compact` `--stack` `--color` | a surface panel: quiet header on the waterline, a thin line between rows (`--color` for the old bar-colored header and stripes), `.num` right-aligns numbers, `tr.is-selected` gets the button fill, `.muted` for quiet cells. `--stack` turns each row into a card under 600px: mark the title cell `td.is-main`, the rest get their column name (`data-label`, filled from the header by `watr.js`) |
| modal | `<dialog class="watr-dialog">` `--danger` `--blob` with `__head` `__body` `__foot` | a tiny page with its own bar. drops in from the top-left. the danger one gets a red bar |
| toast | `watr.toast()` or `.watr-toasts` > `.watr-toast` `--plain` `--warning` `--danger` | bottom-right, where the shadows fall. the line along the bottom is the timer, hover pauses it |
| old modal | `.watr-modal` | the original dimmed backdrop from watrfiles. use `.watr-dialog` for new stuff |
| helpers | `.watr-row` `.watr-stack` `.watr-center` `img.watr-emoji` | |

Dark mode follows the system setting. Its neutrals lean further into the hue than light mode's do (deep water, not grey).

Scrollbars are flat and square: a muted thumb on the page color that goes bright on hover. `data-theme="dark"` or `data-theme="light"` on `<html>` forces one.

### watr.js

Load it at the end of `<body>`. It wires things up by attribute, so most of the time you never call it:

```html
<button class="watr-btn" data-watr-open="rename">Rename</button>

<dialog class="watr-dialog" id="rename">
    <form method="dialog">
        <div class="watr-dialog__head"><h2>rename file</h2><button type="button" class="watr-dialog__close" data-watr-close aria-label="close">&times;</button></div>
        <div class="watr-dialog__body"><input name="name" value="backup.zip"></div>
        <div class="watr-dialog__foot">
            <button type="button" class="watr-btn watr-btn--quiet" data-watr-close>Cancel</button>
            <button class="watr-btn" value="rename">Rename</button>
        </div>
    </form>
</dialog>
```

- `data-watr-open="id"` opens a dialog, `data-watr-close` closes the one it's in, clicking the backdrop closes it too. Read `dialog.returnValue` in its `close` event to see which button was pressed.
- `watr.toast("Uploaded backup.zip", { type, action, onAction, time })`. `type` is `info` (default), `plain`, `warning` or `danger`. `time` is in ms, `0` means it stays until closed. Use `time: 0` for errors.
- Tabs select on click. Header buttons in a `.watr-table` sort that column (put raw numbers in `data-sort` for sizes). Row checkboxes toggle `.is-selected`, and a header checkbox selects every row.
- `data-watr-shell="id"` on the burger runs the sidebar: fold on desktop (saved in localStorage per shell id), drawer on phones.
- `data-watr-sidebar="id"` toggles the old `.watr-sidebar`.
- Turns on the bar's phone menu button, fills `data-label` on `--stack` tables, and picks blob faces. If you add markup later, call `watr.setup(element)`.

### phones

Everything is built to hold up at 375px wide:

- the bar folds its links behind "menu" (use the newer bar markup above)
- the sidebar turns into a drawer under 800px
- tabs stay on one row and scroll sideways
- `--stack` tables become cards; plain tables scroll sideways inside their wrap
- split and blob layouts stack, the waterline turns flat
- dialogs and toasts shrink to the screen with a 16px gap

### toasts vs messages vs modals

- **message** (`.watr-msg`): something about the form or page in front of you. Stays until it's fixed.
- **toast**: something that just happened and needs no answer. "Uploaded backup.zip", "Link copied". Add `undo` when you can.
- **modal**: you need an answer before going on. Deleting things, renaming, being logged out. Danger modals say exactly what will be lost, and the safe button goes first.

---

## voice

- Everything is written in normal sentence case: nav, labels, buttons and sentences alike.
- Say what happened and what to do: "Your passwords do not match." not "Oops! Something went wrong."
- Buttons say the action: Upload, Go back, Delete file. Not Submit or OK.
- One in-joke per page, max. It goes somewhere low-stakes, like the side of the login form, never in an error message someone needs to act on.
- Code comments can be as unhinged as you like.

---

## starting a new site

1. Link `watr.css` before your own CSS.
2. Pick a hue at least 40° from your other live sites. Tune it in `index.html` and copy the snippet.
3. Change at least one of intensity, lift or heading weight from the last site.
4. Pick the layout.
5. Pick the blob for 404 and 500, and write the site's one quote.
6. Go down the keep list. Add back anything missing.

---

## to work on

Things I noticed while pulling this out of watrfiles. Decide on each and move it into the guide.

- [x] **White on the bar color is only 3.2:1 contrast.** Bars are the surface color with normal text by default since 0.6, so this only matters for `--color`. There, keep it to bold nav links, never small body text. (Buttons are white on `--watr-fill`, about 5:1 in light and 6:1 in dark.)
- [ ] **Yellow warnings had no text color** in the original CSS. The kit uses black text on yellow. Confirm that's the look.
- [x] **Panel color.** The old `.container` grey never applied in `base.css` (`background-color: --container-color;` is missing `var()`). Panels now use a pale wash of the hue instead of grey, so they change with each site.
- [ ] **Toast position.** Bottom-right fits the light-from-top-left idea. On phones that's also where thumbs are. Check that it doesn't cover anything important on real pages.
- [ ] **Sorting on phones.** Stacked tables hide the header, so there's nothing to tap to sort. Maybe a small "sort by" select above the table.
- [ ] **Blob faces.** Six to start. Draw more, or a special one for admins.
- [ ] **A display moment.** Montserrat at 900 weight could be a thing for big landing headings. Try it on one site first.
- [x] **Icons.** Line icons, 20px, 2px square-ended strokes in `currentColor` (Lucide-style, inline SVG, no icon font). Used in sidebar rows and the bar's search button. Keep them out of buttons that already say what they do.
- [ ] **Move watrfiles onto the kit** so the source site and the guide stop drifting apart.

## changelog

- **0.6** (2026-11): quieter frame, taken from watrfall. bars, footer and table headers are the surface color by default (`--color` keeps the full-hue look). the thick rule is now the waterline: one thin solid line in the hue. nothing is forced to lowercase any more; write labels in sentence case. the mark sits on its own, without a tile. dark mode is deeper and bluer. new: `.watr-mark`, `.watr-bar__search`, `.watr-bar__cta`, `aria-current` in the bar, `.watr-footer--cols`, `.watr-side__item` / `__promo` / `__links`, flat square scrollbars. messages and strips keep normal text and show their signal color as an edge instead of a solid fill. buttons, the current sidebar row, selected tabs, the bar's call to action and toasts use `--watr-fill`, a deeper calmer blue with white text, instead of black on bright.

- **0.5** (2026-10): sidebar. `.watr-shell` + `.watr-side` (light or `--color`), `.watr-burger`, a width knob, and the mockup is now a full sidebar app.
- **0.4** (2026-10): phone layout pass. bar folds into a menu, tabs scroll, tables can stack into cards. default avatars: a person, or one of six blobs picked from the username.
- **0.3** (2026-10): avatar status is a bar along the bottom instead of a dot. initials avatars are bright on deep. panels are a pale wash of the hue instead of flat grey.
- **0.2** (2026-10): the bar no longer casts a shadow onto alert strips; the stack casts one shadow from its bottom. fixed the page background not changing in dark mode when a reset is loaded. added modals, toasts, tables, tabs, dropdowns, checks, switches, tags, avatars, tooltips, progress, loading, pagination, breadcrumbs, `watr.js`, and a full mockup page.
- **0.1** (2026-10): first pull from watrfiles. ramp, drop shadow, bars, buttons, fields, split, blob, voice.
