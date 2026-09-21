(function () {
    'use strict';

    class PttController {
        constructor(button, options) {
            this.button = button;
            this.onStart = options.onStart;
            this.onStop = options.onStop;
            this.pressed = false;
            this.bind();
        }

        bind() {
            this.button.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                try {
                    this.button.setPointerCapture(event.pointerId);
                } catch (error) {
                    /* Some browsers do not require capture. */
                }
                this.press();
            });

            const stop = (event) => {
                if (event) {
                    event.preventDefault();
                }
                this.release();
            };

            this.button.addEventListener('pointerup', stop);
            this.button.addEventListener('pointercancel', stop);
            this.button.addEventListener('lostpointercapture', stop);
            this.button.addEventListener('contextmenu', (event) => event.preventDefault());
            window.addEventListener('pointerup', () => this.release());
            window.addEventListener('blur', () => this.release());

            window.addEventListener('keydown', (event) => {
                if (event.code !== 'Space' || event.repeat) {
                    return;
                }
                const tag = (event.target && event.target.tagName) || '';
                if (tag === 'INPUT' || tag === 'TEXTAREA') {
                    return;
                }
                event.preventDefault();
                this.press();
            });

            window.addEventListener('keyup', (event) => {
                if (event.code === 'Space') {
                    event.preventDefault();
                    this.release();
                }
            });
        }

        press() {
            if (this.pressed) {
                return;
            }
            this.pressed = true;
            this.button.classList.add('is-down');
            this.button.setAttribute('aria-pressed', 'true');
            this.onStart();
        }

        release() {
            if (!this.pressed) {
                return;
            }
            this.pressed = false;
            this.button.classList.remove('is-down');
            this.button.setAttribute('aria-pressed', 'false');
            this.onStop();
        }
    }

    window.WalkiePtt = PttController;
})();
