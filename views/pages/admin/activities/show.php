<?php
/**
 * @var \App\Core\Template $this  @var string $ns  @var array<string,mixed> $item
 * @var \App\Core\Paginator $registrations  @var string $q
 */
$title = localized($item, 'title');
$this->layout('layouts/admin', ['title' => $title]);
$d = dates();
$status = \App\Domain\ActivityStatus::from((string) $item['status']);
$isVolunteer = $ns === 'volunteering';
$regEnum = $isVolunteer ? \App\Domain\VolunteerRegistrationStatus::class : \App\Domain\EventRegistrationStatus::class;
$actions = '<a class="btn btn-light" href="' . e(url(($isVolunteer ? '/volunteering/' : '/events/') . $item['id'])) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>' . e(t('common.actions.view_public')) . '</a>'
    . '<a class="btn btn-primary" href="' . e(url("/admin/$ns/" . $item['id'] . '/edit')) . '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>' . e(t('common.actions.edit')) . '</a>';
?>
<?= $this->insert('partials/admin-head', ['heading' => $title, 'back' => ["/admin/$ns", t("$ns.admin.title")], 'actions' => $actions]) ?>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="panel mb-3">
            <div class="panel-body">
                <div class="d-flex flex-wrap gap-2 mb-3"><?= $this->component('status', ['status' => $status]) ?><span class="chip"><?= e(localized($item, 'type_name')) ?></span></div>
                <ul class="fact-list mb-3">
                    <li><i class="bi bi-calendar3" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.date')) ?></span><span class="v"><?= e($d->schedule($item['start_datetime'], $item['end_datetime'])) ?></span></span></li>
                    <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.location')) ?></span><span class="v"><?= e(localized($item, 'location')) ?></span></span></li>
                    <?php if ($isVolunteer): ?>
                        <li><i class="bi bi-hourglass-split" aria-hidden="true"></i><span><span class="k"><?= e(t('volunteering.fields.hours')) ?></span><span class="v"><?= e(tc('volunteering.hours_count', (float) $item['volunteer_hours'])) ?></span></span></li>
                    <?php endif; ?>
                </ul>
                <?= $this->component('meter', ['registered' => (int) $item['registered_count'], 'capacity' => (int) $item['capacity']]) ?>
                <?php if ($item['cancelled_at'] !== null && !empty($item['cancel_reason'])): ?>
                    <div class="alert alert-danger small mt-3 mb-0"><?= e($item['cancel_reason']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($status === \App\Domain\ActivityStatus::UPCOMING || $status === \App\Domain\ActivityStatus::ONGOING): ?>
            <div class="panel mb-3">
                <div class="panel-head"><h2><?= e(t("$ns.admin.cancel_title")) ?></h2></div>
                <div class="panel-body">
                    <form method="post" action="<?= e(url("/admin/$ns/" . $item['id'] . '/cancel')) ?>" data-confirm="<?= e(t("$ns.admin.cancel_confirm")) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t("$ns.admin.cancel")) ?>">
                        <?= csrf_field() ?>
                        <?= $this->component('field', ['name' => 'reason', 'type' => 'textarea', 'rows' => 2, 'label' => t("$ns.admin.cancel_reason"), 'help' => t("$ns.admin.cancel_help"), 'attrs' => ['maxlength' => 255]]) ?>
                        <button class="btn btn-outline-danger w-100" type="submit"><?= e(t("$ns.admin.cancel")) ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url("/admin/$ns/" . $item['id'] . '/delete')) ?>" data-confirm="<?= e(t("$ns.admin.delete_confirm")) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t('common.actions.delete')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-link text-danger px-0" type="submit"<?= (int) $item['registered_count'] > 0 ? ' disabled aria-describedby="delete-help"' : '' ?>><i class="bi bi-trash me-1" aria-hidden="true"></i><?= e(t('common.actions.delete')) ?></button>
            <?php if ((int) $item['registered_count'] > 0): ?><div class="form-text" id="delete-help"><?= e(t("$ns.errors.delete_has_registrations")) ?></div><?php endif; ?>
        </form>
    </div>

    <div class="col-xl-8">
        <div class="panel">
            <div class="panel-head">
                <h2><?= e(t('events.admin.registrations_title')) ?> <span class="chip ms-1"><?= e(fmt_number($registrations->total)) ?></span></h2>
                <form class="d-flex gap-2" method="get" role="search">
                    <label class="visually-hidden" for="reg-q"><?= e(t('common.filters.search')) ?></label>
                    <input class="form-control form-control-sm" type="search" id="reg-q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('events.admin.search_attendees')) ?>">
                    <button class="btn btn-sm btn-light" type="submit"><?= e(t('common.filters.apply')) ?></button>
                </form>
            </div>
            <?php if ($registrations->isEmpty()): ?>
                <?= $this->component('empty', ['title' => t('events.admin.no_registrations'), 'icon' => 'bi-person-plus']) ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-stack mb-0">
                        <thead><tr>
                            <th scope="col"><?= e(t('users.cols.name')) ?></th>
                                                        <th scope="col"><?= e(t('events.admin.cols.status')) ?></th>
                            <?php if ($isVolunteer): ?><th scope="col"><?= e(t('volunteering.fields.hours')) ?></th><?php endif; ?>
                            <th scope="col" class="text-end"><?= e(t('common.actions.actions')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($registrations->items as $r): ?>
                            <tr>
                                <td class="cell-main"><span class="cell-title"><?= e($r['full_name']) ?></span><div class="cell-sub" dir="ltr"><?= e($r['email']) ?><?= $r['student_id'] ? ' — ' . e($r['student_id']) : '' ?></div>
                                    <?php if ($isVolunteer && !empty($r['motivation'])): ?><div class="cell-sub"><i class="bi bi-chat-left-quote me-1" aria-hidden="true"></i><?= e($r['motivation']) ?></div><?php endif; ?></td>
                                                                <td data-label="<?= e(t('events.admin.cols.status')) ?>"><?= $this->component('status', ['status' => $regEnum::from((string) $r['status'])]) ?></td>
                                <?php if ($isVolunteer): ?><td data-label="<?= e(t('volunteering.fields.hours')) ?>"><?= $r['hours_awarded'] !== null ? e(fmt_number($r['hours_awarded'], 1)) : '—' ?></td><?php endif; ?>
                                <td class="text-end">
                                    <div class="d-flex flex-column gap-1 align-items-end">
                                        <?php if ($r['can_mark_attended']): ?>
                                            <form method="post" action="<?= e(url("/admin/$ns/registrations/" . $r['id'] . '/attendance')) ?>"><?= csrf_field() ?><input type="hidden" name="attended" value="1"><button class="btn btn-sm btn-light" type="submit"><?= e(t('events.admin.mark_attended')) ?></button></form>
                                        <?php elseif ($r['can_undo_attended']): ?>
                                            <form method="post" action="<?= e(url("/admin/$ns/registrations/" . $r['id'] . '/attendance')) ?>"><?= csrf_field() ?><input type="hidden" name="attended" value="0"><button class="btn btn-sm btn-light" type="submit"><?= e(t('events.admin.undo_attended')) ?></button></form>
                                        <?php endif; ?>
                                        <?php if (!empty($r['can_complete'])): ?>
                                            <form class="d-inline-flex gap-1" method="post" action="<?= e(url('/admin/volunteering/registrations/' . $r['id'] . '/complete')) ?>">
                                                <?= csrf_field() ?>
                                                <label class="visually-hidden" for="h-<?= e($r['id']) ?>"><?= e(t('volunteering.fields.hours')) ?></label>
                                                <input class="form-control form-control-sm input-hours" type="number" step="0.5" min="0" max="200" id="h-<?= e($r['id']) ?>" name="hours" value="<?= e((float) $item['volunteer_hours']) ?>">
                                                <button class="btn btn-sm btn-primary" type="submit"><?= e(t('volunteering.admin.approve_completion')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($r['can_cancel_registration'])): ?>
                                            <form method="post" action="<?= e(url('/admin/volunteering/registrations/' . $r['id'] . '/cancel')) ?>" data-confirm="<?= e(t('volunteering.admin.cancel_registration_confirm')) ?>" data-confirm-tone="danger"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('common.actions.cancel')) ?></button></form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?= $this->insert('partials/pagination', ['paginator' => $registrations]) ?>
        <?php if ($isVolunteer): ?><p class="small text-muted mt-3"><?= e(t('volunteering.hours_policy')) ?></p><?php endif; ?>
    </div>
</div>
