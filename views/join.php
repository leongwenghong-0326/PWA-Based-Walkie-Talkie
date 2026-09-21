<main class="wt-join">
    <div class="container py-4 py-md-5">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-xl-7">
                <header class="wt-hero text-center mb-4">
                    <div class="wt-logo" aria-hidden="true">
                        <i class="fa-solid fa-walkie-talkie"></i>
                    </div>
                    <p class="wt-kicker mb-1">WALKIE TALKIE</p>
                    <h1 class="wt-title">Push to Talk</h1>
                    <p class="wt-subtitle mb-0">Instant Voice Communication</p>
                </header>

                <?php if (!empty($error)): ?>
                    <div class="alert wt-alert" role="alert">
                        <i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?>
                    </div>
                <?php endif; ?>

                <section class="wt-card p-4 p-md-5">
                    <form method="post" action="<?= e(url('join')) ?>" novalidate>
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                        <div class="mb-3">
                            <label class="form-label" for="nickname">Nickname</label>
                            <input class="form-control wt-input" type="text" id="nickname" name="nickname"
                                   maxlength="24" required autocomplete="nickname"
                                   placeholder="Enter your nickname"
                                   value="<?= e(is_string($nickname ?? null) ? $nickname : '') ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="channel">Channel</label>
                            <input class="form-control wt-input" type="text" id="channel" name="channel"
                                   maxlength="64" required autocomplete="off"
                                   placeholder="Enter channel name"
                                   value="<?= e(is_string($channel ?? null) ? $channel : '') ?>">
                            <p class="wt-hint mt-2 mb-0">
                                Enter the same channel name as your team members to communicate together.
                            </p>
                        </div>

                        <button class="btn wt-join-btn w-100" type="submit">
                            <i class="fa-solid fa-tower-broadcast me-2"></i>JOIN CHANNEL
                        </button>
                    </form>
                </section>

                <section class="wt-card wt-qr-card mt-4 p-4 text-center">
                    <h2 class="h6 text-uppercase mb-3">Open on your phone</h2>
                    <img class="wt-qr" src="<?= e(url('qr.svg')) ?>?t=<?= e((string) time()) ?>"
                         width="220" height="220" alt="QR code for this application"
                         decoding="sync">
                    <p class="wt-hint mt-3 mb-0">Scan this code to join</p>
                </section>
            </div>
        </div>
    </div>
</main>
