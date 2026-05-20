# BPM Timeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create `public_html/tools/bpmtimeline.html` — a self-contained BPM-sorted sample pack browser with sidebar filters, matching the visual style of `samplesearch.html`.

**Architecture:** Single HTML file, no build step, no external dependencies. JSON data from `sample_packs_bpm_tagged.json` embedded inline as a `<script type="application/json">` tag. All JS in a self-executing IIFE. Dual-handle BPM slider built with two overlapping `<input type="range">` elements.

**Tech Stack:** Vanilla HTML/CSS/JS. Fonts: Space Mono + Unbounded via Google Fonts. No frameworks, no bundler.

---

## File Map

- **Create:** `public_html/tools/bpmtimeline.html` — the entire tool (HTML + CSS + JS + embedded JSON)

---

### Task 1: HTML scaffold with full CSS

Create the file with complete markup and styles — no JS yet. Use static placeholder text for counts.

**Files:**
- Create: `public_html/tools/bpmtimeline.html`

- [ ] **Step 1: Create the file**

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BPM Map — Igor Starshinov</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Unbounded:wght@300;400;600&display=swap');

    :root {
      --bg: #0a0a0a; --surface: #111; --surface2: #1a1a1a;
      --border: #222; --border2: #2e2e2e;
      --text: #e8e4dc; --text-dim: #888; --text-faint: #444;
      --accent: #e8c46a; --accent-dim: #7a6530;
      --red: #e85454; --blue: #6ab4e8;
      --mono: 'Space Mono', monospace; --wide: 'Unbounded', sans-serif;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }

    body {
      background: var(--bg); color: var(--text);
      font-family: var(--mono); font-size: 13px; line-height: 1.6;
      min-height: 100vh;
    }
    body::after {
      content: ''; position: fixed; inset: 0;
      background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,0,0,0.025) 2px, rgba(0,0,0,0.025) 4px);
      pointer-events: none; z-index: 9999;
    }

    /* HEADER */
    header {
      border-bottom: 1px solid var(--border2); padding: 24px 28px 18px;
      display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;
    }
    .header-title { font-family: var(--wide); font-size: 17px; font-weight: 300; letter-spacing: 0.04em; }
    .header-title strong { font-weight: 600; color: var(--accent); }
    .stats { margin-left: auto; font-size: 11px; color: var(--text-dim); text-align: right; }
    .stats strong { color: var(--accent); font-weight: 400; }

    /* LAYOUT */
    .layout {
      display: grid; grid-template-columns: 260px 1fr;
      min-height: calc(100vh - 72px);
    }

    /* SIDEBAR */
    .sidebar {
      border-right: 1px solid var(--border2); padding: 22px 18px;
      position: sticky; top: 0; height: calc(100vh - 72px);
      overflow-y: auto; display: flex; flex-direction: column; gap: 26px;
    }
    .sidebar::-webkit-scrollbar { width: 3px; }
    .sidebar::-webkit-scrollbar-thumb { background: var(--border2); }

    .filter-section { display: flex; flex-direction: column; }
    .filter-label {
      font-family: var(--wide); font-size: 9px; font-weight: 600;
      letter-spacing: 0.2em; text-transform: uppercase; color: var(--text-faint);
      margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid var(--border);
    }

    /* Search */
    .search-wrap { position: relative; }
    .search-wrap input {
      width: 100%; background: var(--surface2); border: 1px solid var(--border2);
      color: var(--text); font-family: var(--mono); font-size: 12px;
      padding: 7px 28px 7px 10px; outline: none; transition: border-color 0.15s;
    }
    .search-wrap input:focus { border-color: var(--accent); }
    .search-wrap input::placeholder { color: var(--text-faint); }
    .search-clear {
      position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
      color: var(--text-faint); cursor: pointer; font-size: 14px; line-height: 1;
      display: none; transition: color 0.1s;
    }
    .search-clear:hover { color: var(--text); }
    .search-clear.visible { display: block; }

    /* BPM Slider */
    .bpm-display {
      display: flex; justify-content: space-between; align-items: baseline;
      margin-bottom: 6px;
    }
    .bpm-display-val { font-family: var(--wide); font-size: 14px; color: var(--accent); }
    .bpm-display-dash { color: var(--text-faint); font-size: 11px; }
    .bpm-slider-wrap { position: relative; height: 28px; margin: 4px 0 2px; }
    .bpm-slider-track {
      position: absolute; top: 13px; left: 0; right: 0;
      height: 4px; background: var(--border2); border-radius: 2px;
    }
    .bpm-slider-fill {
      position: absolute; height: 100%; background: var(--accent); border-radius: 2px;
    }
    .bpm-slider-wrap input[type=range] {
      position: absolute; width: 100%; top: 7px;
      background: transparent; pointer-events: none;
      -webkit-appearance: none; appearance: none; height: 14px; outline: none;
    }
    .bpm-slider-wrap input[type=range]::-webkit-slider-thumb {
      -webkit-appearance: none; appearance: none;
      width: 14px; height: 14px; border-radius: 50%;
      background: var(--accent); cursor: pointer; pointer-events: all;
    }
    .bpm-slider-wrap input[type=range]::-moz-range-thumb {
      width: 14px; height: 14px; border-radius: 50%;
      background: var(--accent); cursor: pointer; pointer-events: all; border: none;
    }
    .bpm-slider-limits { display: flex; justify-content: space-between; font-size: 9px; color: var(--text-faint); }

    /* Genre pills */
    .genre-list { display: flex; flex-wrap: wrap; gap: 4px; max-height: 220px; overflow-y: auto; }
    .genre-list::-webkit-scrollbar { width: 3px; }
    .genre-list::-webkit-scrollbar-thumb { background: var(--border2); }
    .genre-pill {
      font-size: 9px; padding: 2px 7px; border: 1px solid var(--border2);
      color: var(--text-faint); cursor: pointer; font-family: var(--mono);
      transition: border-color 0.1s, color 0.1s; white-space: nowrap;
    }
    .genre-pill:hover { border-color: var(--accent-dim); color: var(--text-dim); }
    .genre-pill.active { border-color: rgba(232,196,106,0.4); color: var(--accent); background: rgba(232,196,106,0.06); }

    /* Reset */
    .reset-btn {
      background: transparent; border: 1px solid var(--border2); color: var(--text-faint);
      font-family: var(--mono); font-size: 10px; padding: 6px 12px; cursor: pointer;
      letter-spacing: 0.05em; margin-top: auto; transition: border-color 0.1s, color 0.1s;
    }
    .reset-btn:hover { border-color: var(--red); color: var(--red); }

    /* MAIN */
    .main { overflow-y: auto; }
    .results-header {
      padding: 12px 22px 10px; border-bottom: 1px solid var(--border);
      position: sticky; top: 0; background: var(--bg); z-index: 5;
    }
    .results-count { font-size: 11px; color: var(--text-dim); }
    .results-count em { color: var(--accent); font-style: normal; }

    /* TIMELINE */
    .timeline { padding: 0 22px 48px; }

    .bpm-group-header {
      position: sticky; top: 37px; z-index: 4; background: var(--bg);
      padding: 9px 0 5px; border-bottom: 1px solid var(--border);
      display: flex; align-items: baseline; gap: 10px;
    }
    .bpm-group-label { font-family: var(--wide); font-size: 10px; font-weight: 600; color: var(--accent); letter-spacing: 0.08em; }
    .bpm-group-count { font-size: 9px; color: var(--text-faint); }

    /* Post row */
    .post-row {
      display: grid; grid-template-columns: 72px 1fr auto;
      align-items: start; gap: 14px; padding: 9px 0;
      border-bottom: 1px solid var(--border);
    }
    .post-bpm-col { display: flex; flex-direction: column; gap: 2px; padding-top: 3px; }
    .post-bpm-range { font-size: 11px; color: var(--accent); font-weight: 700; white-space: nowrap; }
    .post-bpm-slowed { font-size: 8px; color: var(--text-faint); white-space: nowrap; }

    .post-info { display: flex; flex-direction: column; gap: 4px; }
    .post-genres { display: flex; flex-wrap: wrap; gap: 3px; }
    .post-genre-tag { font-size: 8px; padding: 1px 5px; border: 1px solid var(--border2); color: var(--text-dim); }
    .post-genre-tag.active-filter { border-color: rgba(232,196,106,0.3); color: var(--accent); }
    .pack-line { display: flex; gap: 6px; align-items: baseline; }
    .pack-name { font-size: 12px; color: var(--text); }
    .pack-producer { font-size: 10px; color: var(--text-dim); }
    .pack-producer::before { content: '·'; margin-right: 4px; color: var(--text-faint); }

    mark { background: rgba(232,196,106,0.25); color: var(--text); border-radius: 1px; }

    /* TG link */
    .tg-link {
      display: flex; align-items: center; gap: 4px; color: var(--text-faint);
      font-size: 9px; text-decoration: none; padding: 4px 7px;
      border: 1px solid transparent; white-space: nowrap; align-self: start;
      margin-top: 2px; transition: border-color 0.1s, color 0.1s;
    }
    .tg-link:hover { border-color: var(--border2); color: var(--blue); }
    .tg-icon { width: 13px; height: 13px; fill: currentColor; flex-shrink: 0; }

    /* Untagged section */
    .section-divider {
      margin-top: 30px; padding: 9px 0 5px; border-bottom: 1px solid var(--border);
      display: flex; align-items: baseline; gap: 10px;
    }
    .section-divider-label { font-family: var(--wide); font-size: 9px; font-weight: 600; color: var(--text-faint); letter-spacing: 0.15em; text-transform: uppercase; }
    .section-divider-note { font-size: 9px; color: var(--text-faint); }
    .post-row.untagged { opacity: 0.55; }

    /* Empty state */
    .empty { padding: 60px 0; text-align: center; color: var(--text-faint); }
    .empty-icon { font-size: 28px; margin-bottom: 12px; }
    .empty-text { font-size: 11px; letter-spacing: 0.05em; }
  </style>
</head>
<body>
  <header>
    <div class="header-title">Igor Starshinov — <strong>BPM Map</strong></div>
    <div class="stats">
      <strong id="stat-posts">—</strong> постов &nbsp;·&nbsp;
      <strong id="stat-packs">—</strong> паков &nbsp;·&nbsp;
      <strong id="stat-untagged">—</strong> без тега
    </div>
  </header>

  <div class="layout">
    <aside class="sidebar">
      <div class="filter-section">
        <div class="filter-label">Поиск</div>
        <div class="search-wrap">
          <input type="text" id="search-input" placeholder="Название пака или producer…" autocomplete="off" spellcheck="false">
          <span class="search-clear" id="search-clear">&times;</span>
        </div>
      </div>

      <div class="filter-section">
        <div class="filter-label">BPM диапазон</div>
        <div class="bpm-display">
          <div class="bpm-display-val" id="bpm-min-val">—</div>
          <div class="bpm-display-dash">—</div>
          <div class="bpm-display-val" id="bpm-max-val">—</div>
        </div>
        <div class="bpm-slider-wrap" id="bpm-slider-wrap">
          <div class="bpm-slider-track"><div class="bpm-slider-fill" id="bpm-slider-fill"></div></div>
          <input type="range" id="bpm-min-input" step="1">
          <input type="range" id="bpm-max-input" step="1">
        </div>
        <div class="bpm-slider-limits">
          <span id="bpm-global-min">—</span>
          <span id="bpm-global-max">—</span>
        </div>
      </div>

      <div class="filter-section">
        <div class="filter-label">Жанр</div>
        <div class="genre-list" id="genre-list"></div>
      </div>

      <button class="reset-btn" id="reset-btn">Сбросить</button>
    </aside>

    <main class="main">
      <div class="results-header">
        <div class="results-count" id="results-count">Загрузка...</div>
      </div>
      <div class="timeline" id="timeline"></div>
    </main>
  </div>

  <script type="application/json" id="raw-data">PLACEHOLDER_JSON</script>

  <script>
    /* JS added in Task 2 */
  </script>
</body>
</html>
```

- [ ] **Step 2: Open in browser and verify layout**

Open `public_html/tools/bpmtimeline.html` in a browser. Expect: dark background, header with dashes for stats, sidebar with empty filter sections, `Загрузка...` in the main area. No JS errors in console (aside from PLACEHOLDER_JSON parse error — expected at this stage).

- [ ] **Step 3: Commit**

```
git add public_html/tools/bpmtimeline.html
git commit -m "feat(bpmtimeline): scaffold HTML and CSS"
```

---

### Task 2: Embed JSON data

Replace `PLACEHOLDER_JSON` with the actual content of `sample_packs_bpm_tagged.json`.

**Files:**
- Modify: `public_html/tools/bpmtimeline.html`

- [ ] **Step 1: Embed the JSON using PowerShell**

Run from the repo root (`C:\Users\Asus\Documents\KSBURAYA\ksburayamusic.ru`):

```powershell
$json = Get-Content 'C:\Users\Asus\Downloads\sample_packs_bpm_tagged.json' -Raw -Encoding UTF8
$html = Get-Content 'public_html\tools\bpmtimeline.html' -Raw -Encoding UTF8
$html = $html.Replace('PLACEHOLDER_JSON', $json)
$html | Out-File 'public_html\tools\bpmtimeline.html' -Encoding utf8 -NoNewline
```

- [ ] **Step 2: Verify JSON embedded correctly**

Open the file in a browser. Open DevTools console and run:
```js
JSON.parse(document.getElementById('raw-data').textContent).tagged_posts.length
```
Expected output: `262`

- [ ] **Step 3: Commit**

```
git add public_html/tools/bpmtimeline.html
git commit -m "feat(bpmtimeline): embed JSON data"
```

---

### Task 3: Data layer and stats

Parse the JSON, compute globals, build genre list, populate header stats.

**Files:**
- Modify: `public_html/tools/bpmtimeline.html` — replace `/* JS added in Task 2 */` with the IIFE below

- [ ] **Step 1: Replace the script placeholder with the IIFE**

Find `<script>\n    /* JS added in Task 2 */\n  </script>` and replace with:

```html
  <script>
    (function () {
      var DATA = JSON.parse(document.getElementById('raw-data').textContent);
      var tagged = DATA.tagged_posts;
      var untagged = DATA.untagged_posts;

      /* ── globals ── */
      var globalMin = tagged.reduce(function (m, p) { return Math.min(m, p.bpm_min); }, Infinity);
      var globalMax = tagged.reduce(function (m, p) { return Math.max(m, p.bpm_max); }, -Infinity);

      var totalPacks = tagged.reduce(function (s, p) { return s + p.packs.length; }, 0)
                     + untagged.reduce(function (s, p) { return s + p.packs.length; }, 0);

      document.getElementById('stat-posts').textContent = tagged.length;
      document.getElementById('stat-packs').textContent = totalPacks;
      document.getElementById('stat-untagged').textContent = untagged.length;

      /* ── genre list ── */
      var genreCounts = {};
      tagged.forEach(function (post) {
        post.genres.forEach(function (g) { genreCounts[g] = (genreCounts[g] || 0) + 1; });
      });
      untagged.forEach(function (post) {
        post.genres.forEach(function (g) { genreCounts[g] = (genreCounts[g] || 0) + 1; });
      });
      var allGenres = Object.entries(genreCounts).sort(function (a, b) { return b[1] - a[1]; });

      /* ── state ── */
      var state = { search: '', genre: null, bpmMin: globalMin, bpmMax: globalMax };

      /* ── genre pills ── */
      var genreListEl = document.getElementById('genre-list');
      allGenres.forEach(function (entry) {
        var g = entry[0];
        var pill = document.createElement('div');
        pill.className = 'genre-pill';
        pill.textContent = g;
        pill.dataset.genre = g;
        pill.addEventListener('click', function () {
          state.genre = state.genre === g ? null : g;
          render();
        });
        genreListEl.appendChild(pill);
      });

      /* ── BPM slider ── */
      var bpmMinInput = document.getElementById('bpm-min-input');
      var bpmMaxInput = document.getElementById('bpm-max-input');
      var bpmMinValEl = document.getElementById('bpm-min-val');
      var bpmMaxValEl = document.getElementById('bpm-max-val');
      var bpmFillEl = document.getElementById('bpm-slider-fill');

      bpmMinInput.min = bpmMaxInput.min = globalMin;
      bpmMinInput.max = bpmMaxInput.max = globalMax;
      bpmMinInput.value = globalMin;
      bpmMaxInput.value = globalMax;
      document.getElementById('bpm-global-min').textContent = globalMin;
      document.getElementById('bpm-global-max').textContent = globalMax;

      function updateSliderFill() {
        var lo = +bpmMinInput.value, hi = +bpmMaxInput.value;
        var range = globalMax - globalMin;
        bpmFillEl.style.left  = ((lo - globalMin) / range * 100) + '%';
        bpmFillEl.style.right = ((globalMax - hi) / range * 100) + '%';
        bpmMinValEl.textContent = lo;
        bpmMaxValEl.textContent = hi;
      }
      updateSliderFill();

      bpmMinInput.addEventListener('input', function () {
        if (+this.value > +bpmMaxInput.value) this.value = bpmMaxInput.value;
        state.bpmMin = +this.value;
        updateSliderFill();
        render();
      });
      bpmMaxInput.addEventListener('input', function () {
        if (+this.value < +bpmMinInput.value) this.value = bpmMinInput.value;
        state.bpmMax = +this.value;
        updateSliderFill();
        render();
      });

      /* ── text search ── */
      var searchInput = document.getElementById('search-input');
      var searchClear = document.getElementById('search-clear');
      searchInput.addEventListener('input', function () {
        state.search = this.value;
        searchClear.classList.toggle('visible', !!this.value);
        render();
      });
      searchClear.addEventListener('click', function () {
        searchInput.value = '';
        state.search = '';
        this.classList.remove('visible');
        render();
      });

      /* ── reset ── */
      document.getElementById('reset-btn').addEventListener('click', function () {
        state.search = ''; state.genre = null;
        state.bpmMin = globalMin; state.bpmMax = globalMax;
        searchInput.value = '';
        searchClear.classList.remove('visible');
        bpmMinInput.value = globalMin;
        bpmMaxInput.value = globalMax;
        updateSliderFill();
        render();
      });

      /* ── helpers ── */
      var TG_PATH = 'M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z';

      function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }
      function hi(text, q) {
        if (!q) return esc(text);
        var escaped = esc(text);
        var qEsc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return escaped.replace(new RegExp('(' + qEsc + ')', 'gi'), '<mark>$1</mark>');
      }
      function makeTgSvg() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('class', 'tg-icon');
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', TG_PATH); svg.appendChild(path);
        return svg;
      }

      /* ── filter ── */
      function filterTagged() {
        var q = state.search.toLowerCase().trim();
        return tagged.filter(function (post) {
          if (post.bpm_min > state.bpmMax || post.bpm_max < state.bpmMin) return false;
          if (state.genre && post.genres.indexOf(state.genre) < 0) return false;
          if (q) {
            var match = post.packs.some(function (p) {
              return p.name.toLowerCase().indexOf(q) >= 0 ||
                (p.producer && p.producer.toLowerCase().indexOf(q) >= 0);
            });
            if (!match) return false;
          }
          return true;
        }).sort(function (a, b) { return a.bpm_min - b.bpm_min; });
      }

      function filterUntagged() {
        var q = state.search.toLowerCase().trim();
        return untagged.filter(function (post) {
          if (state.genre && post.genres.indexOf(state.genre) < 0) return false;
          if (q) {
            var match = post.packs.some(function (p) {
              return p.name.toLowerCase().indexOf(q) >= 0 ||
                (p.producer && p.producer.toLowerCase().indexOf(q) >= 0);
            });
            if (!match) return false;
          }
          return true;
        });
      }

      /* ── row builders ── */
      function makePostRow(post, isUntagged) {
        var q = state.search.toLowerCase().trim();
        var row = document.createElement('div');
        row.className = 'post-row' + (isUntagged ? ' untagged' : '');

        /* BPM col */
        var bpmCol = document.createElement('div');
        bpmCol.className = 'post-bpm-col';
        if (!isUntagged) {
          var bpmRange = document.createElement('div');
          bpmRange.className = 'post-bpm-range';
          bpmRange.textContent = post.bpm_min + '–' + post.bpm_max;
          var bpmSlowed = document.createElement('div');
          bpmSlowed.className = 'post-bpm-slowed';
          bpmSlowed.textContent = 'slowed ' + post.slowed_min + '–' + post.slowed_max;
          bpmCol.appendChild(bpmRange);
          bpmCol.appendChild(bpmSlowed);
        } else {
          bpmCol.innerHTML = '<span style="color:var(--text-faint);font-size:11px">—</span>';
        }

        /* Info col */
        var infoCol = document.createElement('div');
        infoCol.className = 'post-info';

        var genresEl = document.createElement('div');
        genresEl.className = 'post-genres';
        post.genres.forEach(function (g) {
          var tag = document.createElement('span');
          tag.className = 'post-genre-tag' + (g === state.genre ? ' active-filter' : '');
          tag.textContent = g;
          genresEl.appendChild(tag);
        });
        infoCol.appendChild(genresEl);

        post.packs.forEach(function (p) {
          var packLine = document.createElement('div');
          packLine.className = 'pack-line';
          var nameEl = document.createElement('span');
          nameEl.className = 'pack-name';
          nameEl.innerHTML = hi(p.name, q);
          packLine.appendChild(nameEl);
          if (p.producer && p.producer !== 'Unknown') {
            var prodEl = document.createElement('span');
            prodEl.className = 'pack-producer';
            prodEl.innerHTML = hi(p.producer, q);
            packLine.appendChild(prodEl);
          }
          infoCol.appendChild(packLine);
        });

        /* TG link */
        var tgLink = document.createElement('a');
        tgLink.className = 'tg-link';
        tgLink.href = post.tg_url;
        tgLink.target = '_blank'; tgLink.rel = 'noopener';
        tgLink.appendChild(makeTgSvg());
        var tgLabel = document.createElement('span');
        tgLabel.textContent = 'TG';
        tgLink.appendChild(tgLabel);

        row.appendChild(bpmCol);
        row.appendChild(infoCol);
        row.appendChild(tgLink);
        return row;
      }

      /* ── render ── */
      function render() {
        var q = state.search.toLowerCase().trim();
        var filteredTagged = filterTagged();
        var filteredUntagged = filterUntagged();

        /* update genre pills */
        document.querySelectorAll('.genre-pill').forEach(function (el) {
          el.classList.toggle('active', el.dataset.genre === state.genre);
        });

        /* results count */
        var totalFiltered = filteredTagged.length + filteredUntagged.length;
        var packsFiltered = filteredTagged.reduce(function (s, p) { return s + p.packs.length; }, 0)
                          + filteredUntagged.reduce(function (s, p) { return s + p.packs.length; }, 0);
        var activeFilters = [state.genre, q, state.bpmMin > globalMin || state.bpmMax < globalMax ? '1' : ''].filter(Boolean).length;
        var countEl = document.getElementById('results-count');
        countEl.innerHTML = '<em>' + filteredTagged.length + '</em> постов &nbsp;·&nbsp; <em>' + packsFiltered + '</em> паков'
          + (filteredUntagged.length ? ' &nbsp;·&nbsp; ' + filteredUntagged.length + ' без тега' : '')
          + (activeFilters ? ' &nbsp;<span style="color:var(--text-faint);font-size:9px">[' + activeFilters + ' фильтр' + (activeFilters > 1 ? 'а' : '') + ']</span>' : '');

        var timeline = document.getElementById('timeline');
        timeline.innerHTML = '';

        /* empty state for tagged */
        if (!filteredTagged.length && !filteredUntagged.length) {
          var empty = document.createElement('div');
          empty.className = 'empty';
          empty.innerHTML = '<div class="empty-icon">&#9711;</div><div class="empty-text">Ничего не найдено</div>';
          timeline.appendChild(empty);
          return;
        }

        /* group tagged by 10-BPM buckets */
        var buckets = {};
        filteredTagged.forEach(function (post) {
          var bucketBase = Math.floor(post.bpm_min / 10) * 10;
          var key = bucketBase;
          if (!buckets[key]) buckets[key] = [];
          buckets[key].push(post);
        });
        var sortedKeys = Object.keys(buckets).map(Number).sort(function (a, b) { return a - b; });

        sortedKeys.forEach(function (base) {
          var posts = buckets[base];
          var group = document.createElement('div');
          group.className = 'bpm-group';

          var header = document.createElement('div');
          header.className = 'bpm-group-header';
          var labelSpan = document.createElement('span');
          labelSpan.className = 'bpm-group-label';
          labelSpan.textContent = base + '–' + (base + 10) + ' BPM';
          var countSpan = document.createElement('span');
          countSpan.className = 'bpm-group-count';
          countSpan.textContent = posts.length + ' ' + (posts.length === 1 ? 'пост' : 'постов');
          header.appendChild(labelSpan);
          header.appendChild(countSpan);
          group.appendChild(header);

          posts.forEach(function (post) {
            group.appendChild(makePostRow(post, false));
          });
          timeline.appendChild(group);
        });

        /* untagged section */
        if (filteredUntagged.length) {
          var divider = document.createElement('div');
          divider.className = 'section-divider';
          divider.innerHTML = '<span class="section-divider-label">Без BPM-тега</span>'
            + '<span class="section-divider-note">' + filteredUntagged.length + ' постов — Vocals, SFX, MIDI…</span>';
          timeline.appendChild(divider);
          filteredUntagged.forEach(function (post) {
            timeline.appendChild(makePostRow(post, true));
          });
        }
      }

      render();
    })();
  </script>
```

- [ ] **Step 2: Open in browser — verify data loads**

Open `bpmtimeline.html`. Expect header stats to show real numbers (262 posts, correct pack count, 40 untagged). Timeline shows posts sorted slow → fast, grouped by BPM bucket. Genre pills list all 40 genres.

- [ ] **Step 3: Test BPM slider**

Drag the left thumb to 80. Expect: count updates, posts below 80 BPM disappear, fill track shrinks from left.

- [ ] **Step 4: Test genre filter**

Click `Downtempo`. Expect: only Downtempo posts remain. Click again — all posts return.

- [ ] **Step 5: Test text search**

Type `loopmasters`. Expect: only posts containing Loopmasters packs shown, search terms highlighted in gold.

- [ ] **Step 6: Test reset**

Set genre + BPM range + search, then click Сбросить. Expect: everything cleared, full list restored.

- [ ] **Step 7: Test empty state**

Type a nonsense search string (e.g. `zzzzz`). Expect: circle + "Ничего не найдено".

- [ ] **Step 8: Commit**

```
git add public_html/tools/bpmtimeline.html
git commit -m "feat(bpmtimeline): data layer, filters, and timeline rendering"
```
