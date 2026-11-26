/** Durable T1 boundary regressions (S2). Run: node tests/js/theme-boundary.test.js */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'dashboard.js');

function el(a = {}) {
  const n = {
    className: a.className || '', textContent: '', title: '', attrs: { ...a }, listeners: {},
    getAttribute(k) { return Object.hasOwn(this.attrs, k) ? this.attrs[k] : null; },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    addEventListener(k, f) { (this.listeners[k] ||= []).push(f); },
    click() { (this.listeners.click || []).forEach(f => f()); },
  };
  const set = new Set(n.className.split(/\s+/).filter(Boolean));
  n.classList = {
    add(...x) { x.forEach(v => set.add(v)); },
    remove(...x) { x.forEach(v => set.delete(v)); },
  };
  return n;
}

function load({ theme = 'light', skin = 'clasico', cookies = '' } = {}) {
  const attrs = { 'data-bs-theme': theme, 'data-skin': skin }, writes = [], store = new Map();
  const nodes = {
    '[data-theme-icon] i': [el()],
    '[data-theme-text]': [el()],
    '[data-theme-title]': [el()],
    '[data-theme-skin]': [
      el({ 'data-theme-skin': 'clasico' }),
      el({ 'data-theme-skin': 'obsidian' }),
      el({ 'data-theme-skin': '<svg onload=alert(1)>' }),
    ],
    '[data-theme-mode]': [el({ 'data-theme-mode': 'light' }), el({ 'data-theme-mode': 'dark' })],
  };
  cookies.split(';').map(c => c.trim()).filter(Boolean).forEach(p => {
    const i = p.indexOf('=');
    if (i > 0) store.set(p.slice(0, i), p.slice(i + 1));
  });
  const doc = {
    documentElement: {
      getAttribute: k => attrs[k] ?? null,
      setAttribute: (k, v) => { attrs[k] = String(v); },
    },
    querySelectorAll: s => nodes[s] || [],
    addEventListener() {},
    getElementById() { return null; },
  };
  Object.defineProperty(doc, 'cookie', {
    get: () => [...store].map(([k, v]) => k + '=' + v).join('; '),
    set(v) {
      writes.push(v);
      const p = v.split(';')[0], i = p.indexOf('=');
      if (i <= 0) return;
      const k = p.slice(0, i).trim(), val = p.slice(i + 1).trim();
      if (!val || /max-age=0/i.test(v)) store.delete(k);
      else store.set(k, val);
    },
  });
  const c = vm.createContext({
    window: {}, document: doc, console: { log() {} }, navigator: {}, alert() {}, setTimeout() {},
  });
  vm.runInContext(fs.readFileSync(JS, 'utf8'), c, { filename: 'dashboard.js' });
  return { c, attrs, nodes, store, writes };
}

const eq = (a, b, m) => {
  assert.equal(a.skin, b.skin, m + ' skin');
  assert.equal(a.mode, b.mode, m + ' mode');
};
let pass = 0;
const t = (name, fn) => { fn(); pass++; console.log('PASS ' + name); };

t('S2 injected/colon/oversized values never reach attributes or cookie', () => {
  for (const pair of [
    ['<img src=x onerror=alert(1)>', '"><script>'],
    ['clasico:dark', 'DARK'],
    ['a'.repeat(100000), 'b'.repeat(100000)],
  ]) {
    const x = load();
    eq(x.c.setTheme(pair[0], pair[1]), { skin: 'clasico', mode: 'light' }, 'setTheme');
    assert.equal(x.attrs['data-skin'], 'clasico');
    assert.equal(x.attrs['data-bs-theme'], 'light');
    assert.equal(x.store.get('chilemon_theme'), 'clasico:light');
  }
});

t('S2 tampered DOM attributes and dropdown options stay allowlisted', () => {
  const x = load({ theme: 'evil', skin: '<script>' });
  eq(x.c.window.ThemeManager.read(), { skin: 'clasico', mode: 'light' }, 'read');
  x.c.window.ThemeManager.bindControls();
  x.nodes['[data-theme-skin]'][2].click();
  assert.equal(x.attrs['data-skin'], 'clasico');
});

t('S2 legacy migration ordering and exact cookie format', () => {
  const both = load({ theme: 'dark', cookies: 'chilemon_theme=obsidian:dark; chilemon_darkmode=true' });
  assert.equal(both.c.migrateLegacyTheme(), false);
  assert.equal(both.store.get('chilemon_darkmode'), 'true');

  const legacy = load({ theme: 'dark', cookies: 'chilemon_darkmode=garbage' });
  assert.equal(legacy.c.migrateLegacyTheme(), true);
  assert.equal(legacy.store.get('chilemon_theme'), 'clasico:dark');
  assert.equal(legacy.store.has('chilemon_darkmode'), false);

  const w = load();
  w.c.setTheme('obsidian', 'dark');
  assert.equal(w.writes.at(-1), 'chilemon_theme=obsidian:dark; path=/; max-age=31536000; samesite=Lax');
});

console.log('theme boundary: ' + pass + ' passed');
