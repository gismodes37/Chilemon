/**
 * Regression test: the favorites modal "Conectar" button must issue a POST
 * to api/connect.php through linkAndCallNode(). Reproduces the live failure
 * where saving a favorite worked but clicking Conectar produced no request.
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/favorites-connect.test.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const DASHBOARD_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'dashboard.js'
);
const NODE = '54614';

function makeContext(calls) {
  const listeners = {};

  const connectButton = {
    dataset: {},
    innerHTML: 'original',
    disabled: false,
    getAttribute(name) {
      assert.equal(name, 'data-fav-connect');
      return NODE;
    },
    addEventListener(type, handler) {
      listeners[type] = handler;
    },
  };

  const tbody = {
    innerHTML: '',
    querySelectorAll(selector) {
      if (selector === '[data-fav-connect]') return [connectButton];
      if (selector === '[data-fav-delete]' || selector === '[data-fav-edit]') return [];
      throw new Error(`unexpected selector: ${selector}`);
    },
  };

  const pagination = {
    style: { display: '' },
  };

  const document = {
    addEventListener() {},
    querySelector() { return null; },
    documentElement: { getAttribute() { return 'light'; } },
    getElementById(id) {
      if (id === 'fav_tbody') return tbody;
      if (id === 'fav-pagination') return pagination;
      if (id === 'fav-page-info') return { textContent: '' };
      if (id === 'fav-page-prev') return { disabled: false };
      if (id === 'fav-page-next') return { disabled: false };
      return null;
    },
  };

  const pttWidget = {
    connected: true,
    placeCall(node) { calls.push(['placeCall', String(node)]); },
  };

  const context = vm.createContext({
    window: { CHILEMON_BASE: './', pttWidget },
    document,
    console: { log() {}, warn() {}, error() {} },
    alert() {},
    // A native confirm() used to gate this button; returning false proves the
    // connect still fires without it. In the live Firefox session the dialog
    // was dismissed and no POST reached the server.
    confirm() { calls.push(['confirm', NODE]); return false; },
    setTimeout() {},
    clearTimeout() {},
    setInterval() {},
    clearInterval() {},
    location: { href: '' },
  });

  context.__listeners = listeners;
  return context;
}

function loadDashboard(calls) {
  const context = makeContext(calls);
  const source = fs.readFileSync(DASHBOARD_JS, 'utf8');
  vm.runInContext(source, context, { filename: 'dashboard.js' });

  context.postForm = async (url, data) => {
    calls.push(['postForm', url, String(data.node)]);
  };
  context.afterNodeActionLive = () => { calls.push(['afterNodeActionLive']); };
  return context;
}

async function main() {
  const calls = [];
  const ctx = loadDashboard(calls);

  // Simulate favoritesReload() having loaded the list from the API.
  ctx.renderFavorites([{ node_id: NODE, alias: 'Santiago', description: 'Test' }]);

  assert.ok(
    typeof ctx.__listeners.click === 'function',
    'favorites connect button has no click listener bound'
  );

  await ctx.__listeners.click();

  const connectCalls = calls.filter(
    (c) => c[0] === 'postForm' && c[1] === 'api/connect.php'
  );
  assert.equal(
    connectCalls.length,
    1,
    `expected one api/connect.php POST, got ${JSON.stringify(calls)}`
  );
  assert.equal(connectCalls[0][2], NODE);

  const placeCalls = calls.filter((c) => c[0] === 'placeCall');
  assert.equal(placeCalls.length, 1, 'companion placeCall() was NOT invoked');
  assert.equal(placeCalls[0][1], NODE);

  console.log(`favorites connect button: ${connectCalls.length} POST, ${placeCalls.length} placeCall — passed`);
}

main().catch((err) => {
  console.error(err.message);
  process.exit(1);
});
