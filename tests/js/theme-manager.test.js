/**
 * Regression tests for the dashboard ThemeManager.
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/theme-manager.test.js
 *
 * The server renders the authoritative theme in <html>; JS only syncs
 * controls, persists an allowlisted cookie and flips the mode.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const DASHBOARD_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'dashboard.js'
);

function makeClassList(element) {
  const classes = new Set(String(element.className || '').split(/\s+/).filter(Boolean));
  return {
    add(...names) { names.forEach((name) => classes.add(name)); element.className = [...classes].join(' '); },
    remove(...names) { names.forEach((name) => classes.delete(name)); element.className = [...classes].join(' '); },
    contains(name) { return classes.has(name); },
  };
}

function makeElement(attrs = {}) {
  const el = {
    className: attrs.className || '',
    textContent: attrs.textContent || '',
    title: attrs.title || '',
    attrs: { ...attrs },
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
    },
    setAttribute(name, value) { this.attrs[name] = String(value); },
    listeners: {},
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); },
    click() { (this.listeners.click || []).forEach((fn) => fn()); },
  };
  el.classList = makeClassList(el);
  return el;
}

function makeContext({ theme = 'light', skin = 'clasico', cookies = '' } = {}) {
  const attrs = { 'data-bs-theme': theme, 'data-skin': skin };
  const nodes = {
    '[data-theme-icon] i': [makeElement({ className: 'bi bi-moon-stars' })],
    '[data-theme-text]': [makeElement({ textContent: 'Tema oscuro' })],
    '[data-theme-title]': [makeElement({ title: 'Tema oscuro' })],
    '[data-theme-skin]': [
      makeElement({ 'data-theme-skin': 'clasico', 'aria-checked': 'true' }),
      makeElement({ 'data-theme-skin': 'obsidian', 'aria-checked': 'false' }),
    ],
    '[data-theme-mode]': [
      makeElement({ 'data-theme-mode': 'light', 'aria-checked': 'true' }),
      makeElement({ 'data-theme-mode': 'dark', 'aria-checked': 'false' }),
    ],
  };

  const store = new Map();
  String(cookies).split(';').map((c) => c.trim()).filter(Boolean).forEach((pair) => {
    const idx = pair.indexOf('=');
    if (idx > 0) store.set(pair.slice(0, idx), pair.slice(idx + 1));
  });

  const document = {
    documentElement: {
      getAttribute(name) { return attrs[name] ?? null; },
      setAttribute(name, value) { attrs[name] = String(value); },
    },
    querySelectorAll(selector) { return nodes[selector] || []; },
    addEventListener() {},
    getElementById() { return null; },
  };
  Object.defineProperty(document, 'cookie', {
    get() { return [...store].map(([key, value]) => `${key}=${value}`).join('; '); },
    set(value) {
      const [pair] = String(value).split(';');
      const idx = pair.indexOf('=');
      if (idx <= 0) return;
      const key = pair.slice(0, idx).trim();
      const val = pair.slice(idx + 1).trim();
      if (val === '' || /max-age=0/i.test(String(value))) store.delete(key);
      else store.set(key, val);
    },
  });

  const context = vm.createContext({
    window: {},
    document,
    console: { log() {}, warn() {}, error() {} },
    navigator: {},
    alert() {},
    confirm() { return true; },
    setTimeout() {},
    clearTimeout() {},
    setInterval() {},
    clearInterval() {},
    location: { href: '' },
  });
  vm.runInContext(fs.readFileSync(DASHBOARD_JS, 'utf8'), context, { filename: 'dashboard.js' });
  return { context, attrs, nodes, store };
}

function checked(node) {
  return node.map((el) => el.getAttribute('aria-checked'));
}

function testSetThemeAppliesPersistsAndSyncs() {
  const { context, attrs, nodes, store } = makeContext();
  const result = context.setTheme('obsidian', 'dark');

  assert.equal(result.skin, 'obsidian');
  assert.equal(result.mode, 'dark');
  assert.equal(attrs['data-skin'], 'obsidian');
  assert.equal(attrs['data-bs-theme'], 'dark');
  assert.equal(store.get('chilemon_theme'), 'obsidian:dark');
  assert.deepEqual(checked(nodes['[data-theme-skin]']), ['false', 'true']);
  assert.deepEqual(checked(nodes['[data-theme-mode]']), ['false', 'true']);
  assert.match(nodes['[data-theme-text]'][0].textContent, /Tema claro/);
}

function testToggleKeepsSkinAndIsGloballyAvailable() {
  const { context, attrs, store } = makeContext({ theme: 'dark', skin: 'obsidian' });
  assert.equal(typeof context.toggleTheme, 'function');
  const result = context.toggleTheme();

  assert.equal(result.skin, 'obsidian');
  assert.equal(result.mode, 'light');
  assert.equal(attrs['data-skin'], 'obsidian', 'toggle must not change the skin');
  assert.equal(attrs['data-bs-theme'], 'light');
  assert.equal(store.get('chilemon_theme'), 'obsidian:light');
}

function testAllowlistRejectsInjectedValues() {
  const { context, attrs, store } = makeContext();
  const result = context.setTheme('<script>', 'neon');

  assert.equal(result.skin, 'clasico');
  assert.equal(result.mode, 'light');
  assert.equal(attrs['data-skin'], 'clasico');
  assert.equal(attrs['data-bs-theme'], 'light');
  assert.equal(store.get('chilemon_theme'), 'clasico:light');
}

function testLegacyCookieMigratesOnce() {
  const { context, store } = makeContext({
    theme: 'dark',
    skin: 'clasico',
    cookies: 'chilemon_darkmode=true',
  });
  assert.equal(context.migrateLegacyTheme(), true);
  assert.equal(store.get('chilemon_theme'), 'clasico:dark');
  assert.equal(store.has('chilemon_darkmode'), false);
  assert.equal(context.migrateLegacyTheme(), false, 'migration must be idempotent');
}

function testDropdownOptionsAreWired() {
  const { context, nodes, store, attrs } = makeContext();
  context.window.ThemeManager.bindControls();

  nodes['[data-theme-skin]'][1].click();
  assert.equal(store.get('chilemon_theme'), 'obsidian:light');
  assert.equal(attrs['data-skin'], 'obsidian');

  nodes['[data-theme-mode]'][1].click();
  assert.equal(store.get('chilemon_theme'), 'obsidian:dark');
  assert.deepEqual(checked(nodes['[data-theme-skin]']), ['false', 'true']);
}

function testObsidianModeCanReturnToLight() {
  const { context, nodes, store, attrs } = makeContext({
    theme: 'dark',
    skin: 'obsidian',
  });
  context.window.ThemeManager.bindControls();

  nodes['[data-theme-mode]'][0].click();

  assert.equal(store.get('chilemon_theme'), 'obsidian:light');
  assert.equal(attrs['data-skin'], 'obsidian');
  assert.equal(attrs['data-bs-theme'], 'light');
  assert.deepEqual(checked(nodes['[data-theme-mode]']), ['true', 'false']);
}

function testServerRenderedThemeIsAuthoritative() {
  const { context } = makeContext({
    theme: 'dark',
    skin: 'obsidian',
    cookies: 'chilemon_theme=clasico:light',
  });
  const current = context.window.ThemeManager.read();
  assert.equal(current.skin, 'obsidian');
  assert.equal(current.mode, 'dark');
}

(async () => {
  testSetThemeAppliesPersistsAndSyncs();
  testToggleKeepsSkinAndIsGloballyAvailable();
  testAllowlistRejectsInjectedValues();
  testLegacyCookieMigratesOnce();
  testDropdownOptionsAreWired();
  testObsidianModeCanReturnToLight();
  testServerRenderedThemeIsAuthoritative();
  console.log('theme manager: 7 passed');
})().catch((err) => {
  console.error(err.message);
  process.exitCode = 1;
});
