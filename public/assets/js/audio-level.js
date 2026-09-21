(function () {
    'use strict';

    class AudioLevelMeter {
        constructor(canvas) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');
            this.audioContext = null;
            this.analyser = null;
            this.sources = new Map();
            this.data = null;
            this.raf = null;
            this.active = false;
        }

        async ensureContext() {
            if (this.audioContext) {
                if (this.audioContext.state === 'suspended') {
                    await this.audioContext.resume();
                }
                return;
            }
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            this.analyser = this.audioContext.createAnalyser();
            this.analyser.fftSize = 64;
            this.data = new Uint8Array(this.analyser.frequencyBinCount);
        }

        async watch(id, stream) {
            await this.ensureContext();
            if (this.sources.has(id)) {
                this.sources.get(id).disconnect();
            }
            const source = this.audioContext.createMediaStreamSource(stream);
            source.connect(this.analyser);
            this.sources.set(id, source);
            this.start();
        }

        unwatch(id) {
            const source = this.sources.get(id);
            if (source) {
                source.disconnect();
                this.sources.delete(id);
            }
            if (this.sources.size === 0) {
                this.stop();
            }
        }

        start() {
            if (this.active) {
                return;
            }
            this.active = true;
            const draw = () => {
                if (!this.active) {
                    return;
                }
                this.analyser.getByteFrequencyData(this.data);
                this.render();
                this.raf = window.requestAnimationFrame(draw);
            };
            draw();
        }

        stop() {
            this.active = false;
            if (this.raf) {
                window.cancelAnimationFrame(this.raf);
            }
            this.clear();
        }

        render() {
            const { canvas, ctx, data } = this;
            const width = canvas.width;
            const height = canvas.height;
            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#0b1220';
            ctx.fillRect(0, 0, width, height);

            const bars = data.length;
            const gap = 4;
            const barWidth = Math.max(6, (width - gap * (bars - 1)) / bars);
            for (let i = 0; i < bars; i++) {
                const value = data[i] / 255;
                const barHeight = Math.max(4, value * (height - 10));
                const x = i * (barWidth + gap);
                const y = height - barHeight;
                ctx.fillStyle = value > 0.75 ? '#fb7185' : (value > 0.4 ? '#f59e0b' : '#22c55e');
                ctx.fillRect(x, y, barWidth, barHeight);
            }
        }

        clear() {
            const { canvas, ctx } = this;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#0b1220';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }
    }

    window.WalkieMeter = AudioLevelMeter;
})();
