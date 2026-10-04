<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters  @var array<int,string> $categories */
$this->layout('layouts/admin', ['title' => t('feedback.admin.title')]);
$d = dates();
$archived = $filters['archived'] === '1';
?>
<?= $this->insert('partials/admin-head', ['heading' => t('feedback.admin.title'), 'subheading' => t('feedback.admin.subtitle')]) ?>
<nav class="tabs mb-3" aria-label="<?= e(t('feedback.admin.title')) ?>">
    <a href="<?= e(url('/admin/feedback')) ?>"<?= !$archived ? ' aria-current="page"' : '' ?>><?= e(t('feedback.admin.inbox')) ?></a>
    <a href="<?= e(url('/admin/feedback', ['archived' => 1])) ?>"<?= $archived ? ' aria-current="page"' : '' ?>><?= e(t('feedback.admin.archived')) ?></a>
</nav>
<?= $this->component('filter-bar', [
    'action' => '/admin/feedback',
    'filters' => $filters,
    'hidden' => $archived ? ['archived' => '1'] : [],
    'fields' => [
        ['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search'), 'col' => 'col-12 col-lg-3'],
        ['name' => 'type', 'type' => 'select', 'label' => t('feedback.fields.type'), 'options' => enum_options(\App\Domain\FeedbackType::cases())],
        ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\FeedbackStatus::cases())],
        ['name' => 'category', 'type' => 'select', 'label' => t('feedback.fields.category'), 'options' => $categories],
        ['name' => 'from', 'type' => 'date', 'label' => t('common.filters.from')],
        ['name' => 'to', 'type' => 'date', 'label' => t('common.filters.to')],
    ],
]) ?>
<?php if ($paginator->isEmpty()): ?>
    <div class="panel"><?= $this->component('empty', ['title' => t('feedback.empty'), 'icon' => 'bi-chat-square-text']) ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead><tr>
                <th scope="col"><?= e(t('feedback.fields.subject')) ?></th>
                <th scope="col"><?= e(t('feedback.admin.cols.author')) ?></th>
                <th scope="col"><?= e(t('feedback.fields.type')) ?></th>
                <th scope="col"><?= e(t('feedback.admin.cols.date')) ?></th>
                <th scope="col"><?= e(t('common.filters.status')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($paginator->items as $f): ?>
                <tr>
                    <td class="cell-main"><a class="cell-title" href="<?= e(url('/admin/feedback/' . $f['id'])) ?>"><?= e($f['subject']) ?></a><div class="cell-sub"><?= e(localized($f, 'category_name')) ?></div></td>
                    <td data-label="<?= e(t('feedback.admin.cols.author')) ?>"><?= e((string) $f['author_name']) ?><div class="cell-sub" dir="ltr"><?= e((string) $f['author_email']) ?></div></td>
                    <td data-label="<?= e(t('feedback.fields.type')) ?>"><?= $this->component('status', ['status' => \App\Domain\FeedbackType::from((string) $f['type'])]) ?></td>
                    <td data-label="<?= e(t('feedback.admin.cols.date')) ?>"><?= e($d->date($f['created_at'])) ?></td>
                    <td data-label="<?= e(t('common.filters.status')) ?>"><?= $this->component('status', ['status' => \App\Domain\FeedbackStatus::from((string) $f['status'])]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
<?php endif; ?>
