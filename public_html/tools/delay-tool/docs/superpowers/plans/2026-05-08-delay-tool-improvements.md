# Delay Tool — Device Selection + Buffer Reset

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add input/output device selection dropdowns and a buffer reset button to delay.html.

**Architecture:** Single HTML file. All changes go into delay.html — HTML structure, CSS block, and JS block. No build tools, no dependencies. Manual browser verification in Chrome replaces automated tests (Web Audio API cannot be unit-tested without a browser).

**Tech Stack:** Vanilla JS, Web Audio API, getUserMedia, MediaDevices.enumerateDevices(), AudioContext.setSinkId() (Chrome 110+)

---

## File Structure

- Modify: `delay.html` — all four tasks touch this file only

---

### Task 1: Device selection UI — HTML + CSS

**Files:**
- Modify: `delay.html`

- [ ] **Step 1: Add CSS for device dropdowns**

Inside the `<style>` block, after the `.title` rule (after line 33), add:

```css
  .device-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
  }

  .device-select {
    width: 100%;
    padding: 10px 12px;
    background: #111;
    border: 1px solid #3a3a3a;
    color: #e8e4d8;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    letter-spacing: 0.1em;
    cursor: pointer;
    outline: none;
    -webkit-appearance: none;
    appearance: none;
  }

  .device-select:disabled {
    opacity: 0.35;
    cursor: default;
  }
```

- [ ] **Step 2: Add Reset button CSS**

In the same `<style>` block, after `.status.err`:

```css
  .reset-btn {
    width: 100%;
    padding: 10px;
    background: transparent;
    border: 1px solid #222;
    color: #333;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    cursor: default;
    margin-top: 20px;
    transition: border-color 0.2s, color 0.2s;
  }

  .reset-btn:not(:disabled) {
    border-color: #3a3a3a;
    color: #e8e4d8;
    cursor: pointer;
  }

  .reset-btn:not(:disabled):hover { border-color: #888; }
```

- [ ] **Step 3: Add dropdown HTML above connect button**

In the `<body>`, replace:

```html
  <button class="connect-btn" id="btn">Подключить вход</button>
```

with:

```html
  <div class="device-item">
    <span class="control-label">Вход</span>
    <select id="inputSelect" class="device-select"></select>
  </div>
  <div class="device-item" id="outputWrap">
    <span class="control-label">Выход</span>
    <select id="outputSelect" class="device-select"></select>
  </div>

  <button class="connect-btn" id="btn">Подключить вход</button>
```

- [ ] **Step 4: Add Reset button HTML below visualizer**

After `<div class="viz-wrap">...</div>`, add:

```html
  <button class="reset-btn" id="resetBtn" disabled>Сброс</button>
```

- [ ] **Step 5: Verify layout in Chrome**

Open `delay.html` in Chrome. Check:
- Two labels "Вход" and "Выход" appear above "Подключить вход"
- Selects are empty (no devices yet — JS not wired)
- "Сброс" button appears below the visualizer, visually dim
- Layout matches the rest: dark, monospace, same width

- [ ] **Step 6: Commit**

```bash
git add delay.html
git commit -m "feat: add device select and reset button UI"
```

---

### Task 2: Device enumeration

**Files:**
- Modify: `delay.html` — JS block

- [ ] **Step 1: Add `currentStream` to globals**

Find the line:

```javascript
let ctx, sourceNode, delayNode, feedbackGain, dryGain, wetGain, analyser, animId;
```

Replace with:

```javascript
let ctx, sourceNode, delayNode, feedbackGain, dryGain, wetGain, analyser, animId;
let currentStream = null;
```

- [ ] **Step 2: Add `initDevices` function**

Add after the globals block (before `function updateTimeDisplay()`):

```javascript
async function initDevices() {
  try {
    const tmp = await navigator.mediaDevices.getUserMedia({ audio: true });
    tmp.getTracks().forEach(t => t.stop());
  } catch(e) {
    status.textContent = 'Нет доступа к микрофону';
    status.className = 'status err';
    return;
  }

  const devices = await navigator.mediaDevices.enumerateDevices();
  const inputs = devices.filter(d => d.kind === 'audioinput');
  const outputs = devices.filter(d => d.kind === 'audiooutput');

  const inputSelect = document.getElementById('inputSelect');
  const outputSelect = document.getElementById('outputSelect');

  inputSelect.innerHTML = inputs.map((d, i) =>
    `<option value="${d.deviceId}">${d.label || 'Вход ' + (i + 1)}</option>`
  ).join('');

  outputSelect.innerHTML = outputs.map((d, i) =>
    `<option value="${d.deviceId}">${d.label || 'Выход ' + (i + 1)}</option>`
  ).join('');

  if (typeof AudioContext.prototype.setSinkId !== 'function') {
    document.getElementById('outputWrap').style.display = 'none';
  }
}

document.addEventListener('DOMContentLoaded', initDevices);
```

- [ ] **Step 3: Verify in Chrome**

Open `delay.html`. Chrome will ask for microphone permission — allow it.
- Dropdowns populate with real device names (e.g. "MacBook Pro Microphone", "USB Audio Device")
- If only one device exists it is auto-selected
- If setSinkId is not supported, the "Выход" block disappears

- [ ] **Step 4: Commit**

```bash
git add delay.html
git commit -m "feat: enumerate audio devices on load"
```

---

### Task 3: Wire device selection into audio graph

**Files:**
- Modify: `delay.html` — JS block (btn click handler, stop function)

- [ ] **Step 1: Use selected input device in getUserMedia**

In the `btn.addEventListener('click', async () => { ... })`, find:

```javascript
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false }
    });
```

Replace with:

```javascript
    const inputSelect = document.getElementById('inputSelect');
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: {
        deviceId: inputSelect.value ? { exact: inputSelect.value } : undefined,
        echoCancellation: false,
        noiseSuppression: false,
        autoGainControl: false
      }
    });
    currentStream = stream;
```

- [ ] **Step 2: Apply selected output device via setSinkId**

In the same click handler, find:

```javascript
    ctx = new (window.AudioContext || window.webkitAudioContext)();
    sourceNode = ctx.createMediaStreamSource(stream);
```

Add between them:

```javascript
    const outputSelect = document.getElementById('outputSelect');
    if (outputSelect.value && typeof ctx.setSinkId === 'function') {
      await ctx.setSinkId(outputSelect.value);
    }
```

- [ ] **Step 3: Disable dropdowns after connect, enable reset button**

In the same click handler, find:

```javascript
    running = true;
    btn.textContent = 'Отключить';
    btn.classList.add('active');
    status.textContent = 'Слушаем';
    status.className = 'status on';
```

Add after:

```javascript
    document.getElementById('inputSelect').disabled = true;
    document.getElementById('outputSelect').disabled = true;
    document.getElementById('resetBtn').disabled = false;
```

- [ ] **Step 4: Stop stream tracks and re-enable dropdowns on disconnect**

In the `stop()` function, find:

```javascript
  if (sourceNode) sourceNode.disconnect();
  if (ctx) ctx.close();
```

Replace with:

```javascript
  if (sourceNode) sourceNode.disconnect();
  if (ctx) ctx.close();
  if (currentStream) {
    currentStream.getTracks().forEach(t => t.stop());
    currentStream = null;
  }
  document.getElementById('inputSelect').disabled = false;
  document.getElementById('outputSelect').disabled = false;
  document.getElementById('resetBtn').disabled = true;
```

- [ ] **Step 5: Verify in Chrome**

Connect a USB audio interface. Open `delay.html`.
- Select the USB interface as "Вход", laptop speakers as "Выход"
- Click "Подключить вход" — play something into the instrument
- Audio should come out of laptop speakers with delay effect
- Dropdowns should be greyed out while connected
- "Сброс" button should become active (visible border, light text)
- Click "Отключить" — dropdowns should be re-enabled

- [ ] **Step 6: Commit**

```bash
git add delay.html
git commit -m "feat: wire device selection into audio connect flow"
```

---

### Task 4: Reset buffer

**Files:**
- Modify: `delay.html` — JS block

- [ ] **Step 1: Add `resetBuffer` function**

Add after the `stop()` function:

```javascript
async function resetBuffer() {
  if (!running || !currentStream) return;

  cancelAnimationFrame(animId);
  if (sourceNode) sourceNode.disconnect();
  await ctx.close();

  ctx = new (window.AudioContext || window.webkitAudioContext)();

  const outputSelect = document.getElementById('outputSelect');
  if (outputSelect.value && typeof ctx.setSinkId === 'function') {
    await ctx.setSinkId(outputSelect.value);
  }

  sourceNode = ctx.createMediaStreamSource(currentStream);

  delayNode = ctx.createDelay(20.0);
  delayNode.delayTime.value = parseFloat(timeSlider.value);

  feedbackGain = ctx.createGain();
  feedbackGain.gain.value = parseInt(fbSlider.value) / 100;

  dryGain = ctx.createGain();
  wetGain = ctx.createGain();
  const mix = parseInt(mixSlider.value) / 100;
  dryGain.gain.value = 1 - mix;
  wetGain.gain.value = mix;

  analyser = ctx.createAnalyser();
  analyser.fftSize = 512;

  sourceNode.connect(dryGain);
  sourceNode.connect(delayNode);
  delayNode.connect(feedbackGain);
  feedbackGain.connect(delayNode);
  delayNode.connect(wetGain);
  dryGain.connect(analyser);
  wetGain.connect(analyser);
  analyser.connect(ctx.destination);

  drawLoop();
}

document.getElementById('resetBtn').addEventListener('click', resetBuffer);
```

- [ ] **Step 2: Verify in Chrome**

- Connect audio, play into the instrument to fill the delay buffer with echoes
- Click "Сброс" — echoes should stop immediately, slider values and device selection unchanged
- Continue playing — delay works as before with empty buffer
- Waveform visualizer continues running without interruption

- [ ] **Step 3: Commit**

```bash
git add delay.html
git commit -m "feat: reset audio buffer without disconnecting"
```
