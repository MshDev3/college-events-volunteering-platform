<?php
/**
 * @var \App\Core\Template $this
 * @var array<string,int|float> $stats
 * @var list<array<string,mixed>> $pendingReservations
 * @var list<array<string,mixed>> $latestFeedback
 * @var list<array<string,mixed>> $upcomingEvents
 */
$this->layout('layouts/admin', ['title' => t('admin.dashboard.title')]);
$d = dates();
$cards = [
    ['students', 'bi-mortarboard', '', '/admin/users?role=STUDENT'],
    ['events', 'bi-calendar-event', 'navy', '/admin/events'],
    ['upcoming_events', 'bi-calendar-plus', '', '/admin/events?status=UPCOMING'],
    ['registrations', 'bi-card-checklist', 'navy', '/admin/registrations'],
    ['opportunities', 'bi-people', '', '/admin/volunteering'],
    ['volunteer_hours', 'bi-hourglass-split', 'amber', '/admin/volunteering'],
    ['pending_reservations', 'bi-building', 'amber', '/admin/reservations?status=PENDING'],
    ['open_complaints', 'bi-exclamation-diamond', 'red', '/admin/feedback?type=COMPLAINT'],
    ['unread_messages', 'bi-envelope', 'red', '/admin/messages?status=UNREAD'],
];
?>
<?= $this->insert('partials/admin-head', ['heading' => t('admin.dashboard.title'), 'subheading' => t('admin.dashboard.subtitle', ['name' => auth_user()->firstName()])]) ?>

<div class="row g-3 mb-4">
    <?php foreach ($cards as [$key, $icon, $tone, $href]): ?>
        <div class="col-sm-6 col-xl-4">
            <?= $this->component('stat', ['label' => t('admin.stats.' . $key), 'value' => $stats[$key], 'icon' => $icon, 'tone' => $tone, 'href' => $href]) ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="panel h-100">
            <div class="panel-head"><h2><?= e(t('admin.dashboard.pending_reservations')) ?></h2><a class="small" href="<?= e(url('/admin/reservations', ['status' => 'PENDING'])) ?>"><?= e(t('common.actions.view_all')) ?></a></div>
            <?php if ($pendingReservations === []): ?>
                <p class="panel-body text-muted mb-0"><?= e(t('admin.dashboard.no_pending')) ?></p>
            <?php else: ?>
                <ul class="list-plain">
                    <?php foreach ($pendingReservations as $r): ?>
                        <li class="list-row">
                            <?= $this->component('datebox', ['date' => $r['start_datetime']]) ?>
                            <span class="grow">
                                <span class="title"><?= e(localized($r, 'facility_name')) ?></span>
                                <span class="sub"><?= e($r['full_name']) ?> — <?= e($d->timeRange($r['start_datetime'], $r['end_datetime'])) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="panel h-100">
            <div class="panel-head"><h2><?= e(t('admin.dashboard.latest_feedback')) ?></h2><a class="small" href="<?= e(url('/admin/feedback')) ?>"><?= e(t('common.actions.view_all')) ?></a></div>
            <?php if ($latestFeedback === []): ?>
                <p class="panel-body text-muted mb-0"><?= e(t('feedback.empty')) ?></p>
            <?php else: ?>
                <ul class="list-plain">
                    <?php foreach ($latestFeedback as $f): ?>
                        <li class="list-row">
                            <span class="grow">
                                <a class="title" href="<?= e(url('/admin/feedback/' . $f['id'])) ?>"><?= e($f['subject']) ?></a>
                                <span class="sub"><?= e((string) $f['author_name']) ?> — <?= e($d->relative($f['created_at'])) ?></span>
                            </span>
                            <?= $this->component('status', ['status' => \App\Domain\FeedbackStatus::from((string) $f['status'])]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12">
        <div class="panel">
            <div class="panel-head"><h2><?= e(t('admin.dashboard.upcoming_events')) ?></h2><a class="btn btn-sm btn-primary" href="<?= e(url('/admin/events/create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i><?= e(t('events.admin.create')) ?></a></div>
            <?php if ($upcomingEvents === []): ?>
                <p class="panel-body text-muted mb-0"><?= e(t('events.empty.upcoming')) ?></p>
            <?php else: ?>
                <ul class="list-plain">
                    <?php foreach ($upcomingEvents as $e): ?>
                        <li class="list-row">
                            <?= $this->component('datebox', ['date' => $e['start_datetime']]) ?>
                            <span class="grow">
                                <a class="title" href="<?= e(url('/admin/events/' . $e['id'])) ?>"><?= e(localized($e, 'title')) ?></a>
                                <span class="sub"><?= e($d->timeRange($e['start_datetime'], $e['end_datetime'])) ?> — <?= e(localized($e, 'location')) ?></span>
                            </span>
                            <span class="d-none d-md-block w-25"><?= $this->component('meter', ['registered' => (int) $e['registered_count'], 'capacity' => (int) $e['capacity']]) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
