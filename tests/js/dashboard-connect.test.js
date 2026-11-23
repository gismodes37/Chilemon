/**
 * Regression test: every dashboard connect path must link the node AND
 * open the companion's IAX2 audio channel.
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/dashboard-connect.test.js
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
  const nodeInput = { value: NODE };
  const document = {
    addEventListener() {},
    querySelector() { return null; },
    documentElement: { getAttribute() { return 'light'; } },
    getElementById(id) {
      if (id === 'node-number') return nodeInput;
      return null;
    },
  };

  const pttWidget = {
    connected: true,
    callActive: false,
    pttActive: false,
    placeCall(node) { calls.push(['placeCall', String(node)]); },
    unkeyPtt() { calls.push(['unkeyPtt']); },
    hangupCall() { calls.push(['hangupCall']); },
  };

  return vm.createContext({
    window: { CHILEMON_BASE: './', pttWidget },
    document,
    console: { log() {}, warn() {}, error() {} },
    alert() {},
    confirm() { return true; },
    setTimeout() {},
    clearTimeout() {},
    setInterval() {},
    clearInterval() {},
    location: { href: '' },
  });
}

function loadDashboard(calls) {
  const context = makeContext(calls);
  const source = fs.readFileSync(DASHBOARD_JS, 'utf8');
  vm.runInContext(source, context, { filename: 'dashboard.js' });

  // Top-level function declarations become context globals; replace the
  // network/UI side effects with spies before exercising the connect paths.
  context.postForm = async (url, data) => { calls.push(['postForm', url, data.node]); };
  context.afterNodeActionLive = () => { calls.push(['afterNodeActionLive']); };
  return context;
}

function assertLinkedAndCalled(calls, label) {
  const connectCalls = calls.filter((c) => c[0] === 'postForm' && c[1] === 'api/connect.php');
  assert.equal(connectCalls.length, 1, `${label}: expected one api/connect.php call`);
  assert.equal(connectCalls[0][2], NODE, `${label}: wrong node in API call`);

  const placeCalls = calls.filter((c) => c[0] === 'placeCall');
  assert.equal(placeCalls.length, 1, `${label}: companion placeCall() was NOT invoked`);
  assert.equal(placeCalls[0][1], NODE, `${label}: wrong node in companion call`);

  assert.equal(
    calls.filter((c) => c[0] === 'afterNodeActionLive').length,
    1,
    `${label}: expected one dashboard refresh`,
  );
}

async function testQuickConnect() {
  const calls = [];
  const ctx = loadDashboard(calls);
  await ctx.connectToNode();
  assertLinkedAndCalled(calls, 'quick connect');
}

async function testNodeTableConnect() {
  const calls = [];
  const ctx = loadDashboard(calls);
  await ctx.connectToSpecificNode(NODE);
  assertLinkedAndCalled(calls, 'node table');
}

async function testFavoritesConnect() {
  const calls = [];
  const ctx = loadDashboard(calls);
  let onClick;
  const button = {
    dataset: {},
    innerHTML: 'original',
    disabled: false,
    getAttribute(name) { return name === 'data-fav-connect' ? NODE : null; },
    addEventListener(event, fn) { if (event === 'click') onClick = fn; },
  };
  const tbody = {
    querySelectorAll(selector) {
      return selector === '[data-fav-connect]' ? [button] : [];
    },
  };
  ctx._favBindRowEvents(tbody);
  assert.equal(typeof onClick, 'function', 'favorites button has no click handler');
  await onClick();
  assertLinkedAndCalled(calls, 'favorites');
}

async function testDisconnectClearsCallState() {
  const calls = [];
  const ctx = loadDashboard(calls);
  // The companion may already have auto-hung up the idle call.
  ctx.window.pttWidget.callActive = false;
  await ctx.disconnectFromNode(NODE);

  assert.equal(
    calls.filter((c) => c[0] === 'hangupCall').length,
    1,
    'disconnect must clear the remembered call even when callActive is false',
  );
  assert.equal(
    calls.filter((c) => c[0] === 'postForm' && c[1] === 'api/disconnect.php').length,
    1,
    'disconnect must still unlink the node',
  );
}

(async () => {
  await testQuickConnect();
  await testNodeTableConnect();
  await testFavoritesConnect();
  await testDisconnectClearsCallState();
  console.log('dashboard connect paths: 4 passed');
})().catch((err) => {
  console.error(err.message);
  process.exitCode = 1;
});
