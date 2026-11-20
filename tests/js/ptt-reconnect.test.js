/**
 * Regression tests for manual and automatic companion reconnection.
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/ptt-reconnect.test.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const WIDGET_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'ptt-widget.js'
);

function makeElement() {
  const el = {
    style: {},
    textContent: '',
    disabled: false,
    handlers: {},
    classList: { toggle() {}, add() {}, remove() {} },
    addEventListener(type, handler) { this.handlers[type] = handler; },
    removeEventListener() {},
    querySelector(selector) { return this.refs?.[selector] ?? null; },
    appendChild() {},
    removeChild() {},
  };
  Object.defineProperty(el, 'innerHTML', {
    set(value) {
      this._html = value;
      this.refs = {
        '#ptt-status-dot': makeElement(),
        '#ptt-status-text': makeElement(),
        '#ptt-volume-fill': makeElement(),
        '#ptt-volume-bar': makeElement(),
        '#ptt-connect-btn': makeElement(),
        '#ptt-button': makeElement(),
        '#ptt-label': makeElement(),
      };
    },
    get() { return this._html ?? ''; },
  });
  return el;
}

function makeStorage() {
  const data = new Map();
  return {
    getItem(key) { return data.has(key) ? data.get(key) : null; },
    setItem(key, value) { data.set(key, String(value)); },
    removeItem(key) { data.delete(key); },
  };
}

function makeWidget(storage = makeStorage()) {
  const sockets = [];
  const timers = [];

  class FakeWebSocket {
    static OPEN = 1;

    constructor(url) {
      this.url = url;
      this.readyState = 1;
      this.sent = [];
      this.onopen = null;
      this.onclose = null;
      this.onerror = null;
      this.onmessage = null;
      sockets.push(this);
    }
    send(payload) { this.sent.push(JSON.parse(payload)); }
    close() { this.readyState = 3; }
  }

  const document = {
    body: { appendChild() {}, removeChild() {} },
    getElementById(id) { return id === 'ptt-widget' ? null : null; },
    createElement() { return makeElement(); },
    addEventListener() {},
    removeEventListener() {},
  };

  const context = vm.createContext({
    document,
    window: { addEventListener() {}, removeEventListener() {} },
    console: { log() {}, warn() {}, error() {} },
    WebSocket: FakeWebSocket,
    sessionStorage: storage,
    setTimeout(fn, delay) { timers.push({ fn, delay }); return timers.length; },
    clearTimeout() {},
    AudioVisualizer: undefined,
  });

  const source = fs.readFileSync(WIDGET_JS, 'utf8');
  vm.runInContext(`${source}\nglobalThis.PTTWidget = PTTWidget;`, context, {
    filename: 'ptt-widget.js',
  });

  const widget = new context.PTTWidget();
  widget._createDOM();
  return { widget, sockets, timers, document, storage };
}

function openAndDrop(widget, sockets, timers) {
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onerror?.();
  ws.onclose?.();
  const pending = timers.pop();
  assert.ok(pending, 'expected an auto-reconnect timer to be scheduled');
  pending.fn();
}

const { test } = require('node:test');

test('connect button stays visible and usable after the WebSocket opens', () => {
  const { widget, sockets } = makeWidget();
  widget._openWebSocket();
  sockets.at(-1).onopen?.();

  assert.equal(widget.connected, true);
  assert.notEqual(
    widget.connectBtn.style.display,
    'none',
    'connect button must remain visible when connected',
  );
  assert.equal(widget.connectBtn.disabled, false);
  assert.equal(widget.connectBtn.textContent, 'Reconectar');
});

test('auto-reconnect keeps trying after 20 failed attempts', () => {
  const { widget, sockets, timers } = makeWidget();

  for (let attempt = 0; attempt < 25; attempt++) {
    openAndDrop(widget, sockets, timers);
  }

  assert.ok(
    sockets.length > 21,
    `expected retries beyond attempt 20, got ${sockets.length} sockets`,
  );
  assert.equal(widget.reconnectAttempts > 20, true);
});

test('clicking the visible button resets backoff and reconnects immediately', () => {
  const { widget, sockets, timers } = makeWidget();

  openAndDrop(widget, sockets, timers);
  openAndDrop(widget, sockets, timers);
  assert.ok(widget.reconnectAttempts >= 2);

  const before = sockets.length;
  widget.connectBtn.handlers.click();

  assert.equal(sockets.length, before + 1, 'manual click must open a new socket');
  assert.equal(widget.reconnectAttempts, 0, 'manual click must reset the backoff');
});

test('Reconectar restores the audio call for the last node after a page reload', () => {
  const storage = makeStorage();
  const first = makeWidget(storage);
  first.widget._openWebSocket();
  first.sockets.at(-1).onopen?.();
  first.widget.placeCall('54614');

  // Simulate leaving the dashboard and logging back in: new widget, same tab.
  const second = makeWidget(storage);
  second.widget.connectBtn.handlers.click();
  const ws = second.sockets.at(-1);
  ws.onopen?.();

  const calls = ws.sent.filter((msg) => msg.type === 'call');
  assert.deepEqual(
    calls,
    [{ type: 'call', node: '54614' }],
    'the button must reopen the IAX2 audio channel, not only the WebSocket',
  );
});

test('an explicit hangup does not reopen the call on a later reconnect', () => {
  const storage = makeStorage();
  const first = makeWidget(storage);
  first.widget._openWebSocket();
  first.sockets.at(-1).onopen?.();
  first.widget.placeCall('54614');
  first.widget.hangupCall();

  const second = makeWidget(storage);
  second.widget.connectBtn.handlers.click();
  const ws = second.sockets.at(-1);
  ws.onopen?.();

  assert.equal(
    ws.sent.filter((msg) => msg.type === 'call').length,
    0,
    'a deliberate disconnect must not be undone by a later reconnect',
  );
});
