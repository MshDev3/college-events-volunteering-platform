<?php
/** @var array{address:string, phones:list<string>, emails:list<string>}|null $contactInfo  shared by ShareLayoutData */
$contactInfo ??= ['address' => '', 'phones' => [], 'emails' => []];
?>
<footer class="site-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="brand mb-3" href="<?= e(url('/')) ?>">
                    <img src="<?= e(asset('assets/img/brand/tvtc.svg')) ?>" alt="" width="40" height="40">
                    <span class="brand-name"><?= e(t('common.site_name')) ?><span class="brand-sub"><?= e(t('common.site_tagline')) ?></span></span>
                </a>
                <p class="mt-3 mb-0 measure"><?= e(t('common.footer.about')) ?></p>
            </div>
            <div class="col-6 col-lg-2">
                <h2><?= e(t('common.footer.explore')) ?></h2>
                <ul class="list-unstyled d-grid gap-2">
                    <li><a href="<?= e(url('/events')) ?>"><?= e(t('common.nav.events')) ?></a></li>
                    <li><a href="<?= e(url('/volunteering')) ?>"><?= e(t('common.nav.volunteering')) ?></a></li>
                    <li><a href="<?= e(url('/facilities')) ?>"><?= e(t('common.nav.facilities')) ?></a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h2><?= e(t('common.footer.college')) ?></h2>
                <ul class="list-unstyled d-grid gap-2">
                    <li><a href="<?= e(url('/about')) ?>"><?= e(t('common.nav.about')) ?></a></li>
                    <li><a href="<?= e(url('/contact')) ?>"><?= e(t('common.nav.contact')) ?></a></li>
                    <li><a href="<?= e(url('/student/feedback/new')) ?>"><?= e(t('common.footer.feedback')) ?></a></li>
                </ul>
            </div>
            <div class="col-lg-4">
                <h2><?= e(t('common.footer.reach_us')) ?></h2>
                <ul class="list-unstyled d-grid gap-2">
                    <?php if ($contactInfo['address'] !== ''): ?><li><i class="bi bi-geo-alt me-2" aria-hidden="true"></i><?= e($contactInfo['address']) ?></li><?php endif; ?>
                    <?php foreach (array_slice($contactInfo['phones'], 0, 1) as $phone): ?>
                        <li><i class="bi bi-telephone me-2" aria-hidden="true"></i><a href="tel:<?= e($phone) ?>" dir="ltr"><?= e($phone) ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach (array_slice($contactInfo['emails'], 0, 1) as $email): ?>
                        <li><i class="bi bi-envelope me-2" aria-hidden="true"></i><a href="<?= e(mailto_href((string) $email)) ?>" dir="ltr"><?= e($email) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="legal d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?= e(date('Y')) ?> <?= e(t('common.site_name')) ?>. <?= e(t('common.footer.rights')) ?></span>
            <?= $this->insert('partials/lang-switch') ?>
        </div>
    </div>
</footer>
