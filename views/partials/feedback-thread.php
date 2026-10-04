<?php
/** Shared by the student and admin feedback detail pages. @var array<string,mixed> $item */
$d = dates();
?>
<div class="panel mb-3">
    <div class="panel-head">
        <div class="d-flex flex-wrap gap-2">
            <?= $this->component('status', ['status' => \App\Domain\FeedbackType::from((string) $item['type'])]) ?>
            <?= $this->component('status', ['status' => \App\Domain\FeedbackStatus::from((string) $item['status'])]) ?>
            <span class="chip"><?= e(localized($item, 'category_name')) ?></span>
        </div>
        <span class="small text-muted"><?= e($d->dateTime($item['created_at'])) ?></span>
    </div>
    <div class="panel-body">
        <p class="small text-muted mb-2"><?= e(t('feedback.by', ['name' => (string) $item['author_name']])) ?></p>
        <div class="prose"><?= e($item['message']) ?></div>
        <?php if (!empty($item['attachments'])): ?>
            <h3 class="h6 mt-4"><?= e(t('feedback.fields.attachment')) ?></h3>
            <ul class="list-unstyled mb-0">
                <?php foreach ($item['attachments'] as $a): ?>
                    <li><a href="<?= e(url('/attachments/' . $a['id'])) ?>"><i class="bi bi-paperclip me-1" aria-hidden="true"></i><?= e($a['original_name']) ?></a>
                        <span class="small text-muted">(<?= e(fmt_number($a['size_bytes'] / 1024)) ?> <?= e(t('common.units.kb')) ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<div class="panel">
    <div class="panel-head"><h2><?= e(t('feedback.reply_title')) ?></h2>
        <?php if (!empty($item['replied_at'])): ?><span class="small text-muted"><?= e($d->dateTime($item['replied_at'])) ?></span><?php endif; ?></div>
    <div class="panel-body">
        <?php if (empty($item['admin_reply'])): ?>
            <p class="text-muted mb-0"><?= e(t('feedback.no_reply')) ?></p>
        <?php else: ?>
            <div class="prose"><?= e($item['admin_reply']) ?></div>
            <?php if (!empty($item['replied_by_name'])): ?><p class="small text-muted mt-2 mb-0"><?= e(t('feedback.replied_by', ['name' => (string) $item['replied_by_name']])) ?></p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
