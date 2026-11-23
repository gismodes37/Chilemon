/**
 * Regression test: background update checks must only pulse the badge.
 * The confirmation modal opens exclusively on an explicit badge click.
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/dashboard-update.test.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const DASHBOARD_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'dashboard.js'
);

function loadDashboard(overrides = {}) {
  const calls = [];
  const alerts = [];
  const classes = new Set();
  const badge = {
    classList: {
      add: (...names) => names.forEach((name) => classes.add(name)),
      remove: (...names) => names.forEach((name) => classes.delete(name)),
    },
    setAttribute() {},
  };
  const icon = { className: '' };
  const resultEl = { className: '', innerHTML: '' };
  const confirmBtn = { disabled: false, innerHTML: '' };

  const document = {
    addEventListener() {},
    querySelector() { return null; },
    documentElement: { getAttribute() { return 'light'; } },
    getElementById(id) {
      if (id === 'update-status-badge') return badge;
      if (id === 'update-badge-icon') return icon;
      if (id === 'updateModal') return {};
      if (id === 'update-modal-body') return { innerHTML: '' };
      if (id === 'update-result') return resultEl;
      if (id === 'btn-confirm-update') return confirmBtn;
      return null;
    },
  };

  const window = {
    CHILEMON_BASE: './',
    bootstrap: {
      Modal: class {
        show() { calls.push(['modalShow']); }
      },
    },
  };

  const context = vm.createContext({
    window,
    document,
    console: { log() {}, warn() {}, error() {}, debug() {} },
    alert(message) { alerts.push(String(message)); },
    confirm() { return true; },
    setTimeout: overrides.setTimeout || (() => {}),
    setInterval: overrides.setInterval || (() => {}),
    clearTimeout() {},
    clearInterval() {},
    location: { href: '' },
  });

  vm.runInContext(fs.readFileSync(DASHBOARD_JS, 'utf8'), context, {
    filename: 'dashboard.js',
  });

  context.getJson = async () => ({
    ok: true,
    update_available: true,
    summary: 'new commits',
    local_commit: 'aaaaaaa',
    remote_commit: 'bbbbbbb',
  });
  context.afterNodeActionLive = () => {};
  context.postForm = async () => {};

  return { context, calls, alerts, classes, resultEl, confirmBtn };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

async function testPollingDoesNotOpenModal() {
  const { context, calls, classes } = loadDashboard();
  await context.checkForUpdate(false);
  await flush();

  assert.equal(calls.filter((c) => c[0] === 'modalShow').length, 0,
    'background polling opened the modal');
  assert.equal(classes.has('btn-update-available'), true,
    'polling must still pulse the badge');
}

async function testClickOpensModal() {
  const { context, calls } = loadDashboard();
  const checkForUpdate = context.checkForUpdate;
  await checkForUpdate(true);
  await flush();

  assert.equal(calls.filter((c) => c[0] === 'modalShow').length, 1,
    'explicit badge click did not open the modal');
}

async function testClickWithNoUpdateShowsFeedback() {
  const { context, calls, alerts } = loadDashboard();
  context.getJson = async () => ({ ok: true, update_available: false });

  await context.checkForUpdate(true);
  await flush();

  assert.equal(calls.filter((c) => c[0] === 'modalShow').length, 0,
    'no update must not open the modal');
  assert.equal(alerts.length, 1,
    'explicit click with no update gave no feedback');
  assert.match(alerts[0], /versi.n m.s reciente/,
    'feedback must state the installation is current');
}

async function testPollingWithNoUpdateStaysSilent() {
  const { context, alerts } = loadDashboard();
  context.getJson = async () => ({ ok: true, update_available: false });

  await context.checkForUpdate(false);
  await flush();

  assert.equal(alerts.length, 0,
    'background polling must not show an alert');
}

async function testPollingCallbacksPassFalse() {
  const timers = [];
  const { context, calls } = loadDashboard({
    setTimeout: (fn) => { timers.push(fn); },
    setInterval: (fn) => { timers.push(fn); return 1; },
  });

  context.startUpdatePolling();
  assert.equal(timers.length, 2, 'expected initial + interval timers');
  for (const timer of timers) {
    timer();
    await flush();
  }

  assert.equal(calls.filter((c) => c[0] === 'modalShow').length, 0,
    'a polling timer opened the modal');
}

async function testApplyClearsStaleRedError() {
  const { context, resultEl } = loadDashboard();
  let resolvePost;
  context.postForm = () => new Promise((resolve) => { resolvePost = resolve; });

  resultEl.className = 'alert alert-danger mt-3';
  resultEl.innerHTML = '<strong>Error:</strong> Git pull failed. (exit code 1)';

  context.applyUpdate();

  assert.equal(resultEl.className, 'd-none',
    'a previous red error stayed visible while applying');
  assert.equal(resultEl.innerHTML, '',
    'a previous red error stayed in the result area');

  resolvePost({ success: true, message: 'Update applied successfully.' });
  await flush();
}

(async () => {
  await testPollingDoesNotOpenModal();
  await testClickOpensModal();
  await testClickWithNoUpdateShowsFeedback();
  await testPollingWithNoUpdateStaysSilent();
  await testPollingCallbacksPassFalse();
  await testApplyClearsStaleRedError();
  console.log('update modal behavior: 6 passed');
})().catch((err) => {
  console.error(err.message);
  process.exitCode = 1;
});
