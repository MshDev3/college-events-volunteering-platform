<?php
/** @var \App\Core\Template $this  @var string $next  @var string $role */
$this->layout('layouts/auth', ['title' => t('auth.login.title')]);
$selected = (string) old('role', $role);
?>
<h1><?= e(t('auth.login.title')) ?></h1>
<p class="text-muted mb-4"><?= e(t('auth.login.subtitle')) ?></p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>

    <fieldset class="mb-3">
        <legend class="form-label fs-6"><?= e(t('auth.login.account_type')) ?></legend>
        <div class="segmented">
            <input type="radio" name="role" id="role-student" value="STUDENT"<?= $selected !== 'ADMIN' ? ' checked' : '' ?>>
            <label for="role-student"><i class="bi bi-mortarboard" aria-hidden="true"></i><?= e(t('common.roles.STUDENT')) ?></label>
            <input type="radio" name="role" id="role-admin" value="ADMIN"<?= $selected === 'ADMIN' ? ' checked' : '' ?>>
            <label for="role-admin"><i class="bi bi-shield-check" aria-hidden="true"></i><?= e(t('common.roles.ADMIN')) ?></label>
        </div>
        <?php if (error('role')): ?><div class="invalid-feedback d-block" role="alert"><?= e(error('role')) ?></div><?php endif; ?>
    </fieldset>

    <?= $this->component('field', [
        'name' => 'identifier', 'label' => t('auth.fields.identifier'), 'value' => old('identifier'), 'required' => true,
        'attrs' => ['autocomplete' => 'username', 'autofocus' => true, 'dir' => 'auto', 'maxlength' => 190],
    ]) ?>

    <?= $this->component('field', [
        'name' => 'password', 'type' => 'password', 'label' => t('auth.fields.password'), 'required' => true,
        'attrs' => ['autocomplete' => 'current-password', 'maxlength' => 128],
    ]) ?>

    <div class="d-flex justify-content-between align-items-center mb-4 gap-2 flex-wrap">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember"<?= old('remember') ? ' checked' : '' ?> aria-describedby="remember-help">
            <label class="form-check-label" for="remember"><?= e(t('auth.login.remember')) ?></label>
        </div>
        <a class="small" href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.login.forgot')) ?></a>
    </div>
    <p class="form-text mt-n3 mb-4" id="remember-help"><?= e(t('auth.login.remember_help')) ?></p>

    <button class="btn btn-primary btn-lg w-100" type="submit"><?= e(t('auth.login.submit')) ?></button>
</form>

<div class="divider-text my-4"><?= e(t('auth.login.no_account')) ?></div>
<a class="btn btn-outline-primary w-100" href="<?= e(url('/register')) ?>"><?= e(t('auth.login.create_account')) ?></a>
