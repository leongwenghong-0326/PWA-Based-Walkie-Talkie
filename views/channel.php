<div class="wt-channel">
    <header class="wt-channel-header">
        <div class="wt-header-brand">
            <span class="wt-logo wt-logo-sm" aria-hidden="true"><i class="fa-solid fa-walkie-talkie"></i></span>
            <div>
                <p class="wt-kicker mb-0">WALKIE TALKIE</p>
                <p class="wt-channel-name mb-0" id="channel-name"># <?= e($channelDisplay) ?></p>
            </div>
        </div>
        <div class="wt-status" id="connection-status" data-state="connecting" role="status" aria-live="polite">
            <span class="wt-status-dot" aria-hidden="true"></span>
            <span id="connection-status-text">CONNECTING</span>
        </div>
    </header>

    <main class="wt-channel-main">
        <section class="wt-speaker-card" aria-live="polite">
            <p class="wt-live" id="live-badge"><span class="wt-live-dot"></span> Waiting</p>
            <h1 class="wt-speaker-text" id="speaker-text">Waiting for a speaker</h1>
            <p class="wt-timer" id="talk-timer" hidden></p>
        </section>

        <section class="wt-ptt-wrap">
            <button type="button" class="wt-ptt" id="ptt-button"
                    aria-label="Hold to talk"
                    aria-pressed="false">
                <span class="wt-ptt-icon" aria-hidden="true"><i class="fa-solid fa-microphone"></i></span>
                <span class="wt-ptt-label" id="ptt-label">HOLD TO TALK</span>
            </button>
            <p class="wt-hint text-center mt-3 mb-0">Hold the button or press Spacebar to talk. Only one person can speak at a time.</p>
        </section>

        <section class="wt-meter-card" aria-label="Audio level">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="wt-section-label"><i class="fa-solid fa-volume-high me-2"></i>Audio level</span>
                <span class="wt-mic-status" id="mic-status">Microphone = OFF</span>
            </div>
            <canvas id="audio-meter" class="wt-meter" width="640" height="72" aria-hidden="true"></canvas>
        </section>

        <section class="wt-users-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="wt-section-label mb-0"><i class="fa-solid fa-users me-2"></i>On this channel</h2>
                <span class="wt-user-count" id="user-count">0 USERS</span>
                <span class="wt-user-count" id="link-count" title="Audio links ready">LINKS 0/0</span>
            </div>
            <ul class="wt-user-list" id="user-list"></ul>
        </section>

        <p class="wt-feedback" id="feedback" role="status" aria-live="assertive"></p>

        <a class="btn wt-leave-btn" id="leave-button" href="<?= e(url('leave')) ?>">
            <i class="fa-solid fa-right-from-bracket me-2"></i>Leave Channel
        </a>
    </main>
</div>
