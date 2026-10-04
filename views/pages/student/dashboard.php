<?php
/**
 * @var \App\Core\Template $this
 * @var array<string,int|float> $stats
 * @var list<array<string,mixed>> $myUpcoming
 * @var list<array<string,mixed>> $suggestedEvents
 * @var list<array<string,mixed>> $opportunities
 * @var list<array<string,mixed>> $activity
 * @var list<array<string,mixed>> $notifications
 */
$this->layout('layouts/app', ['title' => t('student.dashboard.title')]);
$user = auth_user();
$d = dates();
$kindIcons = ['event' => 'bi-calendar-event', 'volunteer' => 'bi-heart', 'reservation' => 'bi-building', 'feedback' => 'bi-chat-square-text'];
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('student.dashboard.welcome', ['name' => $user->firstName()]),
    'subheading' => t('student.dashboard.subtitle'),
]) ?>
<?= $this->insert('partials/student-nav') ?>

<section class="section-tight">
    <div class="container">
        <?php if ($user->studentId === null || $user->phone === null): ?>
            <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
                <span><i class="bi bi-person-exclamation me-2" aria-hidden="true"></i><?= e(t('student.dashboard.complete_profile')) ?></span>
                <a class="btn btn-sm btn-warning" href="<?= e(url('/profile')) ?>"><?= e(t('student.dashboard.complete_profile_action')) ?></a>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-4"><?= $this->component('stat', ['label' => t('student.stats.registered_events'), 'value' => $stats['registered_events'], 'icon' => 'bi-calendar-check', 'href' => '/student/registrations']) ?></div>
            <div class="col-md-4"><?= $this->component('stat', ['label' => t('student.stats.completed_events'), 'value' => $stats['completed_events'], 'icon' => 'bi-patch-check', 'tone' => 'navy', 'href' => '/student/registrations?tab=completed']) ?></div>
            <div class="col-md-4"><?= $this->component('stat', ['label' => t('student.stats.volunteer_hours'), 'value' => tc('volunteering.hours_count', (float) $stats['volunteer_hours']), 'icon' => 'bi-hourglass-split', 'tone' => 'amber', 'href' => '/student/volunteering']) ?></div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="panel mb-4">
                    <div class="panel-head">
                        <h2><?= e(t('student.dashboard.my_upcoming')) ?></h2>
                        <a class="small" href="<?= e(url('/student/registrations')) ?>"><?= e(t('common.actions.view_all')) ?></a>
                    </div>
                    <?php if ($myUpcoming === []): ?>
                        <?= $this->component('empty', ['title' => t('student.dashboard.no_upcoming'), 'text' => t('student.dashboard.no_upcoming_text'), 'icon' => 'bi-calendar-plus', 'actionHref' => '/events', 'actionLabel' => t('home.hero.explore_events')]) ?>
                    <?php else: ?>
                        <ul class="list-plain">
                            <?php foreach ($myUpcoming as $e): ?>
                                <li class="list-row">
                                    <?= $this->component('datebox', ['date' => $e['start_datetime']]) ?>
                                    <span class="grow">
                                        <a class="title" href="<?= e(url('/events/' . $e['id'])) ?>"><?= e(localized($e, 'title')) ?></a>
                                        <span class="sub"><?= e($d->timeRange($e['start_datetime'], $e['end_datetime'])) ?> — <?= e(localized($e, 'location')) ?></span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="section-head mb-3"><h2 class="h5 mb-0"><?= e(t('student.dashboard.suggested')) ?></h2></div>
                <?php if ($suggestedEvents === []): ?>
                    <p class="text-muted"><?= e(t('events.empty.upcoming')) ?></p>
                <?php else: ?>
                    <div class="row g-3 mb-4">
                        <?php foreach (array_slice($suggestedEvents, 0, 2) as $event): ?>
                            <div class="col-md-6"><?= $this->component('activity-card', ['item' => $event, 'kind' => 'event']) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="section-head mb-3">
                    <h2 class="h5 mb-0"><?= e(t('student.dashboard.opportunities')) ?></h2>
                    <a class="small" href="<?= e(url('/volunteering')) ?>"><?= e(t('common.actions.view_all')) ?></a>
                </div>
                <?php if ($opportunities === []): ?>
                    <p class="text-muted"><?= e(t('volunteering.empty.upcoming')) ?></p>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach (array_slice($opportunities, 0, 2) as $o): ?>
                            <div class="col-md-6"><?= $this->component('activity-card', ['item' => $o, 'kind' => 'volunteer']) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <div class="panel mb-4">
                    <div class="panel-head">
                        <h2><?= e(t('notifications.title')) ?></h2>
                        <a class="small" href="<?= e(url('/notifications')) ?>"><?= e(t('common.actions.view_all')) ?></a>
                    </div>
                    <?php if ($notifications === []): ?>
                        <p class="panel-body text-muted mb-0"><?= e(t('notifications.empty')) ?></p>
                    <?php else: ?>
                        <ul class="list-plain">
                            <?php foreach ($notifications as $n): ?>
                                <li class="list-row<?= $n['is_read'] ? '' : ' notif-unread' ?>">
                                    <span class="grow">
                                        <span class="title"><?= e($n['title']) ?></span>
                                        <span class="sub"><?= e($d->relative($n['created_at'])) ?></span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-head"><h2><?= e(t('student.dashboard.activity')) ?></h2></div>
                    <?php if ($activity === []): ?>
                        <p class="panel-body text-muted mb-0"><?= e(t('student.dashboard.no_activity')) ?></p>
                    <?php else: ?>
                        <ul class="list-plain">
                            <?php foreach ($activity as $a): ?>
                                <li class="list-row">
                                    <span class="notif-ico" aria-hidden="true"><i class="bi <?= e($kindIcons[$a['kind']] ?? 'bi-dot') ?>"></i></span>
                                    <span class="grow">
                                        <a class="title text-truncate" href="<?= e(url((string) $a['link'])) ?>"><?= e(localized($a, 'title')) ?></a>
                                        <span class="sub"><?= e(t('student.activity.' . $a['kind'] . '.' . $a['status'])) ?> — <?= e($d->relative($a['at'])) ?></span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
