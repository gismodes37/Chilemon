/**
 * public/assets/js/ptt-widget.js
 * -----------------------------------------------
 * PTTWidget — Dashboard Push-to-Talk widget for
 * the WebRTC Audio Bridge.
 *
 * Connects to the bridge via WebSocket, provides
 * PTT key/unkey via spacebar or mouse hold, shows
 * connection status, and renders a received-audio
 * volume bar.
 *
 * Usage:
 *   const ptt = new PTTWidget();
 *   ptt.init();
 *
 * Requires: window.CHILEMON_BASE (set in scripts.php)
 * -----------------------------------------------
 */

class PTTWidget {

    /**
     * @param {Object} options
     * @param {number} [options.wsPort=9091]  Bridge WebSocket port
     * @param {number} [options.statusInterval=5000]  Status poll ms
     */
    constructor(options = {}) {
        this.wsPort = options.wsPort || 9091;
        this.statusInterval = options.statusInterval || 5000;

        /** @type {WebSocket|null} */
        this.ws = null;
        this.token = null;
        this.connected = false;
        this.pttActive = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 20;
        this.reconnectBaseDelay = 1000; // 1s, doubles each attempt
        this.reconnectTimer = null;
        this.statusPollTimer = null;

        /** @type {AudioContext|null} */
        this.audioCtx = null;

        // Gain nodes for RX/TX volume control
        /** @type {GainNode|null} */
        this._rxGainNode = null;
        /** @type {GainNode|null} */
        this._txGainNode = null;

        // Previous audio source (stopped before starting new one to prevent overlap)
        /** @type {AudioBufferSourceNode|null} */
        this._audioSource = null;

        // Volume-smoothing: RMS from last N audio messages
        this.volumeSamples = [];
        this.maxVolumeSamples = 10;

        // DOM references (set during init)
        this.widget = null;
        this.pttButton = null;
        this.pttLabel = null;
        this.statusDot = null;
        this.statusText = null;
        this.volumeFill = null;
        this.volumeContainer = null;

        // Bound handlers for addEventListener / removeEventListener
        this._onKeyDown = this._onKeyDown.bind(this);
        this._onKeyUp = this._onKeyUp.bind(this);
        this._onMouseUp = this._onMouseUp.bind(this);
        this._onBeforeUnload = this._onBeforeUnload.bind(this);

        // TX (mic capture) state
        /** @type {MediaStream|null} */
        this._micStream = null;
        /** @type {MediaStreamAudioSourceNode|null} */
        this._micSource = null;
        /** @type {ScriptProcessorNode|null} */
        this._micProcessor = null;
        /** @type {AudioContext|null} */
        this._txCtx = null;
    }

    // ---------------------------------------------------------------
    //  Public API
    // ---------------------------------------------------------------

    /** Initialize: create DOM, bind events, fetch token, connect. */
    init() {
        this._createDOM();
        this._bindGlobalEvents();
        this._startStatusPolling();
        this._initVisualizer();
        this._fetchToken();
    }

    /** Wire up the spectrum visualizer if the canvas exists. */
    _initVisualizer() {
        if (typeof AudioVisualizer !== 'undefined' && document.getElementById('audio-canvas')) {
            this._visualizer = new AudioVisualizer('audio-canvas');
        }
    }

    /** Tear down: close WS, stop timers, unbind events. */
    destroy() {
        this._clearReconnect();
        this._stopStatusPolling();
        this._closeWebSocket();
        this._unbindGlobalEvents();
        this.stopCapture();
        if (this._visualizer) {
            this._visualizer.destroy();
            this._visualizer = null;
        }
        if (this._audioSource) {
            try { this._audioSource.stop(); } catch (_) {}
            try { this._audioSource.disconnect(); } catch (_) {}
            this._audioSource = null;
        }
        if (this.audioCtx) {
            this.audioCtx.close().catch(() => {});
            this.audioCtx = null;
        }
        if (this.widget && this.widget.parentNode) {
            this.widget.parentNode.removeChild(this.widget);
        }
    }

    // ---------------------------------------------------------------
    //  Token & WebSocket
    // ---------------------------------------------------------------

    /** Fetch WS token from PHP API then open WebSocket. */
    async _fetchToken() {
        try {
            const apiBase = window.CHILEMON_PATH || '/';
            const resp = await fetch(apiBase + 'api/ptt-ws-token.php');
            if (!resp.ok) {
                this._setStatus('error', 'Auth failed');
                return;
            }
            const data = await resp.json();
            if (!data.ok || !data.token) {
                this._setStatus('error', data.error || 'No token');
                return;
            }
            this.token = data.token;
            this._openWebSocket();
        } catch (err) {
            this._setStatus('error', 'Token fetch failed');
        }
    }

    /** Open WebSocket connection to the bridge. */
    _openWebSocket() {
        this._closeWebSocket();

        const host = window.location.hostname;

        let url;
        if (window.location.protocol === 'https:') {
            // Via Apache proxy: wss://host/chilemon/ws → ws://127.0.0.1:9091/ws
            const wsPath = window.CHILEMON_PATH || '/';
            url = `wss://${host}${wsPath}ws?token=${encodeURIComponent(this.token)}`;
        } else {
            // Direct (dev): ws://host:9091/ws
            url = `ws://${host}:${this.wsPort}/ws?token=${encodeURIComponent(this.token)}`;
        }

        this.ws = new WebSocket(url);
        this.ws.binaryType = 'arraybuffer';

        this.ws.onopen = () => {
            this.connected = true;
            this.reconnectAttempts = 0;
            this._setStatus('connected', 'Bridge Connected');
            this._updateUI();
        };

        this.ws.onclose = () => {
            this.connected = false;
            this.pttActive = false;
            this._setStatus('disconnected', 'Disconnected');
            this._updateUI();
            this._scheduleReconnect();
        };

        this.ws.onerror = () => {
            // onclose fires after onerror, so we handle reconnect there
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
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            this._setStatus('error', 'Max reconnect attempts');
            return;
        }
        const delay = this.reconnectBaseDelay * Math.pow(2, this.reconnectAttempts);
        this.reconnectAttempts++;
        this._setStatus('disconnected', `Reconnecting in ${Math.round(delay / 1000)}s...`);

        this._clearReconnect();
        this.reconnectTimer = setTimeout(() => {
            this._fetchToken();
        }, delay);
    }

    _clearReconnect() {
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    // ---------------------------------------------------------------
    //  Status polling (HTTP fallback for connection status)
    // ---------------------------------------------------------------

    _startStatusPolling() {
        this._stopStatusPolling();
        this.statusPollTimer = setInterval(() => {
            this._pollStatus();
        }, this.statusInterval);
    }

    _stopStatusPolling() {
        if (this.statusPollTimer) {
            clearInterval(this.statusPollTimer);
            this.statusPollTimer = null;
        }
    }

    async _pollStatus() {
        try {
            const apiBase = window.CHILEMON_PATH || '/';
            const resp = await fetch(apiBase + 'api/ptt-status.php');
            if (!resp.ok) {
                this._setStatus('error', 'Bridge unreachable');
                return;
            }
            const data = await resp.json();
            if (data.status === 'connected' || data.status === 'ok') {
                if (!this.connected) {
                    // WS may be down but bridge is up — try reconnecting
                    this._fetchToken();
                }
                // Update status from bridge data
                if (data.ptt_active) {
                    this.pttActive = true;
                }
                this._updateUI();
            } else {
                this._setStatus('error', 'Bridge error');
            }
        } catch (_) {
            this._setStatus('error', 'Bridge unreachable');
        }
    }

    // ---------------------------------------------------------------
    //  Message handling
    // ---------------------------------------------------------------

    _handleMessage(data) {
        // Text messages are JSON
        if (typeof data === 'string') {
            try {
                const msg = JSON.parse(data);
                this._handleJSON(msg);
            } catch (_) { /* ignore malformed */ }
            return;
        }

        // Binary messages are audio (PCM f32 little-endian)
        if (data instanceof ArrayBuffer) {
            this._handleAudioBuffer(data);
            return;
        }
    }

    _handleJSON(msg) {
        switch (msg.type) {
            case 'status':
                this._handleStatusMessage(msg);
                break;
            case 'audio':
                // Audio as hex-encoded float32 array
                if (msg.data && msg.rate) {
                    this._handleHexAudio(msg.data, msg.rate);
                }
                break;
            case 'pong':
                // Keepalive response — nothing to do
                break;
            default:
                break;
        }
    }

    _handleStatusMessage(msg) {
        if (msg.status === 'connected' || msg.status === 'ok') {
            if (!this.connected) {
                this.connected = true;
                this._setStatus('connected', 'Bridge Connected');
            }
        } else if (msg.status === 'error') {
            this._setStatus('error', msg.error || 'Bridge error');
        }
        if (typeof msg.ptt_active === 'boolean') {
            this.pttActive = msg.ptt_active;
        }
        this._updateUI();
    }

    /** Decode hex-encoded float32 PCM and play via Web Audio API. */
    _handleHexAudio(hexStr, sampleRate) {
        // Convert hex to float32 array
        const bytes = this._hexToBytes(hexStr);
        if (!bytes || bytes.length < 4) return;

        const floatCount = Math.floor(bytes.length / 4);
        const floats = new Float32Array(floatCount);
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);

        let rms = 0;
        for (let i = 0; i < floatCount; i++) {
            const sample = view.getFloat32(i * 4, true); // little-endian
            floats[i] = sample;
            rms += sample * sample;
        }
        rms = Math.sqrt(rms / floatCount);

        // Update volume
        this._pushVolume(rms);

        // Feed to visualizer
        if (this._visualizer) {
            this._visualizer.feedPCM(floats);
        }

        // Play via Web Audio API
        this._playAudioBuffer(floats, sampleRate);
    }

    /** Decode and play a raw ArrayBuffer of float32 PCM. */
    _handleAudioBuffer(buffer) {
        const floats = new Float32Array(buffer);
        if (floats.length === 0) return;

        let rms = 0;
        for (let i = 0; i < floats.length; i++) {
            rms += floats[i] * floats[i];
        }
        rms = Math.sqrt(rms / floats.length);

        this._pushVolume(rms);

        // Feed to visualizer
        if (this._visualizer) {
            this._visualizer.feedPCM(floats);
        }

        this._playAudioBuffer(floats, 16000);
    }

    /** Play an AudioBuffer from float32 PCM data. */
    _playAudioBuffer(samples, sampleRate) {
        try {
            if (!this.audioCtx) {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                // Create RX gain node (persists across plays)
                this._rxGainNode = this.audioCtx.createGain();
                this._rxGainNode.gain.value = this._getStoredGain('rx');
                this._rxGainNode.connect(this.audioCtx.destination);
            }
            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume().catch(() => {});
            }

            // Stop previous source to prevent frame overlap (crackling)
            if (this._audioSource) {
                try { this._audioSource.stop(); } catch (_) {}
                try { this._audioSource.disconnect(); } catch (_) {}
            }

            const buf = this.audioCtx.createBuffer(1, samples.length, sampleRate);
            buf.copyToChannel(samples, 0);

            this._audioSource = this.audioCtx.createBufferSource();
            this._audioSource.buffer = buf;
            this._audioSource.connect(this._rxGainNode);
            this._audioSource.start(0);
        } catch (_) { /* audio not available */ }
    }

    // ---------------------------------------------------------------
    //  TX — Microphone capture & send
    // ---------------------------------------------------------------

    /**
     * Start capturing microphone audio and sending it over WebSocket.
     * Called automatically from keyPtt().
     * Uses a dedicated AudioContext at 16 kHz (separate from RX) so TX
     * sample rate is always correct regardless of when RX started.
     */
    startCapture() {
        if (this._micStream) return; // already capturing

        // Dedicated TX AudioContext — try 16 kHz, fallback to default
        try {
            this._txCtx = new (window.AudioContext || window.webkitAudioContext)({
                sampleRate: 16000,
            });
        } catch (_) {
            this._txCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (this._txCtx.state === 'suspended') {
            this._txCtx.resume().catch(() => {});
        }

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then((stream) => {
                this._micStream = stream;
                this._micSource = this._txCtx.createMediaStreamSource(stream);

                // TX gain node for mic sensitivity control
                this._txGainNode = this._txCtx.createGain();
                this._txGainNode.gain.value = this._getStoredGain('tx');

                this._micProcessor = this._txCtx.createScriptProcessor(1024, 1, 1);

                this._micProcessor.onaudioprocess = (e) => {
                    if (!this.pttActive) return;
                    const input = e.inputBuffer.getChannelData(0);
                    const actualRate = this._txCtx.sampleRate;
                    let outSamples, outRate;
                    if (actualRate === 16000) {
                        outSamples = input;
                        outRate = 16000;
                    } else {
                        try {
                            outSamples = this._downsampleCIC(input, actualRate);
                            outRate = 16000;
                        } catch (err) {
                            console.warn('[ChileMon] CIC downsample failed, raw data sent:', err);
                            outSamples = input;
                            outRate = actualRate;
                        }
                    }
                    this._sendAudioChunk(outSamples, outRate);
                };

                // micSource → gainNode → scriptProcessor
                this._micSource.connect(this._txGainNode);
                this._txGainNode.connect(this._micProcessor);

                // ScriptProcessorNode MUST be connected to the destination
                // for onaudioprocess to fire — otherwise the audio graph
                // has no sink and the browser won't drive the callback.
                this._micProcessor.connect(this._txCtx.destination);
            })
            .catch((err) => {
                console.warn('PTT: Mic access denied —', err.message);
                this._setStatus('error', 'Mic access denied');
                // Undo PTT key so the bridge isn't left hanging
                this.unkeyPtt();
            });
    }

    /** Stop mic capture and release the MediaStream + TX AudioContext. */
    stopCapture() {
        if (this._micProcessor) {
            try { this._micProcessor.disconnect(); } catch (_) { /* ignore */ }
            this._micProcessor = null;
        }
        this._micSource = null;

        if (this._micStream) {
            this._micStream.getTracks().forEach((t) => t.stop());
            this._micStream = null;
        }

        if (this._txCtx) {
            this._txCtx.close().catch(() => {});
            this._txCtx = null;
        }
    }

    /**
     * Cascaded Integrator-Comb downsample, stage-1.
     * Running-sum + decimate-by-M to convert any sample rate to ~16kHz.
     * For 48kHz->16kHz: M=3, 1024 inputs -> ~341 outputs.
     * For arbitrary rates: M = round(actualRate / 16000).
     * @param {Float32Array} samples - Input PCM at actualRate
     * @param {number} actualRate - Real sample rate of AudioContext
     * @returns {Float32Array} Downsampled PCM at ~16kHz
     */
    _downsampleCIC(samples, actualRate) {
        const M = Math.round(actualRate / 16000);
        if (M <= 1) {
            return samples;
        }
        const outLen = Math.floor(samples.length / M);
        const output = new Float32Array(outLen);
        for (let i = 0; i < outLen; i++) {
            const base = i * M;
            let sum = 0;
            for (let j = 0; j < M; j++) {
                sum += samples[base + j];
            }
            output[i] = sum / M;
        }
        console.warn("[ChileMon] CIC downsample: %d->16000 Hz, M=%d", actualRate, M);
        return output;
    }

    /**
     * Convert a Float32Array of audio samples to a hex string.
     * Each float32 -> 4 bytes (little-endian) -> 8 hex chars.
     * Matches what server.py:audio_tx expects.
     */
    _samplesToHex(samples) {
        // Copy to a contiguous buffer (samples may be a view into a larger buffer)
        const copy = new Float32Array(samples);
        const bytes = new Uint8Array(copy.buffer);
        let hex = '';
        for (let i = 0; i < bytes.length; i++) {
            const b = bytes[i];
            hex += b < 16 ? '0' + b.toString(16) : b.toString(16);
        }
        return hex;
    }

    /** Send an audio chunk over the WebSocket as audio_tx. */
    _sendAudioChunk(samples, rate = 16000) {
        // Feed to visualizer (TX audio)
        if (this._visualizer) {
            this._visualizer.feedPCM(samples);
        }

        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.warn('PTT: ws not open, readyState=', this.ws ? this.ws.readyState : 'null');
            return;
        }
        try {
            const hex = this._samplesToHex(samples);
            const msg = JSON.stringify({ type: 'audio_tx', data: hex, rate: rate });
            console.log('PTT: sending audio_tx', hex.length, 'hex chars');
            this.ws.send(msg);
        } catch (err) {
            console.error('PTT: send error', err);
        }
    }

    /** Push an RMS volume sample and update the volume bar. */
    _pushVolume(rms) {
        this.volumeSamples.push(rms);
        if (this.volumeSamples.length > this.maxVolumeSamples) {
            this.volumeSamples.shift();
        }

        // Smoothed RMS (average of recent samples)
        let sum = 0;
        for (const s of this.volumeSamples) {
            sum += s;
        }
        const avg = sum / this.volumeSamples.length;

        // Clamp and scale for display (RMS ~0.0-1.0, usually 0-0.5 for speech)
        const display = Math.min(1.0, avg * 4);
        const pct = Math.round(display * 100);

        if (this.volumeFill) {
            this.volumeFill.style.width = pct + '%';
        }
    }

    /** Convert hex string to Uint8Array. */
    _hexToBytes(hex) {
        if (hex.length % 2 !== 0) return null;
        const len = hex.length / 2;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = parseInt(hex.substring(i * 2, i * 2 + 2), 16);
        }
        return bytes;
    }

    // ---------------------------------------------------------------
    //  PTT — Key / Unkey
    // ---------------------------------------------------------------

    keyPtt() {
        if (!this.connected || this.pttActive) return;
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) return;

        console.log('PTT: key pressed, sending ptt key');
        this.ws.send(JSON.stringify({ type: 'ptt', action: 'key' }));
        this.pttActive = true;
        if (this._visualizer) this._visualizer.setTransmitting(true);
        this._setStatus('transmitting', 'TRANSMITTING');
        this._updateUI();
        this.startCapture();
    }

    unkeyPtt() {
        if (!this.pttActive) return;
        this.stopCapture();
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({ type: 'ptt', action: 'unkey' }));
        }
        this.pttActive = false;
        if (this._visualizer) this._visualizer.setTransmitting(false);
        this._setStatus('connected', 'Bridge Connected');
        this._updateUI();
    }

    // ---------------------------------------------------------------
    //  Audio Gain Control (RX / TX)
    // ---------------------------------------------------------------

    /**
     * Set RX (output) gain. Value 0.0–2.0 (1.0 = normal, 2.0 = 200%).
     * @param {number} value
     */
    setRxGain(value) {
        const v = Math.max(0, Math.min(2, Number(value) || 1));
        if (this._rxGainNode) {
            this._rxGainNode.gain.setTargetAtTime(v, this.audioCtx.currentTime, 0.01);
        }
        try { localStorage.setItem('chilemon_rx_gain', String(v)); } catch (_) {}
    }

    /**
     * Set TX (input/mic) gain. Value 0.0–2.0.
     * @param {number} value
     */
    setTxGain(value) {
        const v = Math.max(0, Math.min(2, Number(value) || 1));
        if (this._txGainNode) {
            this._txGainNode.gain.setTargetAtTime(v, this._txCtx.currentTime, 0.01);
        }
        try { localStorage.setItem('chilemon_tx_gain', String(v)); } catch (_) {}
    }

    /**
     * Get stored gain from localStorage.
     * @param {'rx'|'tx'} type
     * @returns {number} gain value (default 1.0)
     */
    _getStoredGain(type) {
        try {
            const key = type === 'rx' ? 'chilemon_rx_gain' : 'chilemon_tx_gain';
            const v = parseFloat(localStorage.getItem(key));
            return isNaN(v) ? 1 : Math.max(0, Math.min(2, v));
        } catch (_) { return 1; }
    }

    // ---------------------------------------------------------------
    //  UI — Status
    // ---------------------------------------------------------------

    _setStatus(state, text) {
        if (this.statusDot) {
            this.statusDot.className = 'ptt-status-dot ptt-status-' + state;
        }
        if (this.statusText) {
            this.statusText.textContent = text;
        }
    }

    _updateUI() {
        // PTT button state
        if (this.pttButton) {
            this.pttButton.classList.toggle('ptt-active', this.pttActive);
            this.pttButton.disabled = !this.connected;
        }
        if (this.pttLabel) {
            this.pttLabel.textContent = this.pttActive ? 'TRANSMITTING' : 'PTT';
            this.pttLabel.classList.toggle('ptt-label-active', this.pttActive);
        }

        // Volume bar enabled/disabled
        if (this.volumeContainer) {
            this.volumeContainer.classList.toggle('ptt-volume-disabled', !this.connected);
        }

        // Widget visibility
        if (this.widget) {
            this.widget.classList.toggle('ptt-connected', this.connected);
        }
    }

    // ---------------------------------------------------------------
    //  DOM creation
    // ---------------------------------------------------------------

    _createDOM() {
        // Prevent duplicate
        if (document.getElementById('ptt-widget')) return;

        this.widget = document.createElement('div');
        this.widget.id = 'ptt-widget';
        this.widget.className = 'ptt-widget';

        this.widget.innerHTML = `
            <div class="ptt-header">
                <span class="ptt-status-dot ptt-status-disconnected" id="ptt-status-dot"></span>
                <span class="ptt-status-text" id="ptt-status-text">Initializing...</span>
            </div>
            <div class="ptt-volume-bar" id="ptt-volume-bar">
                <div class="ptt-volume-fill" id="ptt-volume-fill"></div>
            </div>
            <button class="ptt-button" id="ptt-button" title="Push to Talk (hold spacebar)" aria-label="Push to Talk">
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

        // Bind widget-specific events
        this.pttButton.addEventListener('mousedown', (e) => {
            e.preventDefault();
            this.keyPtt();
        });
        this.pttButton.addEventListener('mouseup', this._onMouseUp);
        this.pttButton.addEventListener('mouseleave', this._onMouseUp);

        // Touch support for mobile
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
        // Spacebar — but not when typing in an input/textarea
        if (e.code === 'Space') {
            const tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            e.preventDefault(); // siempre bloquear scroll, incluso en repeat
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
