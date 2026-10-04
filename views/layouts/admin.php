<?php
/** @var \App\Core\Template $this  Admin area: sidebar (off-canvas below lg) + top bar. */
$this->layout('layouts/base', ['title' => ($title ?? '') !== '' ? $title . ' | ' . t('admin.panel') : t('admin.panel')]);
$counts = ($adminNavCounts ?? []) + ['pending_reservations' => 0, 'open_feedback' => 0, 'unread_messages' => 0];
$nav = [
    ['/admin/dashboard', 'bi-grid-1x2', 'admin.nav.dashboard', null],
    ['/admin/events', 'bi-calendar-event', 'admin.nav.events', null],
    ['/admin/volunteering', 'bi-people', 'admin.nav.volunteering', null],
    ['/admin/registrations', 'bi-card-checklist', 'admin.nav.registrations', null],
    ['/admin/reservations', 'bi-building', 'admin.nav.reservations', $counts['pending_reservations']],
    ['/admin/facilities', 'bi-house-gear', 'admin.nav.facilities', null],
    ['/admin/feedback', 'bi-chat-square-text', 'admin.nav.feedback', $counts['open_feedback']],
    ['/admin/messages', 'bi-envelope', 'admin.nav.messages', $counts['unread_messages']],
    ['/admin/users', 'bi-person-gear', 'admin.nav.users', null],
    ['/admin/settings', 'bi-sliders', 'admin.nav.settings', null],
];
?>
<div class="admin-shell">
    <div class="admin-sidebar">
        <div class="offcanvas-lg offcanvas-start" tabindex="-1" id="adminNav" aria-labelledby="adminNavLabel">
            <div class="offcanvas-header p-3">
                <a class="brand" href="<?= e(url('/admin/dashboard')) ?>" id="adminNavLabel">
                    <img src="<?= e(asset('assets/img/brand/tvtc.svg')) ?>" alt="">
                    <span class="brand-name"><?= e(t('common.site_name')) ?><span class="brand-sub"><?= e(t('admin.panel')) ?></span></span>
                </a>
                <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#adminNav" aria-label="<?= e(t('common.actions.close')) ?>"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-2 pt-0">
                <nav class="side-nav nav flex-column" aria-label="<?= e(t('admin.panel')) ?>">
                    <?php foreach ($nav as [$href, $icon, $label, $badge]): ?>
                        <a class="nav-link<?= nav_active($href) ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= nav_active($href) ? ' aria-current="page"' : '' ?>>
                            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                            <span><?= e(t($label)) ?></span>
                            <?php if ($badge): ?><span class="badge rounded-pill text-bg-warning"><?= e(fmt_number($badge)) ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="side-group mt-auto"><?= e(t('admin.nav.site')) ?></div>
                <nav class="side-nav nav flex-column mb-2">
                    <a class="nav-link" href="<?= e(url('/')) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span><?= e(t('admin.nav.view_site')) ?></span></a>
                </nav>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column min-w-0">
        <header class="admin-topbar">
            <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminNav" aria-controls="adminNav" aria-label="<?= e(t('common.actions.open_menu')) ?>">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <div class="flex-grow-1"></div>
            <?= $this->insert('partials/lang-switch') ?>
            <?= $this->insert('partials/user-menu') ?>
        </header>
        <main id="main" tabindex="-1" class="admin-content">
            <?= $this->section('content') ?>
        </main>
    </div>
</div>
