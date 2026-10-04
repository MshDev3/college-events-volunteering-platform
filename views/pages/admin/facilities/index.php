<?php
/** @var \App\Core\Template $this  @var list<array<string,mixed>> $facilities */
$this->layout('layouts/admin', ['title' => t('facilities.admin.title')]);
?>
<?= $this->insert('partials/admin-head', [
    'heading' => t('facilities.admin.title'),
    'back' => ['/admin/reservations', t('reservations.admin.title')],
    'actions' => '<a class="btn btn-primary" href="' . e(url('/admin/facilities/create')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>' . e(t('facilities.admin.create')) . '</a>',
]) ?>
<div class="table-wrap">
    <table class="table table-stack">
        <thead><tr>
            <th scope="col"><?= e(t('facilities.admin.cols.name')) ?></th>
            <th scope="col"><?= e(t('events.fields.location')) ?></th>
            <th scope="col"><?= e(t('events.fields.capacity')) ?></th>
            <th scope="col"><?= e(t('common.filters.status')) ?></th>
            <th scope="col" class="text-end"><?= e(t('common.actions.actions')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($facilities as $f): ?>
            <tr>
                <td class="cell-main"><span class="cell-title"><?= e(localized($f, 'name')) ?></span><div class="cell-sub" dir="ltr"><?= e($f['code']) ?></div></td>
                <td data-label="<?= e(t('events.fields.location')) ?>"><?= e(localized($f, 'location')) ?></td>
                <td data-label="<?= e(t('events.fields.capacity')) ?>"><?= $f['capacity'] !== null ? e(fmt_number($f['capacity'])) : '—' ?></td>
                <td data-label="<?= e(t('common.filters.status')) ?>"><span class="status status-<?= $f['is_active'] ? 'success' : 'secondary' ?>"><?= e(t($f['is_active'] ? 'common.states.active' : 'common.states.inactive')) ?></span></td>
                <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('/admin/facilities/' . $f['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
