<?php
/** @var \App\Core\Template $this  @var string $token  @var bool $valid */
$this->layout('layouts/auth', ['title' => t('auth.reset.title')]);
?>
<h1><?= e(t('auth.reset.title')) ?></h1>
<?php if (!$valid): ?>
    <div class="alert alert-warning d-flex gap-2 mt-3" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div><?= e(t('auth.reset.invalid_link')) ?></div>
    </div>
    <a class="btn btn-primary w-100" href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.reset.request_new')) ?></a>
<?php else: ?>
    <p class="text-muted mb-4"><?= e(t('auth.reset.subtitle')) ?></p>
    <form method="post" action="<?= e(url('/reset-password/' . $token)) ?>" novalidate>
        <?= csrf_field() ?>
        <?= $this->component('field', ['name' => 'password', 'type' => 'password', 'label' => t('auth.fields.new_password'), 'required' => true, 'help' => t('auth.help.password'), 'attrs' => ['autocomplete' => 'new-password', 'minlength' => 8, 'maxlength' => 128, 'autofocus' => true]]) ?>
        <?= $this->component('field', ['name' => 'password_confirmation', 'type' => 'password', 'label' => t('auth.fields.password_confirmation'), 'required' => true, 'attrs' => ['autocomplete' => 'new-password', 'maxlength' => 128]]) ?>
        <button class="btn btn-primary btn-lg w-100" type="submit"><?= e(t('auth.reset.submit')) ?></button>
    </form>
<?php endif; ?>
