<?php
/** @var \App\Core\Template $this  @var float $hours  @var string|null $status  @var \App\Core\Paginator $paginator */
$this->layout('layouts/app', ['title' => t('student.volunteering.title')]);
$d = dates();
?>
<?= $this->insert('partials/page-head', ['heading' => t('student.volunteering.title'), 'subheading' => t('student.volunteering.subtitle')]) ?>
<?= $this->insert('partials/student-nav') ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-3 mb-4">
            <div class="col-md-4"><?= $this->component('stat', ['label' => t('student.stats.volunteer_hours'), 'value' => tc('volunteering.hours_count', $hours), 'icon' => 'bi-hourglass-split', 'tone' => 'amber']) ?></div>
            <div class="col-md-8 d-flex align-items-center"><p class="text-muted mb-0"><?= e(t('volunteering.hours_policy')) ?></p></div>
        </div>

        <nav class="tabs mb-3" aria-label="<?= e(t('common.filters.status')) ?>">
            <a href="<?= e(url('/student/volunteering')) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>><?= e(t('common.filters.all')) ?></a>
            <?php foreach (\App\Domain\VolunteerRegistrationStatus::cases() as $case): ?>
                <a href="<?= e(url('/student/volunteering', ['status' => $case->value])) ?>"<?= $status === $case->value ? ' aria-current="page"' : '' ?>><?= e(t($case->labelKey())) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($paginator->isEmpty()): ?>
            <div class="panel"><?= $this->component('empty', ['title' => t('student.volunteering.empty'), 'icon' => 'bi-heart', 'actionHref' => '/volunteering', 'actionLabel' => t('home.hero.volunteer')]) ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead><tr>
                        <th scope="col"><?= e(t('student.volunteering.cols.opportunity')) ?></th>
                        <th scope="col"><?= e(t('student.registrations.cols.date')) ?></th>
                        <th scope="col"><?= e(t('student.volunteering.cols.hours')) ?></th>
                        <th scope="col"><?= e(t('student.registrations.cols.status')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('common.actions.actions')) ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($paginator->items as $row): ?>
                        <tr>
                            <td class="cell-main"><a class="cell-title" href="<?= e(url('/volunteering/' . $row['id'])) ?>"><?= e(localized($row, 'title')) ?></a>
                                <div class="cell-sub"><?= e(localized($row, 'location')) ?></div></td>
                            <td data-label="<?= e(t('student.registrations.cols.date')) ?>"><?= e($d->date($row['start_datetime'])) ?><div class="cell-sub"><?= e($d->timeRange($row['start_datetime'], $row['end_datetime'])) ?></div></td>
                            <td data-label="<?= e(t('student.volunteering.cols.hours')) ?>">
                                <?php if ($row['registration_status'] === 'COMPLETED'): ?>
                                    <strong><?= e(tc('volunteering.hours_count', (float) $row['hours_awarded'])) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted"><?= e(t('student.volunteering.hours_pending', ['hours' => fmt_number($row['volunteer_hours'], 1)])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= e(t('student.registrations.cols.status')) ?>"><?= $this->component('status', ['status' => \App\Domain\VolunteerRegistrationStatus::from((string) $row['registration_status'])]) ?></td>
                            <td class="text-end">
                                <?php if ($row['can_unregister']): ?>
                                    <form method="post" action="<?= e(url('/volunteering/' . $row['id'] . '/unregister')) ?>" data-confirm="<?= e(t('volunteering.action.unregister_confirm')) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t('volunteering.action.unregister')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('volunteering.action.unregister')) ?></button>
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
