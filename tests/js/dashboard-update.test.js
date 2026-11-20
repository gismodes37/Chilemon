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
  const classes = new Set();
  const badge = {
    classList: {
      add: (...names) => names.forEach((name) => classes.add(name)),
      remove: (...names) => names.forEach((name) => classes.delete(name)),
    },
    setAttribute() {},
  };
  const icon = { className: '' };

  const document = {
    addEventListener() {},
    querySelector() { return null; },
    documentElement: { getAttribute() { return 'light'; } },
    getElementById(id) {
      if (id === 'update-status-badge') return badge;
      if (id === 'update-badge-icon') return icon;
      if (id === 'updateModal') return {};
      if (id === 'update-modal-body') return { innerHTML: '' };
      if (id === 'update-result') return { className: '', innerHTML: '' };
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
    alert() {},
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

  return { context, calls, classes };
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

(async () => {
  await testPollingDoesNotOpenModal();
  await testClickOpensModal();
  await testPollingCallbacksPassFalse();
  console.log('update modal behavior: 3 passed');
})().catch((err) => {
  console.error(err.message);
  process.exitCode = 1;
});
