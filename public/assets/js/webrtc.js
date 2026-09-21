(function () {
    'use strict';

    class MeshAudio {
        constructor(options) {
            this.iceServers = options.iceServers || [];
            this.sendSignal = options.sendSignal;
            this.onRemoteStream = options.onRemoteStream || function () {};
            this.onLocalStream = options.onLocalStream || function () {};
            this.onLinksChanged = options.onLinksChanged || function () {};
            this.peers = new Map();
            this.localStream = null;
            this.audioElements = new Map();
            this.makingOffer = new Map();
            this._selfId = '';
        }

        setSelfId(peerId) {
            this._selfId = String(peerId || '');
        }

        hasPeer(peerId) {
            return this.peers.has(peerId);
        }

        getPeerIds() {
            return Array.from(this.peers.keys());
        }

        isOfferer(peerId) {
            return this._selfId > String(peerId);
        }

        isLinkHealthy(peerId) {
            const rec = this.peers.get(peerId);
            if (!rec) {
                return false;
            }
            const ice = rec.pc.iceConnectionState;
            const state = rec.pc.connectionState;
            return ice === 'connected' || ice === 'completed' || state === 'connected';
        }

        /** True only while ICE is actively negotiating (not before the first offer). */
        isLinkBusy(peerId) {
            const rec = this.peers.get(peerId);
            if (!rec) {
                return false;
            }
            const ice = rec.pc.iceConnectionState;
            const state = rec.pc.connectionState;
            return ice === 'checking' || state === 'connecting';
        }

        healthyCount(remoteIds) {
            const ids = remoteIds || this.getPeerIds();
            let n = 0;
            ids.forEach((id) => {
                if (id !== this._selfId && this.isLinkHealthy(id)) {
                    n += 1;
                }
            });
            return n;
        }

        notifyLinks() {
            try {
                this.onLinksChanged();
            } catch (e) { /* ignore */ }
        }

        async prepareMicrophone() {
            if (this.localStream) {
                return this.localStream;
            }

            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                },
                video: false
            });

            this.localStream.getAudioTracks().forEach(function (track) {
                track.enabled = false;
            });
            this.onLocalStream(this.localStream);

            for (const peerId of this.getPeerIds()) {
                await this.bindMic(peerId);
            }
            return this.localStream;
        }

        setTransmitting(enabled) {
            if (!this.localStream) {
                return;
            }
            this.localStream.getAudioTracks().forEach(function (track) {
                track.enabled = !!enabled;
            });
        }

        playAllRemote() {
            this.audioElements.forEach(function (audio) {
                audio.muted = false;
                audio.volume = 1;
                const p = audio.play();
                if (p && typeof p.catch === 'function') {
                    p.catch(function () {});
                }
            });
        }

        playRemote(peerId, stream) {
            let audio = this.audioElements.get(peerId);
            if (!audio) {
                audio = document.createElement('audio');
                audio.autoplay = true;
                audio.playsInline = true;
                audio.setAttribute('playsinline', 'true');
                audio.muted = false;
                audio.volume = 1;
                document.body.appendChild(audio);
                this.audioElements.set(peerId, audio);
            }
            if (audio.srcObject !== stream) {
                audio.srcObject = stream;
            }
            const p = audio.play();
            if (p && typeof p.catch === 'function') {
                p.catch(function () {});
            }
        }

        async syncLinks(peerIds) {
            const wanted = {};
            const list = (peerIds || []).filter((id) => id && id !== this._selfId);

            for (let i = 0; i < list.length; i++) {
                wanted[list[i]] = true;
                await this.ensureLinked(list[i], false);
            }

            this.getPeerIds().forEach((id) => {
                if (!wanted[id]) {
                    this.removePeer(id);
                }
            });

            this.notifyLinks();
        }

        /**
         * @param {string} peerId
         * @param {boolean} forceOffer  When true, this side may offer even if it is not the primary offerer.
         */
        async ensureLinked(peerId, forceOffer) {
            peerId = String(peerId || '');
            if (!peerId || peerId === this._selfId) {
                return null;
            }

            const existing = this.peers.get(peerId);
            if (existing) {
                const ice = existing.pc.iceConnectionState;
                const state = existing.pc.connectionState;
                if (ice === 'failed' || state === 'failed' || state === 'closed' || state === 'disconnected') {
                    this.removePeer(peerId);
                }
            }

            await this.ensurePeer(peerId);
            await this.bindMic(peerId);

            const shouldStartOffer = forceOffer || this.isOfferer(peerId);
            if (shouldStartOffer && !this.isLinkHealthy(peerId) && !this.isLinkBusy(peerId)) {
                await this.createOffer(peerId);
            }

            this.notifyLinks();
            return this.peers.get(peerId);
        }

        async broadcastToAll(peerIds) {
            if (!this.localStream) {
                await this.prepareMicrophone();
            }

            const targets = (peerIds || []).filter((id) => id && id !== this._selfId);

            for (let i = 0; i < targets.length; i++) {
                const peerId = targets[i];
                await this.ensurePeer(peerId);
                await this.bindMic(peerId);
                if (!this.isLinkHealthy(peerId)) {
                    await this.createOffer(peerId);
                }
            }

            this.setTransmitting(true);
            this.playAllRemote();
            this.notifyLinks();
            return targets.length;
        }

        async bindMic(peerId) {
            const rec = this.peers.get(peerId);
            if (!rec || !this.localStream) {
                return;
            }

            const track = this.localStream.getAudioTracks()[0];
            if (!track) {
                return;
            }

            const audioSender = rec.pc.getSenders().find(function (s) {
                return s.track && s.track.kind === 'audio';
            }) || rec.pc.getSenders().find(function (s) {
                return !s.track;
            });

            if (audioSender) {
                if (audioSender.track !== track) {
                    await audioSender.replaceTrack(track);
                }
            } else {
                rec.pc.addTrack(track, this.localStream);
            }
        }

        async ensurePeer(peerId) {
            peerId = String(peerId || '');
            if (this.peers.has(peerId)) {
                return this.peers.get(peerId);
            }

            const pc = new RTCPeerConnection({ iceServers: this.iceServers });
            const rec = {
                pc: pc,
                polite: !this.isOfferer(peerId),
                pendingCandidates: []
            };
            this.peers.set(peerId, rec);

            if (this.localStream) {
                this.localStream.getTracks().forEach((track) => {
                    pc.addTrack(track, this.localStream);
                });
            } else {
                pc.addTransceiver('audio', { direction: 'sendrecv' });
            }

            pc.onicecandidate = (event) => {
                if (event.candidate) {
                    this.sendSignal(peerId, { kind: 'ice', candidate: event.candidate });
                }
            };

            pc.ontrack = (event) => {
                const stream = event.streams[0] || new MediaStream([event.track]);
                this.playRemote(peerId, stream);
                this.onRemoteStream(peerId, stream);
                this.playAllRemote();
                this.notifyLinks();
            };

            pc.oniceconnectionstatechange = () => {
                this.notifyLinks();
                if (pc.iceConnectionState === 'failed') {
                    try {
                        pc.restartIce();
                    } catch (e) { /* ignore */ }
                }
            };

            pc.onconnectionstatechange = () => {
                this.notifyLinks();
                if (pc.connectionState === 'failed') {
                    try {
                        pc.restartIce();
                    } catch (e) { /* ignore */ }
                }
            };

            pc.onnegotiationneeded = () => {
                if (this.isOfferer(peerId) && !this.makingOffer.get(peerId)) {
                    this.createOffer(peerId).catch(function () {});
                }
            };

            this.notifyLinks();
            return rec;
        }

        async createOffer(peerId) {
            const rec = this.peers.get(peerId) || await this.ensurePeer(peerId);
            if (this.makingOffer.get(peerId)) {
                return;
            }
            if (rec.pc.signalingState !== 'stable') {
                return;
            }

            try {
                this.makingOffer.set(peerId, true);
                await this.bindMic(peerId);
                const offer = await rec.pc.createOffer();
                await rec.pc.setLocalDescription(offer);
                await Promise.resolve(this.sendSignal(peerId, {
                    kind: 'offer',
                    sdp: {
                        type: rec.pc.localDescription.type,
                        sdp: rec.pc.localDescription.sdp
                    }
                }));
            } catch (e) {
                /* glare / closed */
            } finally {
                this.makingOffer.set(peerId, false);
                this.notifyLinks();
            }
        }

        async flushCandidates(peerId) {
            const rec = this.peers.get(peerId);
            if (!rec || !rec.pc.remoteDescription) {
                return;
            }
            const queued = rec.pendingCandidates.splice(0, rec.pendingCandidates.length);
            for (let i = 0; i < queued.length; i++) {
                try {
                    await rec.pc.addIceCandidate(queued[i]);
                } catch (e) { /* ignore */ }
            }
        }

        async handleSignal(from, payload) {
            if (!payload || !payload.kind) {
                return;
            }
            from = String(from || '');

            const rec = await this.ensurePeer(from);

            if (payload.kind === 'offer' && payload.sdp) {
                const offerCollision = this.makingOffer.get(from) || rec.pc.signalingState !== 'stable';

                if (offerCollision) {
                    if (!rec.polite) {
                        return;
                    }
                    try {
                        if (rec.pc.signalingState !== 'stable') {
                            await rec.pc.setLocalDescription({ type: 'rollback' });
                        }
                    } catch (e) { /* continue */ }
                }

                try {
                    if (!this.localStream) {
                        try {
                            await this.prepareMicrophone();
                        } catch (e) { /* listen-only */ }
                    }

                    await rec.pc.setRemoteDescription(payload.sdp);
                    await this.flushCandidates(from);
                    await this.bindMic(from);

                    const answer = await rec.pc.createAnswer();
                    await rec.pc.setLocalDescription(answer);
                    await Promise.resolve(this.sendSignal(from, {
                        kind: 'answer',
                        sdp: {
                            type: rec.pc.localDescription.type,
                            sdp: rec.pc.localDescription.sdp
                        }
                    }));
                    this.playAllRemote();
                } catch (e) {
                    /* ignore transient errors */
                }
                this.notifyLinks();
                return;
            }

            if (payload.kind === 'answer' && payload.sdp) {
                try {
                    if (rec.pc.signalingState === 'have-local-offer') {
                        await rec.pc.setRemoteDescription(payload.sdp);
                        await this.flushCandidates(from);
                    }
                } catch (e) { /* ignore */ }
                this.notifyLinks();
                return;
            }

            if (payload.kind === 'ice' && payload.candidate) {
                try {
                    if (!rec.pc.remoteDescription) {
                        rec.pendingCandidates.push(payload.candidate);
                    } else {
                        await rec.pc.addIceCandidate(payload.candidate);
                    }
                } catch (e) { /* ignore */ }
            }
        }

        removePeer(peerId) {
            const rec = this.peers.get(peerId);
            if (rec) {
                try { rec.pc.close(); } catch (e) { /* ignore */ }
                this.peers.delete(peerId);
            }
            this.makingOffer.delete(peerId);
            const audio = this.audioElements.get(peerId);
            if (audio) {
                audio.srcObject = null;
                audio.remove();
                this.audioElements.delete(peerId);
            }
            this.notifyLinks();
        }

        closeAll() {
            this.setTransmitting(false);
            this.getPeerIds().forEach((id) => this.removePeer(id));
            if (this.localStream) {
                this.localStream.getTracks().forEach(function (t) { t.stop(); });
                this.localStream = null;
            }
        }
    }

    window.WalkieRtc = MeshAudio;
})();
