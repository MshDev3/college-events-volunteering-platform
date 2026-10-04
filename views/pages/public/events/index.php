<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters  @var array<int,string> $types */
$this->layout('layouts/app', ['title' => t('events.title')]);
$filtered = array_filter($filters, static fn ($v) => $v !== '') !== [];
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('events.title'),
    'subheading' => t('events.subtitle'),
    'crumbs' => [[t('events.title'), null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <?= $this->insert('partials/activity-filters', [
            'filters' => $filters, 'lookupOptions' => $types, 'lookupName' => 'type',
            'lookupLabel' => t('events.fields.type'), 'action' => '/events',
        ]) ?>

        <?php if ($paginator->isEmpty()): ?>
            <?= $this->component('empty', [
                'title' => t('events.empty.title'),
                'text' => $filtered ? t('common.states.no_results') : t('events.empty.text'),
                'icon' => 'bi-calendar-x',
                'actionHref' => $filtered ? '/events' : null,
                'actionLabel' => t('common.filters.reset'),
            ]) ?>
        <?php else: ?>
            <div class="row g-3 g-lg-4">
                <?php foreach ($paginator->items as $event): ?>
                    <div class="col-md-6 col-lg-4"><?= $this->component('activity-card', ['item' => $event, 'kind' => 'event']) ?></div>
                <?php endforeach; ?>
            </div>
            <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
        <?php endif; ?>
    </div>
</section>
