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
  const documentListeners = {};

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
    addEventListener(type, handler) {
      (documentListeners[type] ||= []).push(handler);
    },
    removeEventListener() {},
  };

  const context = vm.createContext({
    document,
    window: {
      addEventListener() {},
      removeEventListener() {},
      location: { hostname: 'node494780.local' },
    },
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
  return { widget, sockets, timers, document, documentListeners, context, storage };
}

function fireKey(documentListeners, type, code, extra = {}) {
  let prevented = false;
  const event = {
    code,
    repeat: false,
    preventDefault() { prevented = true; },
    ...extra,
  };
  for (const handler of documentListeners[type] || []) handler(event);
  return prevented;
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

test('dashboard host is announced when the WebSocket opens', () => {
  const { widget, sockets } = makeWidget();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();

  const announcements = ws.sent.filter((msg) => msg.type === 'host');
  assert.deepEqual(announcements, [{ type: 'host', host: 'node494780.local' }]);
});

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

test('a resting mic is shown as a normal ready state, not a failure', () => {
  const { widget } = makeWidget();
  widget._handleJSON({
    type: 'status',
    connected: true,
    call_active: true,
    ptt: false,
    tx_state: 'resting',
  });

  assert.equal(widget.txState, 'resting');
  assert.equal(
    widget.statusText.textContent,
    'Mic en reposo - listo para transmitir',
  );
});

test('the resting state also arrives nested in audio stats', () => {
  const { widget } = makeWidget();
  widget._handleJSON({
    type: 'status',
    connected: true,
    call_active: true,
    ptt: false,
    audio: { tx_state: 'resting' },
  });

  assert.equal(widget.txState, 'resting');
  assert.equal(
    widget.statusText.textContent,
    'Mic en reposo - listo para transmitir',
  );
});

test('PTT without an IAX2 call refuses to key and explains why', () => {
  const { widget, sockets } = makeWidget();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();

  widget.keyPtt();

  assert.equal(widget.pttActive, false, 'local PTT must not lie without a call');
  assert.equal(widget.statusText.textContent, 'Sin llamada IAX2 - conectate a un nodo');
  assert.equal(
    ws.sent.filter((msg) => msg.type === 'ptt').length,
    0,
    'no ptt key message may be sent without an IAX2 call',
  );
});

test('PTT still keys normally when the call is active', () => {
  const { widget, sockets } = makeWidget();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();
  widget._handleJSON({
    type: 'status', connected: true, call_active: true, ptt: false,
  });

  widget.keyPtt();

  assert.equal(widget.pttActive, true);
  assert.equal(widget.statusText.textContent, 'TRANSMITTING');
  assert.deepEqual(ws.sent.filter((msg) => msg.type === 'ptt'), [
    { type: 'ptt', action: 'key' },
  ]);
});

test('a dropped call is visible before the operator presses PTT', () => {
  const { widget, sockets } = makeWidget();
  widget._openWebSocket();
  sockets.at(-1).onopen?.();
  widget.placeCall('54614');
  widget._handleJSON({
    type: 'status', connected: true, call_active: false, ptt: false,
  });

  assert.equal(widget.statusText.textContent, 'Sin llamada IAX2 - usa Reconectar');
});

test('Reconectar reopens the IAX2 call without a second WebSocket', () => {
  const { widget, sockets } = makeWidget();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();
  widget.placeCall('54614');
  widget._handleJSON({
    type: 'status', connected: true, call_active: false, ptt: false,
  });

  const socketsBefore = sockets.length;
  widget.connectBtn.handlers.click();

  assert.equal(sockets.length, socketsBefore, 'the WS is already alive');
  assert.deepEqual(
    ws.sent.filter((msg) => msg.type === 'call'),
    [{ type: 'call', node: '54614' }, { type: 'call', node: '54614' }],
  );
  assert.equal(widget.connectBtn.textContent, 'Llamando...');
  assert.equal(widget.connectBtn.disabled, true, 'avoid a burst of call attempts');

  widget._handleJSON({
    type: 'status', connected: true, call_active: true, ptt: false,
  });
  assert.equal(widget.connectBtn.disabled, false);
  assert.equal(widget.connectBtn.textContent, 'Reconectar');
});

test('spacebar keys and unkeys PTT when the IAX2 call is active', () => {
  const { widget, sockets, documentListeners, context } = makeWidget();
  widget._bindGlobalEvents();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();
  widget._handleJSON({
    type: 'status', connected: true, call_active: true, ptt: false,
  });
  context.document.activeElement = { tagName: 'BUTTON' };

  const preventedDown = fireKey(documentListeners, 'keydown', 'Space');
  assert.equal(preventedDown, true, 'Space must not scroll the dashboard');
  assert.deepEqual(ws.sent.filter((msg) => msg.type === 'ptt'), [
    { type: 'ptt', action: 'key' },
  ]);
  assert.equal(widget.statusText.textContent, 'TRANSMITTING');

  fireKey(documentListeners, 'keyup', 'Space');
  assert.deepEqual(ws.sent.filter((msg) => msg.type === 'ptt'), [
    { type: 'ptt', action: 'key' },
    { type: 'ptt', action: 'unkey' },
  ]);
  assert.equal(widget.pttActive, false);
});

test('holding the spacebar does not resend the PTT key', () => {
  const { widget, sockets, documentListeners, context } = makeWidget();
  widget._bindGlobalEvents();
  widget._openWebSocket();
  sockets.at(-1).onopen?.();
  widget._handleJSON({
    type: 'status', connected: true, call_active: true, ptt: false,
  });
  context.document.activeElement = { tagName: 'BODY' };

  fireKey(documentListeners, 'keydown', 'Space');
  fireKey(documentListeners, 'keydown', 'Space', { repeat: true });

  assert.equal(
    sockets.at(-1).sent.filter((msg) => msg.type === 'ptt').length,
    1,
  );
});

test('spacebar works while the numeric node field keeps focus', () => {
  const { widget, sockets, documentListeners, context } = makeWidget();
  widget._bindGlobalEvents();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();
  widget._handleJSON({
    type: 'status', connected: true, call_active: true, ptt: false,
  });
  context.document.activeElement = { tagName: 'INPUT', id: 'node-number' };

  const prevented = fireKey(documentListeners, 'keydown', 'Space');

  assert.equal(prevented, true, 'the numeric node field cannot swallow Space');
  assert.deepEqual(ws.sent.filter((msg) => msg.type === 'ptt'), [
    { type: 'ptt', action: 'key' },
  ]);
});

test('spacebar still respects text fields and refuses without a call', () => {
  const { widget, sockets, documentListeners, context } = makeWidget();
  widget._bindGlobalEvents();
  widget._openWebSocket();
  const ws = sockets.at(-1);
  ws.onopen?.();
  context.document.activeElement = { tagName: 'INPUT' };

  fireKey(documentListeners, 'keydown', 'Space');
  assert.equal(ws.sent.filter((msg) => msg.type === 'ptt').length, 0);

  context.document.activeElement = { tagName: 'BODY' };
  fireKey(documentListeners, 'keydown', 'Space');
  assert.equal(ws.sent.filter((msg) => msg.type === 'ptt').length, 0);
  assert.equal(widget.statusText.textContent, 'Sin llamada IAX2 - conectate a un nodo');
});
