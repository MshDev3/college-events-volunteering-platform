<?php
/** @var \App\Core\Template $this  @var array{address:string, phones:list<string>, emails:list<string>} $contactInfo */
$this->layout('layouts/app', ['title' => t('contact.title')]);
$user = auth_user();
$contactInfo ??= ['address' => '', 'phones' => [], 'emails' => []];
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('contact.title'),
    'subheading' => t('contact.subtitle'),
    'crumbs' => [[t('contact.title'), null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="panel">
                    <div class="panel-body">
                        <ul class="fact-list">
                            <?php if ($contactInfo['address'] !== ''): ?>
                                <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span><span class="k"><?= e(t('contact.info.address')) ?></span><span class="v"><?= e($contactInfo['address']) ?></span></span></li>
                            <?php endif; ?>
                            <li><i class="bi bi-telephone" aria-hidden="true"></i><span><span class="k"><?= e(t('contact.info.phone')) ?></span>
                                <?php foreach ($contactInfo['phones'] as $phone): ?><span class="v d-block"><a href="tel:<?= e($phone) ?>" dir="ltr"><?= e($phone) ?></a></span><?php endforeach; ?>
                            </span></li>
                            <li><i class="bi bi-envelope" aria-hidden="true"></i><span><span class="k"><?= e(t('contact.info.email')) ?></span>
                                <?php foreach ($contactInfo['emails'] as $email): ?><span class="v d-block"><a href="<?= e(mailto_href((string) $email)) ?>" dir="ltr"><?= e($email) ?></a></span><?php endforeach; ?>
                            </span></li>
                        </ul>
                    </div>
                </div>
                <?php if ($contactInfo['address'] !== ''): ?>
                    <?php /* Shown only when a real address is set in Admin › Settings (CSP: frame-src https://www.google.com). */ ?>
                    <?php $mapUrl = 'https://www.google.com/maps?' . http_build_query(['q' => $contactInfo['address'], 'hl' => locale(), 'output' => 'embed']); ?>
                    <div class="panel mt-3 overflow-hidden">
                        <iframe class="contact-map" src="<?= e($mapUrl) ?>" title="<?= e(t('contact.map_title')) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                    </div>
                <?php endif; ?>
                <?php if ($user !== null && $user->isStudent()): ?>
                    <div class="panel mt-3"><div class="panel-body">
                        <p class="mb-2"><?= e(t('contact.feedback_hint')) ?></p>
                        <a href="<?= e(url('/student/feedback/new')) ?>"><?= e(t('contact.feedback_link')) ?></a>
                    </div></div>
                <?php endif; ?>
            </div>
            <div class="col-lg-8">
                <div class="panel">
                    <div class="panel-head"><h2><?= e(t('contact.form_title')) ?></h2></div>
                    <div class="panel-body">
                        <form method="post" action="<?= e(url('/contact')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <div class="d-none" aria-hidden="true">
                                <label for="website">Website</label>
                                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6"><?= $this->component('field', ['name' => 'name', 'label' => t('contact.fields.name'), 'value' => old('name', $user?->fullName ?? ''), 'required' => true, 'attrs' => ['autocomplete' => 'name', 'maxlength' => 120]]) ?></div>
                                <div class="col-md-6"><?= $this->component('field', ['name' => 'email', 'type' => 'email', 'label' => t('contact.fields.email'), 'value' => old('email', $user?->email ?? ''), 'required' => true, 'attrs' => ['autocomplete' => 'email', 'dir' => 'ltr', 'maxlength' => 190]]) ?></div>
                            </div>
                            <?= $this->component('field', ['name' => 'subject', 'label' => t('contact.fields.subject'), 'value' => old('subject'), 'required' => true, 'attrs' => ['maxlength' => 200]]) ?>
                            <?= $this->component('field', ['name' => 'message', 'type' => 'textarea', 'rows' => 6, 'label' => t('contact.fields.message'), 'value' => old('message'), 'required' => true, 'attrs' => ['maxlength' => 5000]]) ?>
                            <button class="btn btn-primary" type="submit"><?= e(t('contact.submit')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
