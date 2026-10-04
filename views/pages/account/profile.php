<?php
/** @var \App\Core\Template $this  @var \App\Domain\User $user  @var array<int,string> $departments  @var array{new_email:string, expires_at:string}|null $pendingEmail */
$this->layout('layouts/app', ['title' => t('profile.title')]);
?>
<?= $this->insert('partials/page-head', ['heading' => t('profile.title'), 'subheading' => t('profile.subtitle')]) ?>
<?php if ($user->isStudent()): ?><?= $this->insert('partials/student-nav') ?><?php endif; ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="panel">
                    <div class="panel-head"><h2><?= e(t('profile.details')) ?></h2></div>
                    <div class="panel-body">
                        <?php if (($pendingEmail ?? null) !== null): ?>
                            <div class="alert alert-info" role="status">
                                <div class="d-flex gap-2">
                                    <i class="bi bi-envelope-exclamation" aria-hidden="true"></i>
                                    <div><?= e(t('profile.email_change.pending', [
                                        'email' => $pendingEmail['new_email'],
                                        'time' => dates()->dateTime($pendingEmail['expires_at']),
                                    ])) ?></div>
                                </div>
                                <form method="post" action="<?= e(url('/profile/email/cancel')) ?>" class="mt-2">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= e(t('profile.email_change.cancel')) ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/profile')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?= $this->component('field', ['name' => 'full_name', 'label' => t('auth.fields.full_name'), 'value' => old('full_name', $user->fullName), 'required' => true, 'attrs' => ['autocomplete' => 'name', 'maxlength' => 120]]) ?>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <?php if ($user->isStudent() && $user->studentId === null): ?>
                                        <?= $this->component('field', ['name' => 'student_id', 'label' => t('auth.fields.student_id'), 'value' => old('student_id'), 'required' => true, 'help' => t('profile.help.student_id_once'), 'attrs' => ['inputmode' => 'numeric', 'dir' => 'ltr']]) ?>
                                    <?php else: ?>
                                        <div class="mb-3">
                                            <label class="form-label" for="sid"><?= e(t('auth.fields.student_id')) ?></label>
                                            <input class="form-control" id="sid" value="<?= e($user->studentId ?? '—') ?>" readonly dir="ltr" aria-describedby="sid-help">
                                            <div class="form-text" id="sid-help"><?= e(t('profile.help.student_id_locked')) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-sm-6">
                                    <?= $this->component('field', ['name' => 'phone', 'type' => 'tel', 'label' => t('auth.fields.phone'), 'value' => old('phone', $user->phone ?? ''), 'required' => $user->isStudent(), 'attrs' => ['autocomplete' => 'tel', 'dir' => 'ltr']]) ?>
                                </div>
                            </div>
                            <?= $this->component('field', ['name' => 'email', 'type' => 'email', 'label' => t('auth.fields.email'), 'value' => old('email', $user->email), 'required' => true, 'help' => t('profile.help.email'), 'attrs' => ['autocomplete' => 'email', 'dir' => 'ltr']]) ?>
                            <?= $this->component('field', ['name' => 'email_password', 'type' => 'password', 'label' => t('profile.fields.current_password_for_email'), 'attrs' => ['autocomplete' => 'current-password']]) ?>
                            <div class="row g-3">
                                <div class="col-sm-6"><?= $this->component('field', ['name' => 'department_id', 'type' => 'select', 'label' => t('auth.fields.department'), 'value' => old('department_id', $user->departmentId ?? ''), 'options' => $departments, 'placeholderOption' => t('common.forms.optional_choose')]) ?></div>
                                <div class="col-sm-6"><?= $this->component('field', ['name' => 'preferred_locale', 'type' => 'select', 'label' => t('profile.fields.language'), 'value' => old('preferred_locale', $user->preferredLocale), 'options' => ['ar' => 'العربية', 'en' => 'English'], 'required' => true]) ?></div>
                            </div>
                            <button class="btn btn-primary" type="submit"><?= e(t('profile.save')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="panel">
                    <div class="panel-head"><h2><?= e(t('profile.password_title')) ?></h2></div>
                    <div class="panel-body">
                        <p class="small text-muted"><?= e(t('profile.password_help')) ?></p>
                        <form method="post" action="<?= e(url('/profile/password')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?= $this->component('field', ['name' => 'current_password', 'id' => 'f-pw-current', 'type' => 'password', 'label' => t('profile.fields.current_password'), 'required' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
                            <?= $this->component('field', ['name' => 'password', 'type' => 'password', 'label' => t('auth.fields.new_password'), 'required' => true, 'help' => t('auth.help.password'), 'attrs' => ['autocomplete' => 'new-password', 'minlength' => 8]]) ?>
                            <?= $this->component('field', ['name' => 'password_confirmation', 'type' => 'password', 'label' => t('auth.fields.password_confirmation'), 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
                            <button class="btn btn-outline-primary" type="submit"><?= e(t('profile.change_password')) ?></button>
                        </form>
                    </div>
                </div>
                <p class="small text-muted mt-3"><?= e(t('profile.member_since', ['date' => dates()->date($user->createdAt)])) ?> — <?= e(t($user->role->labelKey())) ?></p>
            </div>
        </div>
    </div>
</section>
