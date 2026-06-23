/**
 * public/assets/js/audio-visualizer.js
 * -----------------------------------------------
 * AudioVisualizer — Spectrum analyzer for the
 * ChileMon dashboard.
 *
 * Renders frequency bars (like an equalizer) on a
 * dark canvas with colored glow. Receives raw PCM
 * float32 data from both RX and TX audio paths and
 * computes a real FFT for frequency-domain display.
 *
 * Usage:
 *   const viz = new AudioVisualizer('audio-canvas');
 *   viz.feedPCM(samples);  // Float32Array from RX/TX
 *
 * Styles expected: .audio-visualizer-card, #audio-canvas
 * -----------------------------------------------
 */

class AudioVisualizer {

    /**
     * @param {string} canvasId  ID of the <canvas> element
     * @param {Object} [opts]
     * @param {number} [opts.barCount=48]       Number of vertical bars
     * @param {number} [opts.fftSize=1024]      FFT size (power of 2)
     * @param {number} [opts.decayRate=0.88]    Per-frame decay multiplier
     * @param {number} [opts.smoothFactor=0.75] Smoothing (0=instant, 1=no change)
     */
    constructor(canvasId, opts = {}) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) {
            console.warn('AudioVisualizer: canvas #' + canvasId + ' not found');
            return;
        }
        this.ctx = this.canvas.getContext('2d');

        this.barCount = opts.barCount || 48;
        this.fftSize = opts.fftSize || 1024;
        this.decayRate = opts.decayRate || 0.88;
        this.smoothFactor = opts.smoothFactor || 0.75;

        /** @type {Float32Array} Current bar heights (0..1) */
        this.bars = new Float32Array(this.barCount);

        /** @type {Float32Array} Peak dots */
        this.peaks = new Float32Array(this.barCount);

        /** @type {number} Timestamp of last feedPCM call */
        this._lastFeed = 0;

        /** @type {number} FFT half length */
        this._half = this.fftSize >>> 1;

        // Pre-allocate FFT buffers (reused across frames to avoid GC)
        this._re = new Float64Array(this.fftSize);
        this._im = new Float64Array(this.fftSize);

        // Build bar→bin mapping: which FFT bins map to each bar
        // Logarithmic mapping — more bars for low frequencies
        this._binMap = new Uint16Array(this.barCount);
        const logMin = Math.log2(1);
        const logMax = Math.log2(this._half);
        for (let b = 0; b < this.barCount; b++) {
            const t = b / this.barCount;
            const binIdx = Math.round(Math.pow(2, logMin + t * (logMax - logMin)));
            this._binMap[b] = Math.min(binIdx, this._half - 1);
        }

        // Card element for border glow
        this._card = this.canvas.closest('.audio-visualizer-card');

        // Resize canvas backing store to match CSS size
        this._resize();
        window.addEventListener('resize', () => this._resize());

        // Start the render loop
        this._running = true;
        this._draw();
    }

    /** Stop the animation loop and clean up. */
    destroy() {
        this._running = false;
    }

    /**
     * Mark whether the node is currently transmitting (TX active).
     * Changes the card border glow color: yellow for TX, green for RX.
     * @param {boolean} active
     */
    setTransmitting(active) {
        this._transmitting = active;
    }

    /**
     * Feed raw PCM float32 samples into the visualizer.
     * Call this from your audio RX/TX handlers.
     * @param {Float32Array|Array<number>} samples
     */
    feedPCM(samples) {
        if (!samples || samples.length < 4) return;
        this._lastFeed = Date.now();

        const n = this.fftSize;

        // Copy into FFT buffers (zero-pad if smaller)
        const len = Math.min(samples.length, n);
        for (let i = 0; i < len; i++) {
            this._re[i] = samples[i];
            this._im[i] = 0;
        }
        for (let i = len; i < n; i++) {
            this._re[i] = 0;
            this._im[i] = 0;
        }

        // Apply Hann window
        for (let i = 0; i < n; i++) {
            this._re[i] *= 0.5 * (1 - Math.cos((2 * Math.PI * i) / (n - 1)));
        }

        // In-place radix-2 FFT
        this._fft(this._re, this._im);

        // Compute magnitudes (first half) and map to bars
        for (let b = 0; b < this.barCount; b++) {
            const binIdx = this._binMap[b];
            const mag = Math.sqrt(
                this._re[binIdx] * this._re[binIdx] +
                this._im[binIdx] * this._im[binIdx]
            );
            // Normalize: divide by fftSize, clamp to ~1.0
            let normalized = (mag / this.fftSize) * 6;
            if (normalized > 1) normalized = 1;

            // Smooth towards target
            const prev = this.bars[b];
            this.bars[b] = prev + (normalized - prev) * (1 - this.smoothFactor);

            // Update peak
            if (this.bars[b] > this.peaks[b]) {
                this.peaks[b] = this.bars[b];
            }
        }
    }

    // ---------------------------------------------------------------
    //  Internal
    // ---------------------------------------------------------------

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
    }

    /** Main render loop. */
    _draw() {
        if (!this._running) return;
        requestAnimationFrame(() => this._draw());

        const now = Date.now();
        const idle = (now - this._lastFeed) > 300; // no data in 300ms

        if (idle) {
            // Decay all bars
            for (let i = 0; i < this.barCount; i++) {
                this.bars[i] *= this.decayRate;
                if (this.bars[i] < 0.002) this.bars[i] = 0;
            }
        }

        // Decay peaks (faster than bars for the falling-dot effect)
        for (let i = 0; i < this.barCount; i++) {
            this.peaks[i] *= 0.92;
            if (this.peaks[i] < this.bars[i]) {
                this.peaks[i] = this.bars[i];
            }
        }

        // Card border glow — green for RX, yellow for TX
        if (this._card) {
            const hasSignal = !idle && this.bars.some(v => v > 0.02);
            this._card.classList.toggle('active', hasSignal && !this._transmitting);
            this._card.classList.toggle('transmitting', hasSignal && this._transmitting);
        }

        this._render();
    }

    /** Paint the canvas. */
    _render() {
        const w = this.canvas.width;
        const h = this.canvas.height;
        const ctx = this.ctx;

        // Clear
        ctx.clearRect(0, 0, w, h);

        if (w < 10 || h < 10) return;

        const barGap = 2; // px gap between bars
        const barTotal = w / this.barCount;
        const barW = Math.max(2, barTotal - barGap);
        const marginBottom = 3; // px from bottom

        // Pre-multiply height to avoid per-frame division
        const hScale = (h - marginBottom);

        for (let i = 0; i < this.barCount; i++) {
            const barH = Math.min(this.bars[i], 1) * hScale;
            if (barH < 0.5) continue;

            const x = i * barTotal;

            // Color: green (low amp) → yellow (mid) → red (high amp)
            const t = this.bars[i];
            let r, g, b;
            if (t < 0.4) {
                // Green → Yellow
                const p = t / 0.4;
                r = Math.floor(p * 255);
                g = 255;
                b = 40;
            } else {
                // Yellow → Red
                const p = (t - 0.4) / 0.6;
                r = 255;
                g = Math.floor((1 - p) * 255);
                b = Math.floor((1 - p) * 40);
            }

            // Glow
            ctx.shadowBlur = 18;
            ctx.shadowColor = `rgb(${r},${g},${b})`;

            // Bar
            ctx.fillStyle = `rgb(${r},${g},${b})`;
            ctx.fillRect(x, h - marginBottom - barH, barW, barH);

            // Reset shadow before peak dot
            ctx.shadowBlur = 0;

            // Peak dot (2px tall)
            const peakY = h - marginBottom - this.peaks[i] * hScale;
            if (peakY > marginBottom) {
                ctx.fillStyle = `rgba(${r},${g},${b},0.7)`;
                ctx.fillRect(x, peakY - 1, barW, 2);
            }
        }
    }

    // ---------------------------------------------------------------
    //  Radix-2 Cooley-Tukey FFT (in-place)
    // ---------------------------------------------------------------

    /**
     * In-place FFT using bit-reversal + Cooley-Tukey.
     * @param {Float64Array} re  Real part (modified in-place)
     * @param {Float64Array} im  Imaginary part (modified in-place)
     */
    _fft(re, im) {
        const n = re.length;
        if (n <= 1) return;

        // Bit-reversal permutation
        const bits = Math.round(Math.log2(n));
        for (let i = 1; i < n; i++) {
            let j = 0;
            let m = i;
            for (let b = 0; b < bits; b++) {
                j = (j << 1) | (m & 1);
                m >>= 1;
            }
            if (j > i) {
                let tmp = re[i]; re[i] = re[j]; re[j] = tmp;
                tmp = im[i]; im[i] = im[j]; im[j] = tmp;
            }
        }

        // Butterfly
        for (let len = 2; len <= n; len <<= 1) {
            const half = len >>> 1;
            const ang = (2 * Math.PI) / len;
            const wRe = Math.cos(ang);
            const wIm = -Math.sin(ang);
            for (let i = 0; i < n; i += len) {
                let curRe = 1;
                let curIm = 0;
                for (let j = 0; j < half; j++) {
                    const uRe = re[i + j];
                    const uIm = im[i + j];
                    const vRe = re[i + j + half] * curRe - im[i + j + half] * curIm;
                    const vIm = re[i + j + half] * curIm + im[i + j + half] * curRe;
                    re[i + j] = uRe + vRe;
                    im[i + j] = uIm + vIm;
                    re[i + j + half] = uRe - vRe;
                    im[i + j + half] = uIm - vIm;
                    const nxtRe = curRe * wRe - curIm * wIm;
                    curIm = curRe * wIm + curIm * wRe;
                    curRe = nxtRe;
                }
            }
        }
    }
}

// Expose globally for the PTT widget
window.AudioVisualizer = AudioVisualizer;
