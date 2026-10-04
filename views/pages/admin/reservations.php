<?php
/**
 * @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters
 * @var list<array<string,mixed>> $facilities  @var array<int,bool> $conflicts
 */
$this->layout('layouts/admin', ['title' => t('reservations.admin.title')]);
$d = dates();
?>
<?= $this->insert('partials/admin-head', [
    'heading' => t('reservations.admin.title'),
    'subheading' => t('reservations.admin.subtitle'),
    'actions' => '<a class="btn btn-light" href="' . e(url('/admin/facilities')) . '"><i class="bi bi-house-gear me-1" aria-hidden="true"></i>' . e(t('admin.nav.facilities')) . '</a>',
]) ?>
<?= $this->insert('partials/reservation-filters', ['filters' => $filters, 'facilities' => $facilities, 'action' => '/admin/reservations', 'withSearch' => true]) ?>

<?php if ($paginator->isEmpty()): ?>
    <div class="panel"><?= $this->component('empty', ['title' => t('reservations.admin.empty'), 'icon' => 'bi-building']) ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead><tr>
                <th scope="col"><?= e(t('reservations.cols.student')) ?></th>
                <th scope="col"><?= e(t('reservations.cols.facility')) ?></th>
                <th scope="col"><?= e(t('reservations.cols.date')) ?></th>
                <th scope="col"><?= e(t('reservations.cols.time')) ?></th>
                <th scope="col"><?= e(t('reservations.cols.status')) ?></th>
                <th scope="col" class="text-end"><?= e(t('common.actions.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($paginator->items as $r): ?>
                <tr>
                    <td class="cell-main">
                        <span class="cell-title"><?= e($r['full_name']) ?></span>
                        <div class="cell-sub" dir="ltr"><?= e($r['student_id'] ?? $r['email']) ?></div>
                    </td>
                    <td data-label="<?= e(t('reservations.cols.facility')) ?>">
                        <?= e(localized($r, 'facility_name')) ?>
                        <div class="cell-sub"><?= e($r['purpose']) ?><?= $r['expected_attendees'] ? ' — ' . e(tc('facilities.capacity_people', (int) $r['expected_attendees'])) : '' ?></div>
                        <?php if (!empty($r['notes'])): ?><div class="cell-sub"><?= e($r['notes']) ?></div><?php endif; ?>
                        <?php if (isset($conflicts[(int) $r['id']])): ?><div class="small text-danger fw-semibold mt-1"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= e(t('reservations.admin.conflict_warning')) ?></div><?php endif; ?>
                    </td>
                    <td data-label="<?= e(t('reservations.cols.date')) ?>"><?= e($d->fullDate($r['start_datetime'])) ?></td>
                    <td data-label="<?= e(t('reservations.cols.time')) ?>"><?= e($d->timeRange($r['start_datetime'], $r['end_datetime'])) ?></td>
                    <td data-label="<?= e(t('reservations.cols.status')) ?>">
                        <?= $this->component('status', ['status' => \App\Domain\ReservationStatus::from((string) $r['display_status'])]) ?>
                        <?php if (!empty($r['admin_note'])): ?><div class="cell-sub mt-1"><?= e($r['admin_note']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($r['can_reject']): ?>
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                <?php if ($r['can_approve']): ?>
                                    <form method="post" action="<?= e(url('/admin/reservations/' . $r['id'] . '/approve')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary" type="submit"><?= e(t('reservations.admin.approve')) ?></button></form>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#reject-<?= e($r['id']) ?>" aria-expanded="false" aria-controls="reject-<?= e($r['id']) ?>"><?= e(t('reservations.admin.reject')) ?></button>
                            </div>
                            <form class="collapse mt-2 text-start" id="reject-<?= e($r['id']) ?>" method="post" action="<?= e(url('/admin/reservations/' . $r['id'] . '/reject')) ?>">
                                <?= csrf_field() ?>
                                <label class="form-label small" for="note-<?= e($r['id']) ?>"><?= e(t('reservations.admin.reject_reason')) ?></label>
                                <textarea class="form-control form-control-sm mb-2" id="note-<?= e($r['id']) ?>" name="admin_note" rows="2" required minlength="3" maxlength="1000"></textarea>
                                <button class="btn btn-sm btn-danger" type="submit"><?= e(t('reservations.admin.confirm_reject')) ?></button>
                            </form>
                        <?php elseif ($r['can_cancel']): ?>
                            <form method="post" action="<?= e(url('/admin/reservations/' . $r['id'] . '/cancel')) ?>" data-confirm="<?= e(t('reservations.admin.cancel_confirm')) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t('reservations.cancel')) ?>">
                                <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('reservations.cancel')) ?></button>
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
