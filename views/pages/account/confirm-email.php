<?php
/** @var \App\Core\Template $this  @var string $token  @var string|null $newEmail  @var string $currentEmail */
$this->layout('layouts/app', ['title' => t('profile.email_change.confirm_title')]);
?>
<?= $this->insert('partials/page-head', ['heading' => t('profile.email_change.confirm_title')]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="panel">
                    <div class="panel-body">
                        <?php if ($newEmail === null): ?>
                            <div class="alert alert-warning d-flex gap-2" role="alert">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <div><?= e(t('profile.email_change.invalid')) ?></div>
                            </div>
                            <a class="btn btn-primary" href="<?= e(url('/profile')) ?>"><?= e(t('profile.email_change.back_to_profile')) ?></a>
                        <?php else: ?>
                            <p><?= e(t('profile.email_change.confirm_text')) ?></p>
                            <dl class="row mb-4">
                                <dt class="col-sm-4"><?= e(t('profile.email_change.current')) ?></dt>
                                <dd class="col-sm-8"><span dir="ltr"><?= e($currentEmail) ?></span></dd>
                                <dt class="col-sm-4"><?= e(t('profile.email_change.new')) ?></dt>
                                <dd class="col-sm-8 fw-semibold"><span dir="ltr"><?= e($newEmail) ?></span></dd>
                            </dl>
                            <p class="small text-muted"><?= e(t('profile.email_change.confirm_effect')) ?></p>
                            <form method="post" action="<?= e(url('/profile/email/confirm/' . $token)) ?>" class="d-flex flex-wrap gap-2">
                                <?= csrf_field() ?>
                                <button class="btn btn-primary" type="submit"><?= e(t('profile.email_change.confirm_button')) ?></button>
                                <a class="btn btn-light" href="<?= e(url('/profile')) ?>"><?= e(t('profile.email_change.back_to_profile')) ?></a>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
