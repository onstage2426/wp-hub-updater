# `hu.json` & local assets

---

## Metadata file

Place an optional `hu.json` file in your repository root to supply structured metadata for the update popup. Both plugins and themes use the same filename. All fields are optional.

```json
{
  "requires_wp":  "6.4",
  "requires_php": "8.1",
  "tested_up_to": "6.6",
  "contributors": {
    "your-username": {
      "display_name": "Your Name",
      "profile": "https://profiles.wordpress.org/your-username",
      "avatar": "https://secure.gravatar.com/avatar/..."
    }
  },
  "sections": {
    "installation": "<p>Upload to <code>wp-content/plugins/</code> and activate.</p>",
    "faq": "<p><strong>Does it work with multisite?</strong> Yes.</p>"
  },
  "changelog": [
    {
      "version": "1.2.0",
      "date":    "2024-12-01",
      "body":    "<ul><li>Added feature X.</li><li>Fixed bug Y.</li></ul>"
    },
    {
      "version": "1.1.0",
      "date":    "2024-10-15",
      "body":    "<p>Initial public release.</p>"
    }
  ]
}
```

**`requires_wp` and `requires_php`** are enforced — if the site does not meet either requirement, no update is surfaced until the site is upgraded.

**`sections`** entries are merged into the popup section list after the plugin/theme header is read. Including `description` overrides the header description in the popup.

**`changelog`** entries render as `<h4>version – date</h4>` followed by `body` HTML. No Markdown parser required. When present, this takes priority over the GitHub release body and any local changelog file.

**Banners and icons (plugins only)** — when no local `assets/` files are found, `hu.json` can supply remote URLs:

```json
{
  "banners": { "low": "https://example.com/banner-772x250.png", "high": "https://example.com/banner-1544x500.png" },
  "icons":   { "1x": "https://example.com/icon-128x128.png",   "2x": "https://example.com/icon-256x256.png" }
}
```

**`screenshot_url` (themes only)** — overrides the locally-detected screenshot URL.

The file is fetched at the release tag ref and cached in `hu_metadata_cache-{slug}`. A new release automatically busts the cache.

---

## Changelog priority

The changelog shown in the popup is sourced in this order:

1. **`hu.json` structured `changelog` array** — rendered as versioned HTML headings.
2. **GitHub release body** — used when no structured changelog is supplied.
3. **Local changelog file** — scanned for in the plugin/theme directory: `CHANGES.md`, `CHANGELOG.md`, `changes.md`, `changelog.md` (checked in this order). Rendered by [Parsedown](https://github.com/erusev/parsedown) if available, otherwise shown as plain text.

If none of the above yield content, "There is no changelog available." is shown.

---

## Local assets *(plugins only)*

Place files in an `assets/` subdirectory inside your plugin directory. The updater loads them automatically.

**Icons** (shown on the Plugins screen and in the popup):

| Filename | Slot |
|---|---|
| `assets/icon.svg` | SVG (preferred) |
| `assets/icon-256x256.png` or `.jpg` | 2× |
| `assets/icon-128x128.png` or `.jpg` | 1× |

**Banners** (shown at the top of the "View details" popup):

| Filename | Slot |
|---|---|
| `assets/banner-772x250.png` or `.jpg` | Standard |
| `assets/banner-1544x500.png` or `.jpg` | High-DPI |

Local assets always take priority over URLs supplied in `hu.json`.

Themes use the screenshot file already present in the theme directory — no `assets/` directory needed.
