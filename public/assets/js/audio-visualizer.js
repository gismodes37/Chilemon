/**
 * public/assets/js/audio-visualizer.js
 * -----------------------------------------------
 * AudioVisualizer — Radio telemetry spectrum console for the
 * ChileMon dashboard.
 *
 * Renders 32 bars from the 16 log-spaced spectrum bands emitted by
 * the Companion. RMS is mapped through dB with a per-direction
 * adaptive noise floor so weak signals stay visible. No audio
 * processing happens here: the Companion owns the FFT.
 *
 * Usage:
 *   const viz = new AudioVisualizer('audio-canvas');
 *   viz.feedMetadata(rms, spectrum, dir);  // dir: 'rx' | 'tx'
 *
 * Styles expected: .audio-visualizer-card, #audio-canvas
 * -----------------------------------------------
 */

const BAND_COUNT = 16;
const BAR_COUNT = 32;

// Display window: -70 dB (adaptive floor) up to -12 dB, with at least
// 48 dB of dynamic range above the floor so loud audio never clips.
const DEFAULT_FLOOR_DB = -70;
const CEILING_DB = -12;
const MIN_SPAN_DB = 48;

// The floor follows slow ambient changes inside a 24 dB window but ignores
// vocal bursts. Separate coefficients make it fall slower than it rises.
const FLOOR_WINDOW_DB = 24;
const FLOOR_RISE = 0.01;
const FLOOR_FALL = 0.004;

// Time constants (ms) for the rAF-driven attack/decay envelope.
const ATTACK_MS = 60;
const DECAY_MS = 320;
const PEAK_HOLD_MS = 500;
const PEAK_DECAY_MS = 900;
const IDLE_MS = 250;

// Needle profile: slim 2-4 CSS px lit width with a visible gap. When the
// canvas is too narrow for 32 needles, reduce the rendered count instead.
const MIN_LIT_CSS_PX = 2;
const MAX_LIT_CSS_PX = 4;
const LIT_RATIO = 0.4;
const MIN_GAP_CSS_PX = 1;

const PALETTES = {
    rx: { primary: '#22d3ee', secondary: '#2dd4bf', rgb: '34,211,238' },
    tx: { primary: '#f59e0b', secondary: '#fbbf24', rgb: '245,158,11' },
};

class AudioVisualizer {

    /**
     * @param {string} canvasId  ID of the <canvas> element
     * @param {Object} [opts]
     * @param {number} [opts.barCount=32] Number of vertical bars
     */
    constructor(canvasId, opts = {}) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) {
            console.warn('AudioVisualizer: canvas #' + canvasId + ' not found');
            return;
        }
        this.ctx = this.canvas.getContext('2d');

        this.barCount = opts.barCount || BAR_COUNT;

        /** @type {Float32Array} Resampled 16-band spectrum (target) */
        this._bands = new Float32Array(BAND_COUNT);
        /** @type {Float32Array} Per-bar spectrum targets */
        this._targetBars = new Float32Array(this.barCount);
        /** @type {Float32Array} Rendered bar heights (0..1) */
        this._bars = new Float32Array(this.barCount);
        /** @type {Float32Array} Peak-hold markers */
        this._peaks = new Float32Array(this.barCount);
        /** @type {Float64Array} When each peak starts falling (rAF clock) */
        this._peakHeldUntil = new Float64Array(this.barCount);
        /** @type {Float32Array} Fractional band index for each bar */
        this._bandPositions = new Float32Array(this.barCount);

        /** @type {{rx: number, tx: number}} Adaptive noise floor in dB */
        this._noiseFloor = { rx: DEFAULT_FLOOR_DB, tx: DEFAULT_FLOOR_DB };
        this._direction = 'tx';
        this._level = 0;
        this._displayLevel = 0;
        this._transmitting = false;
        this._lastFeed = -Infinity;
        this._lastFrame = 0;

        // More bars per low band: band positions grow exponentially while the
        // incoming bands are already log-spaced in frequency.
        const bandScale = Math.log2(BAND_COUNT);
        for (let b = 0; b < this.barCount; b++) {
            const t = this.barCount > 1 ? b / (this.barCount - 1) : 0;
            this._bandPositions[b] = Math.pow(2, t * bandScale) - 1;
        }

        this._card = this.canvas.closest('.audio-visualizer-card');
        this._chip = document.getElementById('audio-direction-chip');
        this._status = document.getElementById('audio-state-readout');
        this._dbReadout = document.getElementById('audio-db-readout');

        this._reducedMotion = typeof window !== 'undefined'
            && typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this._resize();
        this._onResize = () => this._resize();
        window.addEventListener('resize', this._onResize);
        this._updateDom();

        this._running = !this._reducedMotion;
        if (this._running) {
            this._draw();
        } else {
            this._render();
        }
    }

    /** Stop the animation loop and clean up. */
    destroy() {
        this._running = false;
        if (this._onResize && typeof window.removeEventListener === 'function') {
            window.removeEventListener('resize', this._onResize);
        }
    }

    /**
     * Mark whether the node is currently transmitting (TX active).
     * Keeps the card border hooks: .active for RX, .transmitting for TX.
     * @param {boolean} active
     */
    setTransmitting(active) {
        this._transmitting = Boolean(active);
        if (this._reducedMotion) {
            this._updateActivity(this._level > 0.04);
            this._render();
        }
    }

    /**
     * Feed pre-computed metadata from the Companion audio_level message.
     *
     * @param {number} rms        Raw linear RMS 0..1 (unmodified from source)
     * @param {number[]} spectrum 16 bands (4 during mixed-version rollout)
     * @param {'rx'|'tx'} [dir]   Direction, defaults to 'tx'
     */
    feedMetadata(rms, spectrum, dir = 'tx') {
        if (!this.canvas) return;

        const raw = Number.isFinite(rms) ? Math.max(0, rms) : 0;
        const db = 20 * Math.log10(Math.max(raw, 1e-6));
        this._direction = dir === 'rx' ? 'rx' : 'tx';

        const floor = this._adaptFloor(db, this._noiseFloor[this._direction]);
        this._noiseFloor[this._direction] = floor;
        const ceiling = Math.max(CEILING_DB, floor + MIN_SPAN_DB);
        this._level = this._clamp((db - floor) / (ceiling - floor));

        this._lastFeed = this._now();
        this._levelTarget = this._level;
        this._resampleSpectrum(spectrum);
        this._updateDom(db);

        if (this._reducedMotion) {
            this._displayLevel = this._level;
            for (let i = 0; i < this.barCount; i++) {
                this._bars[i] = this._targetBars[i];
                this._peaks[i] = this._bars[i];
            }
            this._updateActivity(this._level > 0.04);
            this._render();
        }
    }

    /** @return {number} Latest dB-mapped level (0..1). */
    getLevel() {
        return this._level;
    }

    /** @return {'rx'|'tx'} Latest direction. */
    getDirection() {
        return this._direction;
    }

    /** @return {number[]} Resampled 16-band spectrum. */
    getBands() {
        return Array.from(this._bands);
    }

    /** @return {number[]} Current rendered bar heights. */
    getBars() {
        return Array.from(this._bars);
    }

    /** @return {number} Adaptive floor (dB) for a direction. */
    getNoiseFloor(dir) {
        return this._noiseFloor[dir === 'rx' ? 'rx' : 'tx'];
    }

    /** @return {{primary: string, secondary: string, rgb: string}} */
    getPalette() {
        return { ...(PALETTES[this._direction] || PALETTES.tx) };
    }

    /**
     * Rendered needle profile. The spectrum keeps 32 log-spaced bars, but a
     * narrow canvas drops rendered columns before a needle loses its 2 px.
     * @return {{count: number, slotCss: number, litCss: number, gapCss: number,
     *           slotDevice: number, litDevice: number, gapDevice: number}}
     */
    getBarProfile() {
        if (!this._profile) {
            const dpr = window.devicePixelRatio || 1;
            this._profile = this._computeProfile(this.canvas ? this.canvas.width : 0, dpr);
        }
        return { ...this._profile };
    }

    // ---------------------------------------------------------------
    //  Internal
    // ---------------------------------------------------------------

    _now() {
        return typeof performance !== 'undefined' && typeof performance.now === 'function'
            ? performance.now()
            : Date.now();
    }

    _clamp(value) {
        return value < 0 ? 0 : (value > 1 ? 1 : value);
    }

    _adaptFloor(db, floor) {
        // Speech and telemetry bursts are well above ambient: never let them
        // drag the floor up and desensitise the display afterwards.
        if (db > floor + FLOOR_WINDOW_DB) return floor;
        const rate = db < floor ? FLOOR_FALL : FLOOR_RISE;
        return floor + (db - floor) * rate;
    }

    /** Resample any incoming spectrum length into the internal 16 bands. */
    _resampleSpectrum(spectrum) {
        const values = Array.isArray(spectrum) ? spectrum : [];
        const len = values.length;

        for (let i = 0; i < BAND_COUNT; i++) {
            let value;
            if (len === 0) {
                // Mixed-version silence/metadata gap: keep a level-shaped
                // envelope instead of a dead flat line.
                value = this._level * (1 - i / BAND_COUNT) * 0.6;
            } else if (len === 1) {
                value = Number.isFinite(values[0]) ? values[0] : 0;
            } else {
                const pos = (i * (len - 1)) / (BAND_COUNT - 1);
                const lo = Math.floor(pos);
                const hi = Math.min(lo + 1, len - 1);
                const frac = pos - lo;
                const a = Number.isFinite(values[lo]) ? values[lo] : 0;
                const b = Number.isFinite(values[hi]) ? values[hi] : 0;
                value = a * (1 - frac) + b * frac;
            }
            this._bands[i] = this._clamp(value);
        }

        // Spectrum level is scaled by the dB-mapped RMS so quiet audio still
        // moves the bars, while loud audio keeps its full dynamic range.
        const gain = 0.4 + 0.6 * this._level;
        for (let b = 0; b < this.barCount; b++) {
            const pos = this._bandPositions[b];
            const lo = Math.floor(pos);
            const hi = Math.min(lo + 1, BAND_COUNT - 1);
            const frac = pos - lo;
            const value = this._bands[lo] * (1 - frac) + this._bands[hi] * frac;
            this._targetBars[b] = this._clamp(value * gain);
        }
    }

    /** Render loop. All envelope changes use the rAF timestamp. */
    _draw(timestamp) {
        if (!this._running) return;
        requestAnimationFrame((ts) => this._draw(ts));

        const now = Number.isFinite(timestamp) ? timestamp : this._now();
        const dt = Math.max(0, Math.min(now - this._lastFrame, 250));
        this._lastFrame = now;

        const idle = now - this._lastFeed > IDLE_MS;
        const targetLevel = idle ? 0 : this._level;
        this._displayLevel += (targetLevel - this._displayLevel)
            * this._envelope(dt, targetLevel, this._displayLevel, ATTACK_MS, DECAY_MS);

        for (let i = 0; i < this.barCount; i++) {
            const target = idle ? 0 : this._targetBars[i];
            this._bars[i] += (target - this._bars[i])
                * this._envelope(dt, target, this._bars[i], ATTACK_MS, DECAY_MS);
            // Freeze the target once reached: without a fresh feed the trace
            // holds instead of slowly climbing towards an old spectrum.
            this._targetBars[i] = this._bars[i];

            if (this._bars[i] > this._peaks[i]) {
                this._peaks[i] = this._bars[i];
                this._peakHeldUntil[i] = now + PEAK_HOLD_MS;
            } else if (now > this._peakHeldUntil[i]) {
                this._peaks[i] += (this._bars[i] - this._peaks[i])
                    * this._envelope(dt, this._bars[i], this._peaks[i], ATTACK_MS, PEAK_DECAY_MS);
                if (this._peaks[i] < 0.002) this._peaks[i] = 0;
            }
        }

        this._updateActivity(!idle && this._displayLevel > 0.04);
        this._render();
    }

    _envelope(dt, target, current, attackMs, decayMs) {
        const tau = target > current ? attackMs : decayMs;
        return 1 - Math.exp(-dt / tau);
    }

    _updateActivity(hasSignal) {
        if (!this._card) return;
        const tx = this._transmitting || this._direction === 'tx';
        this._card.classList.toggle('active', hasSignal && !tx);
        this._card.classList.toggle('transmitting', hasSignal && tx);
    }

    _updateDom(db) {
        if (this._chip) {
            this._chip.textContent = this._direction.toUpperCase();
            this._chip.dataset.dir = this._direction;
        }
        if (this._status) {
            let label = 'SIN SEÑAL';
            let key = 'silence';
            if (this._level >= 0.55) {
                label = 'VOZ';
                key = 'voice';
            } else if (this._level >= 0.08) {
                label = 'RUIDO';
                key = 'noise';
            }
            this._status.textContent = label;
            this._status.dataset.state = key;
        }
        if (this._dbReadout) {
            const shown = Number.isFinite(db) && db > -100 ? Math.round(db) : '--';
            this._dbReadout.textContent = shown + ' dB';
        }
    }

    /** Resize canvas backing store for crisp rendering. */
    _resize() {
        const rect = this.canvas.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        const w = Math.round(rect.width * dpr);
        const h = Math.round(rect.height * dpr);
        if (this.canvas.width !== w || this.canvas.height !== h) {
            this.canvas.width = w;
            this.canvas.height = h;
        }
        this._profile = this._computeProfile(w, dpr);
    }

    /** Choose a slim needle geometry that stays legible at this width. */
    _computeProfile(w, dpr) {
        const cssW = w / dpr;
        let count = Math.max(1, Math.min(
            this.barCount,
            Math.floor(cssW / (MIN_LIT_CSS_PX + MIN_GAP_CSS_PX))
        ));
        let litCss = MIN_LIT_CSS_PX;

        while (count > 1) {
            const slotCss = cssW / count;
            const candidate = Math.max(MIN_LIT_CSS_PX, Math.min(
                MAX_LIT_CSS_PX,
                Math.round(slotCss * LIT_RATIO)
            ));
            if (slotCss - candidate >= MIN_GAP_CSS_PX) {
                litCss = candidate;
                break;
            }
            count--;
        }

        if (count === 1) {
            litCss = Math.max(MIN_LIT_CSS_PX, Math.min(MAX_LIT_CSS_PX, cssW));
        }

        const slotCss = cssW / count;
        const slotDevice = w / count;
        const litDevice = Math.max(2, Math.round(litCss * dpr));
        return {
            count,
            slotCss,
            litCss,
            gapCss: slotCss - litCss,
            slotDevice,
            litDevice,
            gapDevice: slotDevice - litDevice,
        };
    }

    /**
     * Paint the console: fine graticule, one soft glow layer, gradient bars,
     * peak-hold markers. No per-bar shadowBlur: one gradient keeps 60fps cheap.
     */
    _render() {
        if (!this.ctx) return;
        const ctx = this.ctx;
        const w = this.canvas.width;
        const h = this.canvas.height;

        ctx.clearRect(0, 0, w, h);
        if (w < 10 || h < 10) return;

        const palette = PALETTES[this._direction] || PALETTES.tx;
        const rgb = palette.rgb;

        const profile = this.getBarProfile();
        const { count, slotDevice, litDevice } = profile;
        const needleX = (r) => Math.round(r * slotDevice + (slotDevice - litDevice) / 2);

        // Fine graticule — amplitude divisions and 4-needle time groups.
        ctx.fillStyle = `rgba(${rgb},0.07)`;
        for (let i = 1; i < 4; i++) {
            ctx.fillRect(0, Math.round((h * i) / 4), w, 1);
        }
        for (let r = 4; r < count; r += 4) {
            ctx.fillRect(Math.round(r * slotDevice), 0, 1, h);
        }

        // One soft glow layer behind the whole trace.
        const glowH = Math.max(8, h * (0.15 + 0.85 * this._displayLevel));
        const glow = ctx.createLinearGradient(0, h, 0, h - glowH);
        glow.addColorStop(0, `rgba(${rgb},0.16)`);
        glow.addColorStop(1, `rgba(${rgb},0)`);
        ctx.fillStyle = glow;
        ctx.fillRect(0, h - glowH, w, glowH);

        const barGradient = ctx.createLinearGradient(0, h, 0, 0);
        barGradient.addColorStop(0, `rgba(${rgb},0.5)`);
        barGradient.addColorStop(0.4, palette.primary);
        barGradient.addColorStop(1, palette.secondary);
        ctx.fillStyle = barGradient;

        const usableH = h - 2;
        for (let r = 0; r < count; r++) {
            const from = Math.floor((r * this.barCount) / count);
            const to = Math.max(from + 1, Math.ceil(((r + 1) * this.barCount) / count));
            let value = 0;
            for (let i = from; i < to; i++) {
                if (this._bars[i] > value) value = this._bars[i];
            }
            const barH = Math.min(value, 1) * usableH;
            if (barH < 0.5) continue;
            ctx.fillRect(needleX(r), h - 1 - barH, litDevice, barH);
        }

        ctx.fillStyle = `rgba(${rgb},0.8)`;
        for (let r = 0; r < count; r++) {
            const from = Math.floor((r * this.barCount) / count);
            const to = Math.max(from + 1, Math.ceil(((r + 1) * this.barCount) / count));
            let peak = 0;
            for (let i = from; i < to; i++) {
                if (this._peaks[i] > peak) peak = this._peaks[i];
            }
            const peakY = h - 1 - Math.min(peak, 1) * usableH;
            if (peakY > 1 && peak > 0.01) {
                ctx.fillRect(needleX(r), peakY - 1, litDevice, 1);
            }
        }
    }
}

// Expose globally for the PTT widget
window.AudioVisualizer = AudioVisualizer;
