<?php
/** @var \App\Core\Template $this  @var array<int,string> $departments */
$this->layout('layouts/auth', ['title' => t('auth.register.title')]);
?>
<h1><?= e(t('auth.register.title')) ?></h1>
<p class="text-muted mb-4"><?= e(t('auth.register.subtitle')) ?></p>

<form method="post" action="<?= e(url('/register')) ?>" novalidate>
    <?= csrf_field() ?>
    <?= $this->component('field', ['name' => 'full_name', 'label' => t('auth.fields.full_name'), 'value' => old('full_name'), 'required' => true, 'attrs' => ['autocomplete' => 'name', 'maxlength' => 120]]) ?>
    <div class="row g-3">
        <div class="col-sm-6">
            <?= $this->component('field', ['name' => 'student_id', 'label' => t('auth.fields.student_id'), 'value' => old('student_id'), 'required' => true, 'help' => t('auth.help.student_id'), 'attrs' => ['inputmode' => 'numeric', 'dir' => 'ltr', 'maxlength' => 12]]) ?>
        </div>
        <div class="col-sm-6">
            <?= $this->component('field', ['name' => 'phone', 'type' => 'tel', 'label' => t('auth.fields.phone'), 'value' => old('phone'), 'required' => true, 'help' => t('auth.help.phone'), 'attrs' => ['autocomplete' => 'tel', 'dir' => 'ltr', 'maxlength' => 20]]) ?>
        </div>
    </div>
    <?= $this->component('field', ['name' => 'email', 'type' => 'email', 'label' => t('auth.fields.email'), 'value' => old('email'), 'required' => true, 'attrs' => ['autocomplete' => 'email', 'dir' => 'ltr', 'maxlength' => 190]]) ?>
    <?= $this->component('field', ['name' => 'department_id', 'type' => 'select', 'label' => t('auth.fields.department'), 'value' => old('department_id'), 'options' => $departments, 'placeholderOption' => t('common.forms.optional_choose')]) ?>
    <div class="row g-3">
        <div class="col-sm-6">
            <?= $this->component('field', ['name' => 'password', 'type' => 'password', 'label' => t('auth.fields.password'), 'required' => true, 'help' => t('auth.help.password'), 'attrs' => ['autocomplete' => 'new-password', 'minlength' => 8, 'maxlength' => 128]]) ?>
        </div>
        <div class="col-sm-6">
            <?= $this->component('field', ['name' => 'password_confirmation', 'type' => 'password', 'label' => t('auth.fields.password_confirmation'), 'required' => true, 'attrs' => ['autocomplete' => 'new-password', 'maxlength' => 128]]) ?>
        </div>
    </div>
    <button class="btn btn-primary btn-lg w-100 mt-2" type="submit"><?= e(t('auth.register.submit')) ?></button>
</form>

<p class="text-center mt-4 mb-0"><?= e(t('auth.register.have_account')) ?> <a href="<?= e(url('/login')) ?>"><?= e(t('auth.register.login')) ?></a></p>
