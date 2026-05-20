# BPM Timeline — Design Spec
**Date:** 2026-05-20  
**File to create:** `tools/bpmtimeline.html`

---

## Goal

A standalone search tool for browsing Igor Starshinov's sample pack catalogue by BPM. The user sets a BPM range, picks a genre, and scans a vertical list sorted slowest → fastest to find packs worth downloading.

---

## Data Source

`sample_packs_bpm_tagged.json` — embedded directly in the HTML as a `<script type="application/json" id="raw-data">` tag at build time (same pattern as `samplesearch.html`). The file has two top-level arrays:

- **`tagged_posts`** — 262 posts with BPM data. Each post has: `post_id`, `date`, `description`, `tg_url`, `genres[]`, `bpm_min`, `bpm_max`, `slowed_min`, `slowed_max`, `packs[]`.
- **`untagged_posts`** — 40 posts whose genres (Vocals, SFX, MIDI, etc.) carry no BPM data.

Each `packs[]` item has: `producer`, `name`, `url`, `bpm_min`, `bpm_max`, `slowed_min`, `slowed_max`.

**Unit of display:** one row per **post** (not per pack), since the TG download link (`tg_url`) is per-post and most packs within a post share the same BPM (`inherited_from_post: true`). Pack names are listed inside the row.

---

## Visual Style

Matches `samplesearch.html` exactly:
- Fonts: `Space Mono` (body) + `Unbounded` (labels/headers)
- Palette: `--bg #0a0a0a`, `--accent #e8c46a`, `--text #e8e4dc`, `--text-dim #888`, etc.
- CRT scanline overlay (`body::after`)
- 280px sidebar + fluid main area grid

---

## Layout

### Header
- Left: title `Igor Starshinov — BPM Map`
- Right: stats — total tagged posts · total packs · total untagged

### Sidebar (240px, sticky)
1. **Text search** — filters by pack name or producer (case-insensitive substring)
2. **BPM range** — dual-handle slider, range derived from data (global min `bpm_min` to global max `bpm_max`), default = full range (no restriction). Displays selected min/max values live above the track.
3. **Genre filter** — all 40 genres as clickable pills, single-select, scrollable list (max-height ~260px). Clicking the active genre deselects it.
4. **Reset button** — clears all three filters

### Main area

**Tagged section** (sorted by `bpm_min` ascending):

Posts are grouped into **10-BPM buckets** (60–70, 70–80, … 170–180, 180+) based on the post's `bpm_min`. The bucket header is sticky while scrolling within the bucket. Each row:

| Column | Content |
|--------|---------|
| BPM col (70px) | `bpm_min–bpm_max` in accent gold; `slowed min–max` in faint text below |
| Info col (flex) | Genre tags → pack names (one line each: `Producer · Pack Name`) |
| TG col (auto) | Telegram icon + "TG" link to `tg_url`, opens in new tab |

Genre filter match: a post is included if its `genres[]` contains the selected genre. BPM filter match: a post is included if its range overlaps the slider range (`bpm_min <= slider_max AND bpm_max >= slider_min`). Text search matches any pack name or producer within the post.

**Untagged section** (always shown at the bottom, below a divider):
- Label: "Без BPM-тега" + count + note about genre types
- Same row layout but without BPM column values (shows `—`); rows dimmed to 55% opacity
- Not affected by BPM slider (always visible unless genre or text filter excludes them)

### Results count bar
Between header and timeline: `N постов · M паков` + active filter summary.

---

## Filtering Logic

All filters combine with AND:
- **BPM overlap:** `post.bpm_min <= sliderMax && post.bpm_max >= sliderMin`
- **Genre:** `post.genres.includes(selectedGenre)` (null = no filter)
- **Text:** any `pack.name` or `pack.producer` contains the query string

Untagged posts: exempt from BPM filter, subject to genre and text filters.

---

## Implementation Notes

- No build step — single `.html` file with JSON inlined at the bottom, identical to `samplesearch.html` pattern.
- Dual-handle BPM slider implemented with two `<input type="range">` overlapping (the standard CSS trick), no external library.
- All JS in a self-executing IIFE, no frameworks.
- Genre list built dynamically from the data (same as samplesearch.html).
- Bucket headers use `position: sticky; top: 0` so they pin while scrolling.
