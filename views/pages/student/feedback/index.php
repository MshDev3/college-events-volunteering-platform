<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters */
$this->layout('layouts/app', ['title' => t('feedback.my_title')]);
$d = dates();
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('feedback.my_title'),
    'subheading' => t('feedback.my_subtitle'),
    'actions' => '<a class="btn btn-primary" href="' . e(url('/student/feedback/new')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>' . e(t('feedback.new')) . '</a>',
]) ?>
<?= $this->insert('partials/student-nav') ?>
<section class="section-tight">
    <div class="container">
        <?= $this->component('filter-bar', [
            'action' => '/student/feedback',
            'filters' => $filters,
            'fields' => [
                ['name' => 'type', 'type' => 'select', 'label' => t('feedback.fields.type'), 'options' => enum_options(\App\Domain\FeedbackType::cases()), 'col' => 'col-6 col-md-3'],
                ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\FeedbackStatus::cases()), 'col' => 'col-6 col-md-3'],
            ],
        ]) ?>

        <?php if ($paginator->isEmpty()): ?>
            <div class="panel"><?= $this->component('empty', ['title' => t('feedback.empty'), 'text' => t('feedback.empty_text'), 'icon' => 'bi-chat-square-text', 'actionHref' => '/student/feedback/new', 'actionLabel' => t('feedback.new')]) ?></div>
        <?php else: ?>
            <div class="panel">
                <ul class="list-plain">
                    <?php foreach ($paginator->items as $f): ?>
                        <li class="list-row">
                            <span class="grow">
                                <a class="title" href="<?= e(url('/student/feedback/' . $f['id'])) ?>"><?= e($f['subject']) ?></a>
                                <span class="sub"><?= e(localized($f, 'category_name')) ?> — <?= e($d->date($f['created_at'])) ?><?= !empty($f['admin_reply']) ? ' — ' . e(t('feedback.has_reply')) : '' ?></span>
                            </span>
                            <span class="d-flex flex-wrap gap-1 justify-content-end">
                                <?= $this->component('status', ['status' => \App\Domain\FeedbackType::from((string) $f['type'])]) ?>
                                <?= $this->component('status', ['status' => \App\Domain\FeedbackStatus::from((string) $f['status'])]) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
        <?php endif; ?>
    </div>
</section>
