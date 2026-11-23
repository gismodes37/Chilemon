/**
 * Behavior tests for the dashboard spectrum visualizer and its PTT consumer.
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/audio-visualizer.test.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const VISUALIZER_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'audio-visualizer.js'
);
const WIDGET_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'ptt-widget.js'
);

const visualizerSource = fs.readFileSync(VISUALIZER_JS, 'utf8');
const widgetSource = fs.readFileSync(WIDGET_JS, 'utf8');

function makeClassList() {
  const set = new Set();
  return {
    set,
    add(...names) { names.forEach((name) => set.add(name)); },
    remove(...names) { names.forEach((name) => set.delete(name)); },
    toggle(name, force) {
      const on = force === undefined ? !set.has(name) : Boolean(force);
      if (on) set.add(name); else set.delete(name);
      return on;
    },
    contains(name) { return set.has(name); },
  };
}

function makeCtx() {
  const ops = [];
  const ctx = {
    ops,
    clearRect(...args) { ops.push(['clearRect', ...args]); },
    fillRect(...args) { ops.push(['fillRect', ...args]); },
    createLinearGradient(...args) {
      const gradient = {
        stops: [],
        addColorStop(offset, color) { this.stops.push([offset, color]); },
      };
      ops.push(['gradient', ...args, gradient]);
      return gradient;
    },
  };
  Object.defineProperty(ctx, 'fillStyle', {
    set(value) { ops.push(['fillStyle', typeof value === 'object' ? 'gradient' : value]); },
    get() { return null; },
  });
  Object.defineProperty(ctx, 'shadowBlur', {
    set(value) { if (value) ops.push(['shadowBlur', value]); },
    get() { return 0; },
  });
  return ctx;
}

function makeVizHarness({
  reducedMotion = false,
  withCanvas = true,
  canvasWidth = 640,
  dpr = 1,
} = {}) {
  const ctx = makeCtx();
  const card = { classList: makeClassList() };
  const chip = { textContent: '', dataset: {} };
  const status = { textContent: '', dataset: {} };
  const dbReadout = { textContent: '' };
  const warns = [];
  const raf = [];
  let now = 0;

  const canvas = withCanvas ? {
    width: 0,
    height: 0,
    getContext() { return ctx; },
    getBoundingClientRect() { return { width: canvasWidth, height: 52 }; },
    closest(selector) { return selector === '.audio-visualizer-card' ? card : null; },
  } : null;

  const elements = {
    'audio-canvas': canvas,
    'audio-direction-chip': chip,
    'audio-state-readout': status,
    'audio-db-readout': dbReadout,
  };

  const context = vm.createContext({
    document: { getElementById(id) { return elements[id] || null; } },
    window: {
      devicePixelRatio: dpr,
      addEventListener() {},
      removeEventListener() {},
      matchMedia(query) {
        return { matches: reducedMotion && /prefers-reduced-motion/.test(query) };
      },
    },
    performance: { now() { return now; } },
    requestAnimationFrame(callback) { raf.push(callback); return raf.length; },
    console: { warn(message) { warns.push(String(message)); }, log() {}, error() {} },
  });

  vm.runInContext(
    `${visualizerSource}\nglobalThis.AudioVisualizer = AudioVisualizer;`,
    context,
    { filename: 'audio-visualizer.js' }
  );

  const viz = new context.AudioVisualizer('audio-canvas');

  return {
    viz,
    AudioVisualizer: context.AudioVisualizer,
    canvas,
    ctx,
    card,
    chip,
    status,
    state: status,
    dbReadout,
    warns,
    raf,
    setNow(value) { now = value; },
    frame(timestamp) {
      const callback = raf.shift();
      assert.ok(callback, 'expected a queued animation frame');
      callback(timestamp);
    },
  };
}

const fullSpectrum = (value = 0.5) => new Array(16).fill(value);

function loadWidget({ fakeLevel = null } = {}) {
  const feedCalls = [];
  const txCalls = [];
  const fakeVisualizer = {
    feedMetadata(...args) { feedCalls.push(args); },
    setTransmitting(value) { txCalls.push(value); },
    destroy() {},
    getLevel() { return fakeLevel; },
  };

  const context = vm.createContext({
    document: {
      getElementById(id) { return id === 'audio-canvas' ? {} : null; },
      body: { appendChild() {}, removeChild() {} },
      createElement() { return { style: {}, classList: makeClassList() }; },
      addEventListener() {},
      removeEventListener() {},
    },
    window: { addEventListener() {}, removeEventListener() {} },
    WebSocket: class { static OPEN = 1; send() {} close() {} },
    sessionStorage: {
      getItem() { return null; },
      setItem() {},
      removeItem() {},
    },
    setTimeout() {},
    clearTimeout() {},
    console: { log() {}, warn() {}, error() {} },
    AudioVisualizer: function FakeVisualizer() { return fakeVisualizer; },
  });

  vm.runInContext(
    `${widgetSource}\nglobalThis.PTTWidget = PTTWidget;`,
    context,
    { filename: 'ptt-widget.js' }
  );

  const widget = new context.PTTWidget();
  widget._initVisualizer();
  widget.volumeFill = { style: {} };
  return { widget, fakeVisualizer, feedCalls, txCalls };
}

test('weak RMS is visible in the dB window without saturating speech', () => {
  const h = makeVizHarness();
  h.viz.feedMetadata(0.0015, fullSpectrum(0.4), 'rx');
  const weak = h.viz.getLevel();

  assert.ok(weak > 0.1, `weak signal mapped to ${weak}, expected > 0.1`);
  assert.ok(weak < 0.6, `weak signal mapped to ${weak}, expected < 0.6`);

  h.viz.feedMetadata(0.08, fullSpectrum(0.8), 'rx');
  const speech = h.viz.getLevel();
  assert.ok(speech > weak, 'speech must be louder than a weak signal');
  assert.ok(speech < 1, `speech mapped to ${speech}, expected below saturation`);
  assert.match(h.dbReadout.textContent, /dB/);
});

test('per-direction noise floor follows slow ambient changes, not bursts', () => {
  const h = makeVizHarness();
  const initial = h.viz.getNoiseFloor('tx');

  for (let i = 0; i < 80; i++) {
    h.setNow(i * 20);
    h.viz.feedMetadata(0.0025, fullSpectrum(0.05), 'tx'); // about -52 dB
  }
  const adapted = h.viz.getNoiseFloor('tx');
  assert.ok(adapted > initial + 2, `floor stayed at ${adapted} from ${initial}`);
  assert.equal(h.viz.getNoiseFloor('rx'), initial, 'RX floor must stay independent');

  const beforeBurst = h.viz.getNoiseFloor('tx');
  h.viz.feedMetadata(0.2, fullSpectrum(1), 'tx'); // about -14 dB voice burst
  assert.ok(
    Math.abs(h.viz.getNoiseFloor('tx') - beforeBurst) < 0.5,
    'a loud burst must not raise the ambient floor'
  );
});

test('legacy 4-band spectrum interpolates into 16 bands and 32 log-spaced bars', () => {
  const h = makeVizHarness();
  h.viz.feedMetadata(0.05, [0, 0, 0, 1], 'rx');

  const bands = h.viz.getBands();
  assert.equal(bands.length, 16);
  assert.ok(Math.abs(bands[0]) < 1e-6);
  assert.ok(Math.abs(bands[15] - 1) < 1e-6);
  assert.ok(Math.abs(bands[13] - 0.6) < 1e-6, `band 13 was ${bands[13]}`);
  for (let i = 1; i < bands.length; i++) {
    assert.ok(bands[i] >= bands[i - 1] - 1e-6, 'interpolated bands must be monotone');
  }

  assert.equal(h.viz.getBars().length, 32);
  h.frame(20);
  const bars = h.viz.getBars();
  assert.ok(bars.some((value) => value > 0.05), 'interpolated spectrum made no bars');
  assert.ok(bars[0] < 0.02, 'energy in band 15 must not light the lowest band');
  assert.ok(bars.slice(24).some((value) => value > 0.05),
    'energy in band 15 must reach the high end of the log-spaced bars');
});

test('direction switches palette, chip and activity class; missing dir defaults to TX', () => {
  const h = makeVizHarness();
  h.viz.feedMetadata(0.05, fullSpectrum(0.7), 'rx');
  assert.equal(h.chip.textContent, 'RX');
  assert.equal(h.chip.dataset.dir, 'rx');
  assert.match(h.viz.getPalette().primary, /22d3ee/i);
  h.frame(20);
  assert.equal(h.card.classList.contains('active'), true);
  assert.equal(h.card.classList.contains('transmitting'), false);

  h.viz.feedMetadata(0.05, fullSpectrum(0.7), 'tx');
  assert.equal(h.chip.textContent, 'TX');
  assert.equal(h.chip.dataset.dir, 'tx');
  assert.match(h.viz.getPalette().primary, /f59e0b/i);
  h.frame(40);
  assert.equal(h.card.classList.contains('transmitting'), true);
  assert.equal(h.card.classList.contains('active'), false);

  h.viz.feedMetadata(0.05, fullSpectrum(0.7));
  assert.equal(h.viz.getDirection(), 'tx', 'omitted dir must default to tx');
  assert.equal(h.chip.textContent, 'TX');
});

test('R2-LEVEL-TARGET: displayed level keeps chasing the target between feeds', () => {
  const h = makeVizHarness();
  h.viz.feedMetadata(0.1, fullSpectrum(1), 'rx');
  h.frame(0);
  h.frame(16);
  const early = h.viz._displayLevel;
  h.frame(32);
  h.frame(48);
  const late = h.viz._displayLevel;
  assert.ok(early > 0.15, `level did not rise on first frame: ${early}`);
  assert.ok(late > early + 0.15, `level stalled between feeds: early=${early} late=${late}`);
});

test('silence decay is driven by elapsed time, not the number of frames', () => {
  function decay(steps, stepMs) {
    const h = makeVizHarness();
    h.viz.feedMetadata(0.1, fullSpectrum(1), 'rx');
    h.frame(10);
    const before = h.viz.getBars()[0];
    assert.ok(before > 0.05, 'setup did not raise a bar');

    for (let i = 1; i <= steps; i++) {
      h.frame(10 + i * stepMs);
    }
    return { after: h.viz.getBars()[0], before };
  }

  const few = decay(4, 250); // 1000 ms
  const many = decay(20, 50); // same 1000 ms

  assert.ok(
    Math.abs(few.after - many.after) < 0.03,
    `frame count changed decay: ${few.after} vs ${many.after}`
  );
  assert.ok(few.after < few.before * 0.2, 'bars did not decay after one second silent');
});

test('reduced motion renders state without starting an animation loop or per-bar glow', () => {
  const h = makeVizHarness({ reducedMotion: true });
  assert.equal(h.raf.length, 0, 'reduced motion must not schedule frames');

  h.viz.feedMetadata(0.05, fullSpectrum(0.8), 'rx');
  assert.equal(h.state.textContent, 'VOZ');
  assert.equal(h.chip.textContent, 'RX');
  assert.ok(h.ctx.ops.some((op) => op[0] === 'fillRect'), 'no static render occurred');
  assert.equal(h.raf.length, 0, 'feed must not start the animation loop');
  assert.ok(
    !h.ctx.ops.some((op) => op[0] === 'shadowBlur'),
    'canvas glow must not use per-bar shadowBlur'
  );
});

test('existing setTransmitting, destroy and missing-canvas behavior still holds', () => {
  const h = makeVizHarness();
  h.viz.feedMetadata(0.05, fullSpectrum(0.5), 'rx');
  h.viz.setTransmitting(true);
  h.frame(20);
  assert.equal(h.card.classList.contains('transmitting'), true);

  h.viz.setTransmitting(false);
  h.frame(40);
  assert.equal(h.card.classList.contains('transmitting'), false);

  h.viz.destroy();
  const queued = h.raf.shift();
  assert.ok(queued);
  queued(60);
  assert.equal(h.raf.length, 0, 'destroy must stop the render loop');

  const missing = makeVizHarness({ withCanvas: false });
  assert.equal(missing.viz.ctx, undefined);
  assert.equal(missing.warns.length, 1, 'missing canvas must warn once');
});

test('bar profile is a slim 32-needle trace at dashboard width', () => {
  const h = makeVizHarness({ canvasWidth: 640 });
  const p = h.viz.getBarProfile();

  assert.equal(p.count, 32, 'wide canvases must keep all 32 log-spaced bars');
  assert.ok(p.litCss >= 2 && p.litCss <= 5,
    `lit width ${p.litCss} CSS px is not a slim instrument needle`);
  assert.ok(p.gapCss >= 1, 'bars need a visible gap');
  assert.ok(p.slotCss > p.litCss, 'bars must not fill their slot');
});

test('narrow canvases reduce bar count instead of collapsing lit width', () => {
  const narrow = makeVizHarness({ canvasWidth: 64 });
  const p = narrow.viz.getBarProfile();

  assert.ok(p.count < 32, `expected fewer rendered bars, got ${p.count}`);
  assert.ok(p.count >= 3, `too few rendered bars: ${p.count}`);
  assert.ok(p.litCss >= 2, `lit width collapsed to ${p.litCss} CSS px`);
  assert.ok(p.gapCss >= 1, 'bars still need a gap at narrow width');

  const retina = makeVizHarness({ canvasWidth: 320, dpr: 2 });
  const rp = retina.viz.getBarProfile();
  assert.equal(retina.canvas.width, 640, 'backing store must follow devicePixelRatio');
  assert.ok(rp.litDevice >= 4, 'a 2 CSS px needle needs 2 device px per dpr unit');
});

test('peak markers and graticule follow the chosen bar profile', () => {
  const h = makeVizHarness({ canvasWidth: 320 });
  h.viz.feedMetadata(0.1, fullSpectrum(1), 'rx');
  h.frame(20);

  const p = h.viz.getBarProfile();
  const litRects = h.ctx.ops.filter(
    (op) => op[0] === 'fillRect' && Math.abs(op[3] - p.litDevice) < 1e-6
  );
  assert.equal(litRects.length, 2 * p.count,
    'every rendered column needs one bar and one peak-hold marker');

  const verticalGrid = h.ctx.ops.filter(
    (op) => op[0] === 'fillRect' && op[3] === 1 && op[4] === h.canvas.height
  );
  assert.ok(verticalGrid.length > 0, 'graticule lost its vertical divisions');
  for (const line of verticalGrid) {
    const columns = line[1] / p.slotDevice;
    assert.ok(Math.abs(columns % 4) < 1e-6,
      `graticule line at column ${columns} is not aligned to 4-bar groups`);
  }
});

test('PTT widget forwards msg.dir and no longer overrides feedPCM', () => {
  const { widget, fakeVisualizer, feedCalls } = loadWidget();
  assert.equal(Object.hasOwn(fakeVisualizer, 'feedPCM'), false,
    'the visualizer must keep its own feedPCM-free interface');

  const spectrum = [0.1, 0.2, 0.3, 0.4];
  widget._handleJSON({ type: 'audio_level', rms: 0.01, spectrum, dir: 'rx' });

  assert.equal(feedCalls.length, 1);
  assert.deepEqual(feedCalls[0], [0.01, spectrum, 'rx']);

  widget._handleJSON({ type: 'audio_level', rms: 0.01, spectrum });
  assert.equal(feedCalls[1][2], undefined,
    'widget must pass dir through, leaving the default to the visualizer');
});

test('PTT volume meter uses the dB mapping for weak audio', () => {
  const withoutViz = loadWidget();
  withoutViz.widget._visualizer = null;
  withoutViz.widget._pushVolume(0.003); // about -50 dB
  const weakWidth = parseInt(withoutViz.widget.volumeFill.style.width, 10);
  assert.ok(weakWidth > 5, `weak audio showed only ${weakWidth}% on the meter`);

  const withViz = loadWidget({ fakeLevel: 0.42 });
  withViz.widget._pushVolume(0.003);
  assert.equal(withViz.widget.volumeFill.style.width, '42%',
    'the meter should use the visualizer dB level when available');
});
