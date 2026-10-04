<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters */
$this->layout('layouts/admin', ['title' => t('contact.admin.title')]);
$d = dates();
?>
<?= $this->insert('partials/admin-head', ['heading' => t('contact.admin.title'), 'subheading' => t('contact.admin.subtitle')]) ?>
<?= $this->component('filter-bar', [
    'action' => '/admin/messages',
    'filters' => $filters,
    'fields' => [
        ['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search')],
        ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\ContactStatus::cases())],
        ['name' => 'from', 'type' => 'date', 'label' => t('common.filters.from')],
        ['name' => 'to', 'type' => 'date', 'label' => t('common.filters.to')],
    ],
]) ?>
<?php if ($paginator->isEmpty()): ?>
    <div class="panel"><?= $this->component('empty', ['title' => t('contact.admin.empty'), 'icon' => 'bi-envelope-open']) ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead><tr>
                <th scope="col"><?= e(t('contact.admin.cols.sender')) ?></th>
                <th scope="col"><?= e(t('contact.fields.subject')) ?></th>
                <th scope="col"><?= e(t('contact.admin.cols.date')) ?></th>
                <th scope="col"><?= e(t('common.filters.status')) ?></th>
                <th scope="col" class="text-end"><?= e(t('common.actions.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($paginator->items as $m): ?>
                <tr<?= $m['status'] === 'UNREAD' ? ' class="fw-semibold"' : '' ?>>
                    <td class="cell-main"><?= e($m['name']) ?><div class="cell-sub" dir="ltr"><?= e($m['email']) ?></div></td>
                    <td data-label="<?= e(t('contact.fields.subject')) ?>"><a href="<?= e(url('/admin/messages/' . $m['id'])) ?>"><?= e($m['subject']) ?></a></td>
                    <td data-label="<?= e(t('contact.admin.cols.date')) ?>"><?= e($d->dateTime($m['created_at'])) ?></td>
                    <td data-label="<?= e(t('common.filters.status')) ?>"><?= $this->component('status', ['status' => \App\Domain\ContactStatus::from((string) $m['status'])]) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('/admin/messages/' . $m['id'])) ?>"><?= e(t('common.actions.view')) ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
<?php endif; ?>
