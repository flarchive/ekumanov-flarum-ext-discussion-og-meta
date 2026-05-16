# Discussion OpenGraph Meta for Flarum 2.0

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Latest Stable Version](https://img.shields.io/packagist/v/ekumanov/flarum-ext-discussion-og-meta.svg)](https://packagist.org/packages/ekumanov/flarum-ext-discussion-og-meta)
[![Total Downloads](https://img.shields.io/packagist/dt/ekumanov/flarum-ext-discussion-og-meta.svg)](https://packagist.org/packages/ekumanov/flarum-ext-discussion-og-meta)
[![Backend](https://github.com/ekumanov/flarum-ext-discussion-og-meta/actions/workflows/backend.yml/badge.svg)](https://github.com/ekumanov/flarum-ext-discussion-og-meta/actions/workflows/backend.yml)

Emits per-discussion **OpenGraph** and **Twitter Card** meta tags in the
`<head>` of Flarum 2.0 discussion pages, so when someone pastes a discussion
URL into Slack, Discord, Facebook, X, iMessage, Telegram, or any other
unfurler, the preview shows the **actual discussion title and first-post
excerpt** — not the same generic forum description every time.

Built with [Claude Code](https://claude.com/claude-code).

## Why this exists

Out of the box, every page on a Flarum forum carries the same single forum-level
`<meta name="description">`. So a link to
`https://example.com/d/2449-pianos-and-design` previews everywhere as the
forum's generic description ("Piano forum for piano, keyboard, synth, players
and music enthusiasts..."), regardless of what the thread is actually about.
That makes shared links look interchangeable and kills click-through.

This extension fixes that by reading the discussion + first-post data that
Flarum's discussion route handler **already** loads, and turning it into a
small block of per-page OpenGraph + Twitter meta tags.

## What it emits

On every **public** discussion page (`/d/{id}-{slug}`):

```html
<meta property="og:title"       content="{discussion title} - {forum name}">
<meta property="og:type"        content="article">
<meta property="og:url"         content="{canonical discussion URL}">
<meta property="og:site_name"   content="{forum name}">
<meta property="og:description" content="{first-post excerpt, ~200 chars, plaintext}">
<meta property="og:image"       content="{first image URL in first post, if any}">

<meta name="twitter:card"        content="summary | summary_large_image">
<meta name="twitter:title"       content="{discussion title} - {forum name}">
<meta name="twitter:description" content="{first-post excerpt}">
<meta name="twitter:image"       content="{first image URL, if any}">
```

`twitter:card` switches to `summary_large_image` when the first post contains
an image; otherwise it's plain `summary`. `og:image` and `twitter:image` are
only emitted when an image is found.

## What it does NOT do

- No `INSERT`, `UPDATE`, or `DELETE` against the database. No migrations.
- No new tables, columns, or settings rows.
- No JSON-LD / structured-data emission.
- No sitemap integration (use [fof/sitemap](https://github.com/FriendsOfFlarum/sitemap) for that).
- No admin UI or settings panel — it uses your existing `forum_title` setting.
- No image proxying — the `og:image` URL is whatever sits in the first
  post's rendered HTML (e.g. a `fof/upload` attachment URL).

## What it skips

Defensively, the meta tags are **not** injected when the discussion is:

- Hidden (`isHidden = true`)
- Private (fof/byobu `isPrivateDiscussion = true`)
- Unapproved (flarum/approval `isApproved = false`)

Flarum's API already 404s these for unauthenticated viewers (which is what
link-unfurl bots are), so the meta tags would never reach a crawler anyway —
but the explicit skip belt-and-braces the case where a privileged user
copies a non-public discussion URL.

## Install

```bash
composer require ekumanov/flarum-ext-discussion-og-meta
php flarum extension:enable ekumanov-discussion-og-meta
php flarum cache:clear
```

That's it — no settings to configure.

## Verify

After install, on any public discussion page:

```bash
curl -sS https://your-forum.example/d/123-some-slug \
  | grep -E '<meta (property="og:|name="twitter:)' | head
```

You should see the `og:*` and `twitter:*` tags reflecting the discussion's
actual title and first-post content.

## How it works

The extension registers a single
`Extend\Frontend('forum')->content(AddOgMetaTags::class)` callback. It runs
**after** Flarum's built-in discussion route handler has populated
`$document->payload['apiDocument']` with the JSON:API document for the
discussion (including the eager-loaded `firstPost`). So the callback adds
no DB queries of its own — it just reads what core has already fetched and
formats it into meta tags.

The description build pipeline:

1. Read `firstPost.attributes.contentHtml` from the API document.
2. Replace every HTML tag with a single space (so `</p><p>` doesn't
   concatenate text), then `html_entity_decode`, then collapse whitespace.
3. Truncate at the last word boundary before 200 chars and append `…`.

The image scan:

1. Short-circuit if `contentHtml` doesn't contain `<img`.
2. `preg_match_all` for `<img>` tags, skip ones whose class contains
   `emoji` or whose `src` is a `data:` URI or `/emoji/` path.
3. Use the first survivor as `og:image`.

## Performance

Zero DB queries. The description regex scales linearly with first-post HTML
size and runs in microseconds for typical posts. Measured against the
pianoclack prod-mirror over 20 iterations per condition, the
with-extension vs. without-extension delta sat well inside curl
measurement noise (±70 ms stddev for a ~1 s debug-mode render).

No client-side JS, no CSS, no asset rebuild.

## Compatibility

- Flarum `^2.0`
- PHP `^8.2`

Plays nicely with `fof/upload` (image attachments become `og:image`),
`fof/byobu` (private discussions are skipped), and `flarum/approval`
(unapproved discussions are skipped).

## License

MIT — see [LICENSE](LICENSE).
