<main class="wt-join">
    <div class="container py-5">
        <section class="wt-card p-5 text-center">
            <h1 class="h3">Unable to load the communication server</h1>
            <p class="wt-hint">Please try again in a moment. If the problem continues, ask your instructor to check the server logs.</p>
            <?php if (!empty($detail)): ?>
                <p class="small text-warning mt-3"><?= e($detail) ?></p>
            <?php endif; ?>
            <a class="btn wt-join-btn mt-3" href="<?= e(url()) ?>">Back to Walkie Talkie</a>
        </section>
    </div>
</main>
