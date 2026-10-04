<?php
$session = app(\App\Core\Session::class);
$tones = ['success' => ['success', 'bi-check-circle'], 'error' => ['danger', 'bi-exclamation-octagon'], 'info' => ['info', 'bi-info-circle'], 'warning' => ['warning', 'bi-exclamation-triangle']];
$messages = [];
foreach ($tones as $key => [$tone, $icon]) {
    $text = $session->getFlash($key);
    if (is_string($text) && $text !== '') {
        $messages[] = [$tone, $icon, $text];
    }
}
if ($messages === []) {
    return;
}
?>
<div class="flash-stack" aria-live="polite">
    <?php foreach ($messages as [$tone, $icon, $text]): ?>
        <div class="alert alert-<?= e($tone) ?> alert-dismissible fade show shadow-sm d-flex gap-2" role="<?= $tone === 'danger' ? 'alert' : 'status' ?>">
            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
            <div><?= e($text) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= e(t('common.actions.close')) ?>"></button>
        </div>
    <?php endforeach; ?>
</div>
