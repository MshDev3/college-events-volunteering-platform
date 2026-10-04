<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters  @var array<int,string> $categories */
$this->layout('layouts/app', ['title' => t('volunteering.title')]);
$filtered = array_filter($filters, static fn ($v) => $v !== '') !== [];
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('volunteering.title'),
    'subheading' => t('volunteering.subtitle'),
    'crumbs' => [[t('volunteering.title'), null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <?= $this->insert('partials/activity-filters', [
            'filters' => $filters, 'lookupOptions' => $categories, 'lookupName' => 'category',
            'lookupLabel' => t('volunteering.fields.category'), 'action' => '/volunteering',
        ]) ?>

        <?php if ($paginator->isEmpty()): ?>
            <?= $this->component('empty', [
                'title' => t('volunteering.empty.title'),
                'text' => $filtered ? t('common.states.no_results') : t('volunteering.empty.text'),
                'icon' => 'bi-heart',
                'actionHref' => $filtered ? '/volunteering' : null,
                'actionLabel' => t('common.filters.reset'),
            ]) ?>
        <?php else: ?>
            <div class="row g-3 g-lg-4">
                <?php foreach ($paginator->items as $item): ?>
                    <div class="col-md-6 col-lg-4"><?= $this->component('activity-card', ['item' => $item, 'kind' => 'volunteer']) ?></div>
                <?php endforeach; ?>
            </div>
            <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
        <?php endif; ?>
    </div>
</section>
