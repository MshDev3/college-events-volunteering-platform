<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters  @var list<array<string,mixed>> $facilities */
$this->layout('layouts/app', ['title' => t('reservations.my_title')]);
$d = dates();
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('reservations.my_title'),
    'subheading' => t('reservations.my_subtitle'),
    'actions' => '<a class="btn btn-primary" href="' . e(url('/student/reservations/new')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>' . e(t('reservations.new')) . '</a>',
]) ?>
<?= $this->insert('partials/student-nav') ?>
<section class="section-tight">
    <div class="container">
        <?= $this->insert('partials/reservation-filters', ['filters' => $filters, 'facilities' => $facilities, 'action' => '/student/reservations', 'withSearch' => false]) ?>

        <?php if ($paginator->isEmpty()): ?>
            <div class="panel"><?= $this->component('empty', ['title' => t('reservations.empty'), 'text' => t('reservations.empty_text'), 'icon' => 'bi-building', 'actionHref' => '/student/reservations/new', 'actionLabel' => t('reservations.new')]) ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead><tr>
                        <th scope="col"><?= e(t('reservations.cols.facility')) ?></th>
                        <th scope="col"><?= e(t('reservations.cols.date')) ?></th>
                        <th scope="col"><?= e(t('reservations.cols.time')) ?></th>
                        <th scope="col"><?= e(t('reservations.cols.status')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('common.actions.actions')) ?></span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($paginator->items as $r): ?>
                        <tr>
                            <td class="cell-main">
                                <span class="cell-title"><?= e(localized($r, 'facility_name')) ?></span>
                                <div class="cell-sub"><?= e($r['purpose']) ?></div>
                                <?php if (!empty($r['admin_note'])): ?><div class="cell-sub"><i class="bi bi-chat-left-quote me-1" aria-hidden="true"></i><?= e($r['admin_note']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="<?= e(t('reservations.cols.date')) ?>"><?= e($d->fullDate($r['start_datetime'])) ?></td>
                            <td data-label="<?= e(t('reservations.cols.time')) ?>"><?= e($d->timeRange($r['start_datetime'], $r['end_datetime'])) ?></td>
                            <td data-label="<?= e(t('reservations.cols.status')) ?>"><?= $this->component('status', ['status' => \App\Domain\ReservationStatus::from((string) $r['display_status'])]) ?></td>
                            <td class="text-end">
                                <?php if ($r['can_cancel']): ?>
                                    <form method="post" action="<?= e(url('/student/reservations/' . $r['id'] . '/cancel')) ?>" data-confirm="<?= e(t('reservations.cancel_confirm')) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t('reservations.cancel')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('reservations.cancel')) ?></button>
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
