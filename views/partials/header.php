<?php
/** @var \App\Core\Template $this */
/** @var \App\Domain\User|null $currentUser */
$currentUser ??= null;
$unreadNotifications ??= 0;
$links = [
    ['/', 'common.nav.home', true],
    ['/events', 'common.nav.events', false],
    ['/volunteering', 'common.nav.volunteering', false],
    ['/facilities', 'common.nav.facilities', false],
    ['/about', 'common.nav.about', false],
    ['/contact', 'common.nav.contact', false],
];
?>
<header class="site-header">
    <nav class="navbar navbar-expand-xl" aria-label="<?= e(t('common.a11y.main_nav')) ?>">
        <div class="container">
            <a class="brand" href="<?= e(url('/')) ?>">
                <img src="<?= e(asset('assets/img/brand/tvtc.svg')) ?>" alt="" width="40" height="40">
                <span class="brand-name"><?= e(t('common.site_name')) ?><span class="brand-sub d-none d-sm-block d-xl-none d-xxl-block"><?= e(t('common.site_tagline')) ?></span></span>
            </a>

            <div class="d-flex align-items-center gap-1 order-xl-last ms-auto ms-xl-0">
                <span class="d-none d-md-inline-flex me-1"><?= $this->insert('partials/lang-switch') ?></span>
                <?php if ($currentUser !== null): ?>
                    <a class="icon-btn" href="<?= e(url('/notifications')) ?>" aria-label="<?= e(tc('notifications.bell_label', $unreadNotifications ?? 0)) ?>">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <?php if (($unreadNotifications ?? 0) > 0): ?><span class="count" aria-hidden="true"><?= e($unreadNotifications > 99 ? '99+' : $unreadNotifications) ?></span><?php endif; ?>
                    </a>
                    <?= $this->insert('partials/user-menu') ?>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-primary d-none d-sm-inline-block" href="<?= e(url('/login')) ?>"><?= e(t('common.nav.login')) ?></a>
                    <a class="btn btn-sm btn-primary" href="<?= e(url('/register')) ?>"><?= e(t('common.nav.register')) ?></a>
                <?php endif; ?>
                <button class="navbar-toggler border-0 ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-controls="siteNav" aria-expanded="false" aria-label="<?= e(t('common.actions.open_menu')) ?>">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>

            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav site-nav mx-xl-auto gap-xl-1 py-2 py-xl-0">
                    <?php foreach ($links as [$href, $label, $exact]): ?>
                        <?php $active = nav_active($href, $exact); ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e(t($label)) ?></a>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($currentUser !== null): ?>
                        <?php $dash = $currentUser->role->homePath(); ?>
                        <li class="nav-item">
                            <a class="nav-link<?= nav_active('/student') || nav_active('/admin') ? ' active' : '' ?>" href="<?= e(url($dash)) ?>">
                                <?= e(t($currentUser->isAdmin() ? 'common.nav.admin_panel' : 'common.nav.dashboard')) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item d-md-none pt-2"><?= $this->insert('partials/lang-switch') ?></li>
                    <?php if ($currentUser === null): ?>
                        <li class="nav-item d-sm-none"><a class="nav-link" href="<?= e(url('/login')) ?>"><?= e(t('common.nav.login')) ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
</header>
