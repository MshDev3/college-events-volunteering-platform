<?php
/** @var \App\Core\Template $this  @var int $status  @var string $message  @var string|null $debug */
$this->layout('layouts/app', ['title' => (string) $status]);
$icons = [403 => 'bi-shield-lock', 404 => 'bi-signpost-split', 419 => 'bi-hourglass-bottom', 429 => 'bi-speedometer2'];
?>
<section class="section">
    <div class="container">
        <div class="panel mx-auto text-center p-4 p-md-5 narrow">
            <i class="bi <?= e($icons[$status] ?? 'bi-exclamation-triangle') ?> display-5 text-primary d-block mb-3" aria-hidden="true"></i>
            <p class="text-muted mb-1"><?= e(t('common.errors.code', ['code' => $status])) ?></p>
            <h1 class="h3 mb-3"><?= e($message) ?></h1>
            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= e(t('common.actions.back_home')) ?></a>
                <?php if (in_array($status, [401, 403], true) && auth_user() === null): ?>
                    <a class="btn btn-outline-primary" href="<?= e(url('/login')) ?>"><?= e(t('common.nav.login')) ?></a>
                <?php endif; ?>
            </div>
            <?php if (!empty($debug)): ?>
                <pre class="text-start small bg-light border rounded p-3 mt-4 mb-0 overflow-auto" dir="ltr"><?= e($debug) ?></pre>
            <?php endif; ?>
        </div>
    </div>
</section>
