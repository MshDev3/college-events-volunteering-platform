<?php
/** @var \App\Core\Template $this  @var \App\Domain\ActivityStatus $tab  @var array<string,int> $counts  @var \App\Core\Paginator $paginator */
$this->layout('layouts/app', ['title' => t('student.registrations.title')]);
$d = dates();
$tabs = ['UPCOMING' => 'upcoming', 'ONGOING' => 'ongoing', 'COMPLETED' => 'completed', 'CANCELLED' => 'cancelled'];
?>
<?= $this->insert('partials/page-head', ['heading' => t('student.registrations.title'), 'subheading' => t('student.registrations.subtitle')]) ?>
<?= $this->insert('partials/student-nav') ?>
<section class="section-tight">
    <div class="container">
        <nav class="tabs mb-3" aria-label="<?= e(t('student.registrations.tabs_label')) ?>">
            <?php foreach ($tabs as $value => $key): ?>
                <a href="<?= e(url('/student/registrations', ['tab' => $key])) ?>"<?= $tab->value === $value ? ' aria-current="page"' : '' ?>>
                    <?= e(t('common.status.activity.' . $value)) ?><span class="count"><?= e($counts[$key] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($paginator->isEmpty()): ?>
            <div class="panel"><?= $this->component('empty', [
                'title' => t('student.registrations.empty.' . strtolower($tab->value)),
                'icon' => 'bi-calendar2',
                'actionHref' => $tab === \App\Domain\ActivityStatus::UPCOMING ? '/events' : null,
                'actionLabel' => t('home.hero.explore_events'),
            ]) ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead><tr>
                        <th scope="col"><?= e(t('student.registrations.cols.event')) ?></th>
                        <th scope="col"><?= e(t('student.registrations.cols.date')) ?></th>
                        <th scope="col"><?= e(t('student.registrations.cols.location')) ?></th>
                        <th scope="col"><?= e(t('student.registrations.cols.status')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('common.actions.actions')) ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($paginator->items as $row): ?>
                        <?php $eventStatus = \App\Domain\ActivityStatus::from((string) $row['status']); ?>
                        <tr>
                            <td class="cell-main"><a class="cell-title" href="<?= e(url('/events/' . $row['id'])) ?>"><?= e(localized($row, 'title')) ?></a></td>
                            <td data-label="<?= e(t('student.registrations.cols.date')) ?>">
                                <div><?= e($d->fullDate($row['start_datetime'])) ?></div>
                                <div class="cell-sub"><?= e($d->timeRange($row['start_datetime'], $row['end_datetime'])) ?></div>
                            </td>
                            <td data-label="<?= e(t('student.registrations.cols.location')) ?>"><?= e(localized($row, 'location')) ?></td>
                            <td data-label="<?= e(t('student.registrations.cols.status')) ?>">
                                <div class="d-flex flex-wrap gap-1 justify-content-end justify-content-md-start">
                                    <?= $this->component('status', ['status' => $eventStatus]) ?>
                                    <?= $this->component('status', ['status' => \App\Domain\EventRegistrationStatus::from((string) $row['registration_status'])]) ?>
                                </div>
                            </td>
                            <td class="text-end">
                                <?php if ($row['can_unregister']): ?>
                                    <form method="post" action="<?= e(url('/events/' . $row['id'] . '/unregister')) ?>" data-confirm="<?= e(t('events.action.unregister_confirm')) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t('events.action.unregister')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('events.action.unregister')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
        <?php endif; ?>
    </div>
</section>
