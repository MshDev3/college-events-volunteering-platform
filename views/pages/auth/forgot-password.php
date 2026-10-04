<?php
/** @var \App\Core\Template $this  @var bool $sent */
$this->layout('layouts/auth', ['title' => t('auth.forgot.title')]);
?>
<h1><?= e(t('auth.forgot.title')) ?></h1>
<?php if ($sent): ?>
    <div class="alert alert-success d-flex gap-2 mt-3" role="status">
        <i class="bi bi-envelope-check" aria-hidden="true"></i>
        <div><?= e(t('auth.forgot.sent', ['minutes' => (int) config('auth.reset_minutes')])) ?></div>
    </div>
    <?php if (config('app.env') === 'local' && config('mail.driver') === 'log'): ?>
        <p class="small text-muted"><?= e(t('auth.forgot.dev_hint')) ?> <a href="<?= e(url('/_dev/mail')) ?>"><?= e(t('auth.forgot.dev_link')) ?></a></p>
    <?php endif; ?>
    <a class="btn btn-outline-primary w-100 mt-2" href="<?= e(url('/login')) ?>"><?= e(t('auth.forgot.back_to_login')) ?></a>
<?php else: ?>
    <p class="text-muted mb-4"><?= e(t('auth.forgot.subtitle')) ?></p>
    <form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
        <?= csrf_field() ?>
        <?= $this->component('field', ['name' => 'email', 'type' => 'email', 'label' => t('auth.fields.email'), 'value' => old('email'), 'required' => true, 'attrs' => ['autocomplete' => 'email', 'dir' => 'ltr', 'autofocus' => true]]) ?>
        <button class="btn btn-primary btn-lg w-100" type="submit"><?= e(t('auth.forgot.submit')) ?></button>
    </form>
    <p class="text-center mt-4 mb-0"><a href="<?= e(url('/login')) ?>"><?= e(t('auth.forgot.back_to_login')) ?></a></p>
<?php endif; ?>
