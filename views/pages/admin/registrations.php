<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters */
$this->layout('layouts/admin', ['title' => t('admin.registrations.title')]);
$d = dates();
?>
<?= $this->insert('partials/admin-head', ['heading' => t('admin.registrations.title'), 'subheading' => t('admin.registrations.subtitle')]) ?>
<?= $this->component('filter-bar', [
    'action' => '/admin/registrations',
    'filters' => $filters,
    'fields' => [
        ['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search'), 'placeholder' => t('admin.registrations.search_placeholder'), 'col' => 'col-12 col-md-6'],
        ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\EventRegistrationStatus::cases()), 'col' => 'col-6 col-md-3'],
    ],
]) ?>
<?php if ($paginator->isEmpty()): ?>
    <div class="panel"><?= $this->component('empty', ['title' => t('events.admin.no_registrations'), 'icon' => 'bi-card-checklist']) ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead><tr>
                <th scope="col"><?= e(t('users.cols.name')) ?></th>
                <th scope="col"><?= e(t('student.registrations.cols.event')) ?></th>
                <th scope="col"><?= e(t('events.admin.cols.date')) ?></th>
                <th scope="col"><?= e(t('admin.registrations.registered_at')) ?></th>
                <th scope="col"><?= e(t('events.admin.cols.status')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($paginator->items as $r): ?>
                <tr>
                    <td class="cell-main"><span class="cell-title"><?= e($r['full_name']) ?></span><div class="cell-sub" dir="ltr"><?= e($r['student_id'] ?? $r['email']) ?></div></td>
                    <td data-label="<?= e(t('student.registrations.cols.event')) ?>"><a href="<?= e(url('/admin/events/' . $r['event_id'])) ?>"><?= e(localized($r, 'title')) ?></a></td>
                    <td data-label="<?= e(t('events.admin.cols.date')) ?>"><?= e($d->date($r['start_datetime'])) ?></td>
                    <td data-label="<?= e(t('admin.registrations.registered_at')) ?>"><?= e($d->dateTime($r['registered_at'])) ?></td>
                    <td data-label="<?= e(t('events.admin.cols.status')) ?>"><?= $this->component('status', ['status' => \App\Domain\EventRegistrationStatus::from((string) $r['status'])]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
<?php endif; ?>
