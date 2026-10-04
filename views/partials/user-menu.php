<?php
/** @var \App\Domain\User|null $currentUser */
$currentUser ??= null;
if ($currentUser === null) {
    return;
}
$items = $currentUser->isAdmin()
    ? [
        ['/admin/dashboard', 'bi-grid-1x2', 'admin.nav.dashboard'],
        ['/admin/events', 'bi-calendar-event', 'admin.nav.events'],
        ['/admin/volunteering', 'bi-people', 'admin.nav.volunteering'],
        ['/admin/users', 'bi-person-gear', 'admin.nav.users'],
        ['/admin/reservations', 'bi-building', 'admin.nav.reservations'],
        ['/admin/feedback', 'bi-chat-square-text', 'admin.nav.feedback'],
        ['/admin/messages', 'bi-envelope', 'admin.nav.messages'],
        ['/profile', 'bi-person', 'common.nav.profile'],
    ]
    : [
        ['/student/dashboard', 'bi-grid-1x2', 'common.nav.dashboard'],
        ['/student/registrations', 'bi-calendar-check', 'common.nav.my_registrations'],
        ['/student/volunteering', 'bi-heart', 'common.nav.my_volunteering'],
        ['/student/reservations', 'bi-building', 'common.nav.my_reservations'],
        ['/student/feedback', 'bi-chat-square-text', 'common.nav.my_feedback'],
        ['/profile', 'bi-person', 'common.nav.profile'],
    ];
?>
<div class="dropdown">
    <button class="btn btn-link p-0 d-flex align-items-center gap-2 text-decoration-none" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e(t('common.a11y.account_menu')) ?>">
        <span class="avatar" aria-hidden="true"><?= e($currentUser->initials()) ?></span>
        <span class="d-none d-xxl-inline text-body fw-semibold"><?= e($currentUser->firstName()) ?></span>
        <i class="bi bi-chevron-down small text-muted d-none d-xxl-inline" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li class="px-3 py-2">
            <div class="fw-semibold"><?= e($currentUser->fullName) ?></div>
            <div class="small text-muted"><?= e($currentUser->email) ?></div>
            <span class="chip mt-1"><?= e(t($currentUser->role->labelKey())) ?></span>
        </li>
        <li><hr class="dropdown-divider"></li>
        <?php foreach ($items as [$href, $icon, $label]): ?>
            <li><a class="dropdown-item" href="<?= e(url($href)) ?>"><i class="bi <?= e($icon) ?> me-2" aria-hidden="true"></i><?= e(t($label)) ?></a></li>
        <?php endforeach; ?>
        <li><hr class="dropdown-divider"></li>
        <li>
            <form method="post" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button class="dropdown-item text-danger" type="submit" data-no-loading><i class="bi bi-box-arrow-right flip-rtl me-2" aria-hidden="true"></i><?= e(t('common.nav.logout')) ?></button>
            </form>
        </li>
    </ul>
</div>
