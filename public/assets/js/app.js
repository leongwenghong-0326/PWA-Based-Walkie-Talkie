(function () {
    'use strict';

    const config = window.WALKIE_CONFIG;
    if (!config) {
        return;
    }

    const statusEl = document.getElementById('connection-status');
    const statusText = document.getElementById('connection-status-text');
    const liveBadge = document.getElementById('live-badge');
    const speakerText = document.getElementById('speaker-text');
    const talkTimer = document.getElementById('talk-timer');
    const pttButton = document.getElementById('ptt-button');
    const pttLabel = document.getElementById('ptt-label');
    const micStatus = document.getElementById('mic-status');
    const userList = document.getElementById('user-list');
    const userCount = document.getElementById('user-count');
    const linkCount = document.getElementById('link-count');
    const feedback = document.getElementById('feedback');
    const leaveButton = document.getElementById('leave-button');
    const meterCanvas = document.getElementById('audio-meter');

    const peers = new Map();
    let speaker = null;
    let transmitting = false;
    let wantTalk = false;
    let micReady = false;
    let micDenied = false;
    let countdownTimer = null;
    let busyTimer = null;

    const meter = new window.WalkieMeter(meterCanvas);
    meter.clear();

    const rtc = new window.WalkieRtc({
        iceServers: config.iceServers,
        sendSignal: function (to, payload) {
            return signaling.sendSignal(to, payload).catch(function () {});
        },
        onRemoteStream: function (peerId, stream) {
            meter.watch('remote-' + peerId, stream);
        },
        onLocalStream: function (stream) {
            meter.watch('local', stream);
        },
        onLinksChanged: function () {
            updateLinkCount();
        }
    });
    rtc.setSelfId(config.peerId);

    const signaling = new window.WalkieSignaling({
        signalUrl: config.signalUrl,
        leaveUrl: config.leaveUrl,
        token: config.token,
        csrf: config.csrf,
        pollIntervalMs: config.pollIntervalMs,
        onState: setStatus,
        onHello: function (data) {
            applyState(data.state || {});
            // Peers are connected after mic setup in the hello().then handler.
        },
        onEvent: handleEvent,
        onError: function (error) {
            if (error && (error.status === 401 || (error.payload && error.payload.error === 'expired'))) {
                window.location.href = joinUrl('expired');
                return;
            }
            if (error && error.payload && error.payload.error === 'room_full') {
                window.location.href = joinUrl('full');
            }
        }
    });

    function joinUrl(code) {
        const leave = config.leaveUrl || '';
        return leave.replace(/leave\/?$/, '') + '?error=' + encodeURIComponent(code);
    }

    function setStatus(state) {
        if (!statusEl) {
            return;
        }
        const labels = {
            connecting: 'CONNECTING',
            connected: 'CONNECTED',
            disconnected: 'DISCONNECTED',
            reconnecting: 'RECONNECTING'
        };
        statusEl.dataset.state = state;
        statusText.textContent = labels[state] || state.toUpperCase();
        if (state === 'disconnected' || state === 'reconnecting') {
            setFeedback(state === 'reconnecting'
                ? 'Connection lost. Reconnecting...'
                : 'Unable to connect to the communication server.');
        }
        if (state === 'connected') {
            clearFeedbackIf(/reconnect|connect to the communication server/i);
        }
    }

    function setFeedback(message) {
        feedback.textContent = message || '';
    }

    function clearFeedbackIf(pattern) {
        if (pattern.test(feedback.textContent)) {
            feedback.textContent = '';
        }
    }

    function applyState(state) {
        peers.clear();
        (state.peers || []).forEach(function (peer) {
            peers.set(peer.peerId, peer.nickname);
        });
        speaker = state.speaker || null;
        renderUsers();
        renderSpeaker();
        syncMeshConnections(state.peers || []);
    }

    function connectToKnownPeers(list) {
        syncMeshConnections(list || []);
    }

    function syncMeshConnections(list) {
        const ids = [];
        (list || []).forEach(function (peer) {
            if (peer && peer.peerId && peer.peerId !== config.peerId) {
                ids.push(peer.peerId);
            }
        });
        rtc.syncLinks(ids).catch(function () {});
    }

    function handleEvent(event) {
        if (event.type === 'state' && event.state) {
            applyState(event.state);
            return;
        }

        if (event.type === 'peer_joined' && event.peerId !== config.peerId) {
            peers.set(event.peerId, event.nickname);
            renderUsers();
            maybeOffer(event.peerId);
            return;
        }

        if (event.type === 'peer_left') {
            peers.delete(event.peerId);
            rtc.removePeer(event.peerId);
            meter.unwatch('remote-' + event.peerId);
            renderUsers();
            return;
        }

        if (event.type === 'speaker_started') {
            speaker = {
                peerId: event.peerId,
                nickname: event.nickname,
                expiresAt: event.expiresAt
            };
            renderSpeaker();
            renderUsers();
            // Every listener must stay linked to the speaker and unlock audio playback.
            if (event.peerId !== config.peerId) {
                rtc.ensureLinked(event.peerId, true).then(function () {
                    rtc.playAllRemote();
                }).catch(function () {
                    rtc.playAllRemote();
                });
            }
            return;
        }

        if (event.type === 'speaker_stopped') {
            if (transmitting && event.peerId === config.peerId) {
                stopTalk(false, event.reason === 'timeout' ? 'Talk time ended' : '');
            }
            speaker = null;
            renderSpeaker();
            renderUsers();
            if (event.reason === 'timeout' && event.peerId === config.peerId) {
                setFeedback('Talk time ended');
            }
            return;
        }

        if (event.type === 'signal' && event.payload) {
            rtc.handleSignal(event.from, event.payload);
        }
    }

    function updateLinkCount() {
        if (!linkCount) {
            return;
        }
        const fromRoster = Array.from(peers.keys()).filter(function (id) {
            return id && id !== config.peerId;
        });
        const fromRtc = rtc.getPeerIds().filter(function (id) {
            return id && id !== config.peerId;
        });
        const remoteIds = Array.from(new Set(fromRoster.concat(fromRtc)));
        const expected = Math.max(fromRoster.length, remoteIds.length);
        const healthy = rtc.healthyCount(remoteIds);
        linkCount.textContent = 'LINKS ' + healthy + '/' + expected;
        if (expected > 0 && healthy < expected) {
            linkCount.style.color = '#fde68a';
        } else if (expected > 0 && healthy >= expected) {
            linkCount.style.color = '#86efac';
        } else {
            linkCount.style.color = '';
        }
    }

    function maybeOffer(peerId) {
        // Non-offerer still creates the PC; offerer starts SDP. forceOffer helps recovery.
        const force = config.peerId > peerId;
        rtc.ensureLinked(peerId, force).catch(function () {});
    }

    function renderUsers() {
        const items = Array.from(peers.entries());
        userCount.textContent = items.length + (items.length === 1 ? ' USER' : ' USERS');
        userList.innerHTML = '';
        items.forEach(function ([peerId, nickname]) {
            const speaking = speaker && speaker.peerId === peerId;
            const li = document.createElement('li');
            const name = document.createElement('span');
            const role = document.createElement('span');
            name.innerHTML = '<span class="wt-user-dot" aria-hidden="true"></span>' + escapeHtml(nickname);
            role.className = 'wt-user-role' + (speaking ? ' is-speaking' : '');
            role.textContent = speaking ? 'SPEAKING' : 'LISTENING';
            li.appendChild(name);
            li.appendChild(role);
            userList.appendChild(li);
        });
        updateLinkCount();
    }

    function renderSpeaker() {
        pttButton.classList.toggle('is-busy', !!(speaker && speaker.peerId !== config.peerId && !transmitting));
        if (transmitting) {
            liveBadge.classList.add('is-live');
            liveBadge.innerHTML = '<span class="wt-live-dot"></span> LIVE';
            speakerText.textContent = 'You are transmitting';
            pttLabel.textContent = 'TRANSMITTING';
            return;
        }
        if (speaker) {
            liveBadge.classList.add('is-live');
            liveBadge.innerHTML = '<span class="wt-live-dot"></span> LIVE';
            speakerText.textContent = speaker.nickname + ' is talking';
            pttLabel.textContent = 'HOLD TO TALK';
            talkTimer.hidden = true;
            return;
        }
        liveBadge.classList.remove('is-live');
        liveBadge.innerHTML = '<span class="wt-live-dot"></span> Waiting';
        speakerText.textContent = 'Waiting for a speaker';
        pttLabel.textContent = 'HOLD TO TALK';
        talkTimer.hidden = true;
    }

    async function tryMic() {
        if (micReady || micDenied) {
            return micReady;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            micDenied = true;
            micStatus.textContent = 'Microphone unavailable';
            setFeedback('Microphone permission is required to talk.');
            return false;
        }
        try {
            await rtc.prepareMicrophone();
            micReady = true;
            micStatus.textContent = 'Microphone = OFF';
            return true;
        } catch (error) {
            micDenied = true;
            micStatus.textContent = 'Microphone permission denied';
            setFeedback('Microphone permission is required to talk.');
            return false;
        }
    }

    async function startTalk() {
        if (transmitting) {
            return;
        }
        wantTalk = true;
        const allowed = await tryMic();
        if (!allowed || !wantTalk) {
            return;
        }

        try {
            const result = await signaling.pttRequest();
            if (!wantTalk) {
                if (result.granted) {
                    signaling.pttRelease().catch(function () {});
                }
                return;
            }
            if (!result.granted) {
                pttButton.classList.add('is-busy');
                setFeedback(result.message ? ('CHANNEL BUSY — ' + result.message) : 'CHANNEL BUSY');
                window.clearTimeout(busyTimer);
                busyTimer = window.setTimeout(function () {
                    pttButton.classList.remove('is-busy');
                    if (!transmitting) {
                        setFeedback('');
                    }
                }, 1800);
                return;
            }

            transmitting = true;
            const targets = Array.from(peers.keys()).filter(function (id) {
                return id !== config.peerId;
            });
            const linked = await rtc.broadcastToAll(targets);
            micStatus.textContent = 'Microphone = ON';
            setFeedback('You are live to ' + linked + ' user(s). Hold to keep talking.');
            startCountdown(result.timeoutMs || config.floorTimeoutMs || 30000);
            renderSpeaker();
            updateLinkCount();
            rtc.playAllRemote();
            // Give ICE a moment, then refresh link status.
            window.setTimeout(updateLinkCount, 1200);
        } catch (error) {
            setFeedback('Unable to connect to the communication server.');
        }
    }

    function startCountdown(timeoutMs) {
        window.clearInterval(countdownTimer);
        const started = Date.now();
        talkTimer.hidden = false;
        const tick = function () {
            const remaining = Math.max(0, Math.ceil((timeoutMs - (Date.now() - started)) / 1000));
            talkTimer.textContent = remaining > 0
                ? ('You have ' + remaining + ' seconds to talk.')
                : 'Talk time ended';
            if (remaining <= 0) {
                window.clearInterval(countdownTimer);
                stopTalk(true, 'Talk time ended');
            }
        };
        tick();
        countdownTimer = window.setInterval(tick, 250);
    }

    function stopTalk(notifyServer, message) {
        wantTalk = false;
        window.clearInterval(countdownTimer);
        talkTimer.hidden = true;
        if (!transmitting) {
            if (message) {
                setFeedback(message);
            }
            return;
        }
        transmitting = false;
        rtc.setTransmitting(false);
        micStatus.textContent = micReady ? 'Microphone = OFF' : micStatus.textContent;
        if (notifyServer) {
            signaling.pttRelease().catch(function () {});
        }
        if (message) {
            setFeedback(message);
        } else {
            setFeedback('');
        }
        renderSpeaker();
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function leaveChannel(event) {
        if (event) {
            event.preventDefault();
        }
        stopTalk(true);
        rtc.closeAll();
        await signaling.leave();
        window.location.href = config.leaveUrl;
    }

    new window.WalkiePtt(pttButton, {
        onStart: startTalk,
        onStop: function () { stopTalk(true); }
    });

    leaveButton.addEventListener('click', leaveChannel);

    window.addEventListener('pagehide', function () {
        signaling.beaconLeave();
        rtc.closeAll();
    });

    document.addEventListener('click', function unlock() {
        meter.ensureContext().catch(function () {});
        document.removeEventListener('click', unlock);
    });

    setStatus('connecting');
    signaling.hello().then(async function (data) {
        await tryMic();
        connectToKnownPeers(data.state && data.state.peers ? data.state.peers : []);
        signaling.start();

        // Keep full-mesh links alive for every user up to SIGNAL_MAX_PEERS.
        // Faster refresh so phones update LINKS without waiting on ICE events.
        window.setInterval(function () {
            syncMeshConnections(Array.from(peers.entries()).map(function (entry) {
                return { peerId: entry[0], nickname: entry[1] };
            }));
            updateLinkCount();
            if (speaker && speaker.peerId !== config.peerId) {
                rtc.playAllRemote();
            }
        }, 1000);
    }).catch(function (error) {
        if (error && error.payload && error.payload.error === 'room_full') {
            window.location.href = joinUrl('full');
            return;
        }
        if (error && (error.status === 401 || (error.payload && error.payload.error === 'expired'))) {
            window.location.href = joinUrl('expired');
            return;
        }
        setStatus('disconnected');
        setFeedback((error && error.message) || 'Unable to connect to the communication server.');
    });
})();
