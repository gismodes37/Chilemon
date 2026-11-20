/**
 * Regression test: waitForCall() resolves only when the companion confirms
 * an active IAX2 channel, so the dashboard can link after the audio channel
 * is inside the local node bridge (connection telemetry is audible).
 *
 * Run with plain Node, no dependencies:
 *   node tests/js/ptt-widget-call.test.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const WIDGET_JS = path.join(
  __dirname, '..', '..', 'public', 'assets', 'js', 'ptt-widget.js'
);

function makeWidget() {
  const context = vm.createContext({
    console: { log() {}, warn() {}, error() {} },
    setTimeout,
    clearTimeout,
    WebSocket: class {},
    document: {},
  });
  const source = fs.readFileSync(WIDGET_JS, 'utf8') +
    '\nglobalThis.PTTWidget = PTTWidget;';
  vm.runInContext(source, context, { filename: 'ptt-widget.js' });

  const widget = new context.PTTWidget();
  widget.placeCall = () => {};
  return widget;
}

async function testResolvesOnConfirmedCall() {
  const widget = makeWidget();
  widget.connected = true;
  widget.placeCall = () => {
    setTimeout(() => widget._handleJSON({
      type: 'status', connected: true, call_active: true, ptt: false,
    }), 5);
  };

  const ok = await widget.waitForCall('54614', 100);
  assert.equal(ok, true, 'confirmed call did not resolve true');
  assert.equal(widget.callActive, true);
}

async function testResolvesFalseOnTimeout() {
  const widget = makeWidget();
  widget.connected = true;

  const ok = await widget.waitForCall('54614', 15);
  assert.equal(ok, false, 'unconfirmed call did not time out');
}

async function testAlreadyActiveSkipsPlaceCall() {
  const widget = makeWidget();
  widget.connected = true;
  widget.callActive = true;
  let placed = 0;
  widget.placeCall = () => { placed += 1; };

  const ok = await widget.waitForCall('54614', 100);
  assert.equal(ok, true);
  assert.equal(placed, 0, 'placeCall should not run for an active channel');
}

async function testDisconnectedResolvesFalse() {
  const widget = makeWidget();
  let placed = 0;
  widget.placeCall = () => { placed += 1; };

  const ok = await widget.waitForCall('54614', 100);
  assert.equal(ok, false);
  assert.equal(placed, 0);
}

(async () => {
  await testResolvesOnConfirmedCall();
  await testResolvesFalseOnTimeout();
  await testAlreadyActiveSkipsPlaceCall();
  await testDisconnectedResolvesFalse();
  console.log('companion call wait: 4 passed');
})().catch((err) => {
  console.error(err.message);
  process.exitCode = 1;
});
