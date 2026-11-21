/**
 * public/assets/js/ptt-widget.js
 * -----------------------------------------------
 * PTTWidget — Dashboard Push-to-Talk widget for
 * the Companion Audio App.
 *
 * Connects to the companion app via localhost WebSocket,
 * provides PTT key/unkey via spacebar or mouse hold,
 * DTMF digit entry, and shows connection status.
 *
 * Audio goes through the companion app (native IAX2 peer).
 * This widget only sends control signals and displays status.
 *
 * Companion WS: ws://127.0.0.1:9093/ws
 *
 * Usage:
 *   const ptt = new PTTWidget();
 *   ptt.init();
 * -----------------------------------------------
 */

const LAST_NODE_KEY = 'chilemon.ptt.lastNode';

class PTTWidget {

    /**
     * @param {Object} options
     * @param {number} [options.wsPort=9093]  Companion app WS port
     */
    constructor(options = {}) {
        this.wsPort = options.wsPort || 9093;

        /** @type {WebSocket|null} */
        this.ws = null;
        this.connected = false;
        this.pttActive = false;
        this.callActive = false;
        this.txState = 'active';
        this.reconnectAttempts = 0;
        this.reconnectBaseDelay = 1000;
        this.reconnectTimer = null;
        // True when the WS handshake failed (companion process not running).
        this._wsFailed = false;

        // Volume display (from companion metadata)
        this.volumeSamples = [];
        this.maxVolumeSamples = 10;

        // DOM references
        this.widget = null;
        this.pttButton = null;
        this.pttLabel = null;
        this.statusDot = null;
        this.statusText = null;
        this.volumeFill = null;
        this.volumeContainer = null;
        this.connectBtn = null;
        this.dtmfPad = null;

        // Bound handlers
        this._onKeyDown = this._onKeyDown.bind(this);
        this._onKeyUp = this._onKeyUp.bind(this);
        this._onMouseUp = this._onMouseUp.bind(this);
        this._onBeforeUnload = this._onBeforeUnload.bind(this);

        // Visualizer reference (set by init)
        this._visualizer = null;

        // Last node whose audio channel was opened. Kept in sessionStorage so
        // the visible Reconnect button can restore it after a page reload.
        this.lastCallNode = this._readLastNode();
        this._restoreCallOnOpen = false;
    }

    // ---------------------------------------------------------------
    //  Public API
    // ---------------------------------------------------------------

    /** Initialize: create DOM, bind events. */
    init() {
        this._createDOM();
        this._bindGlobalEvents();
        this._initVisualizer();
        // Auto-connect: WS a localhost funciona aunque el dashboard esté via HTTPS
        // El onclose + _scheduleReconnect maneja la reconexión automática
        this._openWebSocket();
    }

    /** Wire up the spectrum visualizer if canvas exists. */
    _initVisualizer() {
        if (typeof AudioVisualizer !== 'undefined' && document.getElementById('audio-canvas')) {
            this._visualizer = new AudioVisualizer('audio-canvas');
            // Override feedPCM to accept companion metadata instead
            this._visualizer.feedPCM = (samples) => {
                // Metadata arrived via audio_level WS message — handled in _handleAudioLevel
            };
        }
    }

    /** Tear down: close WS, stop timers, unbind. */
    destroy() {
        this._clearReconnect();
        this._closeWebSocket();
        this._unbindGlobalEvents();
        if (this._visualizer) {
            this._visualizer.destroy();
            this._visualizer = null;
        }
        if (this.widget && this.widget.parentNode) {
            this.widget.parentNode.removeChild(this.widget);
        }
    }

    // ---------------------------------------------------------------
    //  WebSocket — connects to companion app (127.0.0.1:9093)
    // ---------------------------------------------------------------

    /** Open WebSocket to companion app (no auth token needed — localhost). */
    _openWebSocket() {
        this._closeWebSocket();
        this._wsFailed = false;

        const url = `ws://127.0.0.1:${this.wsPort}/ws`;

        this.ws = new WebSocket(url);
        this.ws.onopen = () => {
            this.connected = true;
            this.reconnectAttempts = 0;
            this._wsFailed = false;
            this._setStatus('connected', 'Companion Connected');
            // Keep the button available so the user can force a reconnect
            // without waiting for the automatic backoff to fire.
            if (this.connectBtn) {
                this.connectBtn.disabled = false;
                this.connectBtn.textContent = 'Reconectar';
                this.connectBtn.title = 'Force reconnect to companion app';
            }
            // Restore the audio channel only when the operator pressed the
            // button. Automatic WS reconnects never reopen the IAX2 call.
            if (this._restoreCallOnOpen && this.lastCallNode) {
                this._restoreCallOnOpen = false;
                this.placeCall(this.lastCallNode);
            }
            this._updateUI();
        };

        this.ws.onclose = () => {
            this.connected = false;
            this.pttActive = false;
            this.callActive = false;
            // A refused handshake means the companion process is not running.
            // Surface that explicitly instead of a generic "Disconnected".
            const msg = this._wsFailed
                ? 'Companion no detectado'
                : 'Disconnected';
            this._setStatus(this._wsFailed ? 'error' : 'disconnected', msg);
            if (this.connectBtn) {
                this.connectBtn.style.display = '';
                this.connectBtn.disabled = false;
                this.connectBtn.textContent = 'Reintentar';
            }
            this._updateUI();
            this._scheduleReconnect();
        };

        this.ws.onerror = () => {
            // onclose fires right after onerror; flag it for the status message.
            this._wsFailed = true;
        };

        this.ws.onmessage = (event) => {
            this._handleMessage(event.data);
        };
    }

    _closeWebSocket() {
        if (this.ws) {
            try {
                this.ws.onopen = null;
                this.ws.onclose = null;
                this.ws.onerror = null;
                this.ws.onmessage = null;
                this.ws.close();
            } catch (_) { /* ignore */ }
            this.ws = null;
        }
    }

    // ---------------------------------------------------------------
    //  Reconnect (exponential backoff)
    // ---------------------------------------------------------------

    _scheduleReconnect() {
        // Retry indefinitely: a companion started later must always be picked
        // up, even after many failed attempts. Cap the exponent to avoid
        // overflow while the delay itself stays below 10s.
        const exponent = Math.min(this.reconnectAttempts, 10);
        const delay = Math.min(
            this.reconnectBaseDelay * Math.pow(2, exponent),
            10000
        );
        this.reconnectAttempts++;
        this._clearReconnect();
        this.reconnectTimer = setTimeout(() => {
            this._openWebSocket();
        }, delay);
    }

    _clearReconnect() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    // ---------------------------------------------------------------
    //  Message handling
    // ---------------------------------------------------------------

    _handleMessage(data) {
        if (typeof data !== 'string') return;

        try {
            const msg = JSON.parse(data);
            this._handleJSON(msg);
        } catch (_) { /* ignore */ }
    }

    _handleJSON(msg) {
        switch (msg.type) {
            case 'status':
                this._handleStatus(msg);
                break;
            case 'audio_level':
                this._handleAudioLevel(msg);
                break;
            case 'error':
                this._setStatus('error', msg.message || 'Companion error');
                break;
            default:
                break;
        }
    }

    _handleStatus(msg) {
        this.callActive = msg.call_active === true;
        this.pttActive = msg.ptt === true;
        this.txState = msg.tx_state
            || (msg.audio && msg.audio.tx_state)
            || 'active';

        if (this.callActive) {
            this._setStatus('connected', this._callStatusText());
        } else if (msg.connected) {
            this._setStatus('connected', 'Companion Connected');
        } else {
            this._setStatus('error', 'Disconnected');
        }
        this._updateUI();
    }

    _handleAudioLevel(msg) {
        const rms = msg.rms || 0;
        this._pushVolume(rms);

        // Forward to visualizer if available
        if (this._visualizer && typeof this._visualizer.feedMetadata === 'function') {
            this._visualizer.feedMetadata(rms, msg.spectrum || []);
        }
    }

    // ---------------------------------------------------------------
    //  Sending control messages
    // ---------------------------------------------------------------

    _send(msg) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(msg));
        }
    }

    // ---------------------------------------------------------------
    //  PTT — Key / Unkey
    // ---------------------------------------------------------------

    keyPtt() {
        if (!this.connected || this.pttActive) return;
        this._send({ type: 'ptt', action: 'key' });
        this.pttActive = true;
        if (this._visualizer) this._visualizer.setTransmitting(true);
        this._setStatus('transmitting', 'TRANSMITTING');
        this._updateUI();
    }

    unkeyPtt() {
        if (!this.pttActive) return;
        this._send({ type: 'ptt', action: 'unkey' });
        this.pttActive = false;
        if (this._visualizer) this._visualizer.setTransmitting(false);
        this._setStatus('connected', this.callActive ? this._callStatusText() : 'Companion Connected');
        this._updateUI();
    }

    // ---------------------------------------------------------------
    //  DTMF
    // ---------------------------------------------------------------

    sendDtmf(digit) {
        if (!this.connected) return;
        this._send({ type: 'dtmf', digit: String(digit) });
    }

    // ---------------------------------------------------------------
    //  Call — Place / Hangup via companion IAX2
    // ---------------------------------------------------------------

    _readLastNode() {
        try {
            return sessionStorage.getItem(LAST_NODE_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    _rememberNode(node) {
        this.lastCallNode = String(node);
        try {
            sessionStorage.setItem(LAST_NODE_KEY, this.lastCallNode);
        } catch (e) {
            // Storage may be unavailable (private mode); in-memory still works.
        }
    }

    _forgetNode() {
        this.lastCallNode = '';
        try {
            sessionStorage.removeItem(LAST_NODE_KEY);
        } catch (e) {
            // Ignore storage failures.
        }
    }

    /** Place an IAX2 call to a node through the companion app. */
    placeCall(node) {
        console.log("[PLACECALL] called with node:", node, "connected:", this.connected);
        if (!this.connected) return;
        this._rememberNode(node);
        console.log("[PLACECALL] sending WS message");
        this._send({ type: 'call', node: String(node) });
        this._setStatus('connected', 'Llamando...');
    }

    /** Hang up the current call through the companion app. */
    hangupCall() {
        this._forgetNode();
        if (!this.connected) return;
        this._send({ type: 'call', action: 'hangup' });
        this.pttActive = false;
        if (this._visualizer) this._visualizer.setTransmitting(false);
        this._setStatus('connected', 'Companion Connected');
    }

    // ---------------------------------------------------------------
    //  Volume display
    // ---------------------------------------------------------------

    _pushVolume(rms) {
        this.volumeSamples.push(rms);
        if (this.volumeSamples.length > this.maxVolumeSamples) {
            this.volumeSamples.shift();
        }

        let sum = 0;
        for (const s of this.volumeSamples) {
            sum += s;
        }
        const avg = sum / this.volumeSamples.length;
        const display = Math.min(1.0, avg * 4);
        const pct = Math.round(display * 100);

        if (this.volumeFill) {
            this.volumeFill.style.width = pct + '%';
        }
    }

    // ---------------------------------------------------------------
    //  UI — Status
    // ---------------------------------------------------------------

    // A resting mic is a normal state, not an error. Say so in plain words.
    _callStatusText() {
        return this.txState === 'resting'
            ? 'Mic en reposo - listo para transmitir'
            : 'Call active';
    }

    _setStatus(state, text) {
        if (this.statusDot) {
            this.statusDot.className = 'ptt-status-dot ptt-status-' + state;
        }
        if (this.statusText) {
            this.statusText.textContent = text;
        }
    }

    _updateUI() {
        if (this.pttButton) {
            this.pttButton.classList.toggle('ptt-active', this.pttActive);
            this.pttButton.disabled = !this.connected;
        }
        if (this.pttLabel) {
            this.pttLabel.textContent = this.pttActive ? 'TRANSMITTING' : 'PTT';
            this.pttLabel.classList.toggle('ptt-label-active', this.pttActive);
        }
        if (this.volumeContainer) {
            this.volumeContainer.classList.toggle('ptt-volume-disabled', !this.connected);
        }
        if (this.widget) {
            this.widget.classList.toggle('ptt-connected', this.connected);
        }
    }

    // ---------------------------------------------------------------
    //  DOM creation
    // ---------------------------------------------------------------

    _createDOM() {
        if (document.getElementById('ptt-widget')) return;

        this.widget = document.createElement('div');
        this.widget.id = 'ptt-widget';
        this.widget.className = 'ptt-widget';

        this.widget.innerHTML = `
            <div class="ptt-header">
                <span class="ptt-status-dot ptt-status-disconnected" id="ptt-status-dot"></span>
                <span class="ptt-status-text" id="ptt-status-text">Disconnected</span>
                <span class="ptt-gain-controls" style="margin-left:auto;display:none;"></span>
            </div>
            <div class="ptt-volume-bar" id="ptt-volume-bar">
                <div class="ptt-volume-fill" id="ptt-volume-fill"></div>
            </div>
            <button class="ptt-connect-btn" id="ptt-connect-btn" title="Connect to companion app">
                <i class="bi bi-plug"></i> Connect
            </button>
            <button class="ptt-button" id="ptt-button" title="Push to Talk (hold spacebar)" aria-label="Push to Talk" disabled>
                <i class="bi bi-mic"></i>
            </button>
            <div class="ptt-label" id="ptt-label">PTT</div>
        `;

        document.body.appendChild(this.widget);

        // Cache DOM refs
        this.pttButton = this.widget.querySelector('#ptt-button');
        this.pttLabel = this.widget.querySelector('#ptt-label');
        this.statusDot = this.widget.querySelector('#ptt-status-dot');
        this.statusText = this.widget.querySelector('#ptt-status-text');
        this.volumeFill = this.widget.querySelector('#ptt-volume-fill');
        this.volumeContainer = this.widget.querySelector('#ptt-volume-bar');
        this.connectBtn = this.widget.querySelector('#ptt-connect-btn');

        // Connect button — reset backoff so a manual click retries immediately
        this.connectBtn.addEventListener('click', () => {
            this.connectBtn.disabled = true;
            this.connectBtn.textContent = 'Buscando...';
            this.reconnectAttempts = 0;
            this._clearReconnect();
            this._setStatus('disconnected', 'Buscando Companion...');
            // Only an explicit click restores the audio channel that the
            // companion may have dropped after 60s without received audio.
            this._restoreCallOnOpen = Boolean(this.lastCallNode);
            this._openWebSocket();
        });

        // PTT mouse events
        this.pttButton.addEventListener('mousedown', (e) => {
            e.preventDefault();
            this.keyPtt();
        });
        this.pttButton.addEventListener('mouseup', this._onMouseUp);
        this.pttButton.addEventListener('mouseleave', this._onMouseUp);

        // Touch support
        this.pttButton.addEventListener('touchstart', (e) => {
            e.preventDefault();
            this.keyPtt();
        }, { passive: false });
        this.pttButton.addEventListener('touchend', (e) => {
            e.preventDefault();
            this.unkeyPtt();
        }, { passive: false });
    }

    // ---------------------------------------------------------------
    //  Global event binding
    // ---------------------------------------------------------------

    _bindGlobalEvents() {
        document.addEventListener('keydown', this._onKeyDown);
        document.addEventListener('keyup', this._onKeyUp);
        window.addEventListener('beforeunload', this._onBeforeUnload);
    }

    _unbindGlobalEvents() {
        document.removeEventListener('keydown', this._onKeyDown);
        document.removeEventListener('keyup', this._onKeyUp);
        window.removeEventListener('beforeunload', this._onBeforeUnload);
    }

    _onKeyDown(e) {
        if (e.code === 'Space') {
            const tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            e.preventDefault();
            if (!e.repeat) {
                this.keyPtt();
            }
        }
    }

    _onKeyUp(e) {
        if (e.code === 'Space') {
            e.preventDefault();
            this.unkeyPtt();
        }
    }

    _onMouseUp() {
        this.unkeyPtt();
    }

    _onBeforeUnload() {
        this.destroy();
    }
}
