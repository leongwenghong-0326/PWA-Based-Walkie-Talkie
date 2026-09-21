(function () {
    'use strict';

    class SignalingClient {
        constructor(config) {
            this.url = config.signalUrl;
            this.leaveUrl = config.leaveUrl;
            this.token = config.token;
            this.csrf = config.csrf;
            this.pollIntervalMs = config.pollIntervalMs || 800;
            this.lastSeq = 0;
            this.active = false;
            this.timer = null;
            this.backoff = 1000;
            this.onEvent = config.onEvent || function () {};
            this.onState = config.onState || function () {};
            this.onHello = config.onHello || function () {};
            this.onError = config.onError || function () {};
        }

        async post(body) {
            const response = await fetch(this.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': this.csrf
                },
                credentials: 'same-origin',
                body: JSON.stringify(Object.assign({ token: this.token }, body))
            });

            const data = await response.json().catch(function () { return null; });
            if (!response.ok || !data) {
                const error = new Error((data && data.message) || 'Unable to connect to the communication server.');
                error.status = response.status;
                error.payload = data;
                throw error;
            }
            return data;
        }

        async hello() {
            const data = await this.post({ type: 'hello' });
            if (!data.ok) {
                throw Object.assign(new Error(data.message || 'Unable to join this channel.'), { payload: data });
            }
            this.lastSeq = data.seq || 0;
            this.backoff = 1000;
            this.onHello(data);
            this.onState('connected');
            return data;
        }

        start() {
            this.active = true;
            this.loop();
        }

        stop() {
            this.active = false;
            if (this.timer) {
                window.clearTimeout(this.timer);
                this.timer = null;
            }
        }

        async loop() {
            if (!this.active) {
                return;
            }

            try {
                const data = await this.post({ type: 'poll', lastSeq: this.lastSeq });
                this.onState('connected');
                this.backoff = 1000;
                if (data.state) {
                    this.onEvent({ type: 'state', state: data.state });
                }
                (data.events || []).forEach((event) => {
                    this.lastSeq = Math.max(this.lastSeq, event.seq || 0);
                    this.onEvent(event);
                });
                if (typeof data.seq === 'number') {
                    this.lastSeq = Math.max(this.lastSeq, data.seq);
                }
                this.timer = window.setTimeout(() => this.loop(), this.pollIntervalMs);
            } catch (error) {
                this.onState('disconnected');
                this.onState('reconnecting');
                this.timer = window.setTimeout(async () => {
                    try {
                        await this.hello();
                    } catch (helloError) {
                        this.onError(helloError);
                    }
                    this.loop();
                }, this.backoff);
                this.backoff = Math.min(this.backoff * 2, 8000);
            }
        }

        pttRequest() {
            return this.post({ type: 'ptt_request' });
        }

        pttRelease() {
            return this.post({ type: 'ptt_release' });
        }

        sendSignal(to, payload) {
            return this.post({ type: 'signal', to: to, payload: payload });
        }

        async leave() {
            this.stop();
            try {
                await this.post({ type: 'leave' });
            } catch (error) {
                /* Best-effort leave. */
            }
        }

        beaconLeave() {
            const body = new Blob([JSON.stringify({
                type: 'leave',
                token: this.token
            })], { type: 'application/json' });
            navigator.sendBeacon(this.url, body);
        }
    }

    window.WalkieSignaling = SignalingClient;
})();
