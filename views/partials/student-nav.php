<?php
$items = [
    ['/student/dashboard', 'common.nav.dashboard'],
    ['/student/registrations', 'common.nav.my_registrations'],
    ['/student/volunteering', 'common.nav.my_volunteering'],
    ['/student/reservations', 'common.nav.my_reservations'],
    ['/student/feedback', 'common.nav.my_feedback'],
    ['/profile', 'common.nav.profile'],
];
?>
<div class="band border-top-0">
    <div class="container">
        <nav class="tabs" aria-label="<?= e(t('common.a11y.student_nav')) ?>">
            <?php foreach ($items as [$href, $label]): ?>
                <a href="<?= e(url($href)) ?>"<?= nav_active($href) ? ' aria-current="page"' : '' ?>><?= e(t($label)) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
