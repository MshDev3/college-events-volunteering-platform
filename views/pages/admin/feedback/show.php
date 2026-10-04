<?php
/** @var \App\Core\Template $this  @var array<string,mixed> $item */
$this->layout('layouts/admin', ['title' => (string) $item['subject']]);
$archived = $item['archived_at'] !== null;
?>
<?= $this->insert('partials/admin-head', ['heading' => (string) $item['subject'], 'back' => ['/admin/feedback', t('feedback.admin.title')]]) ?>
<div class="row g-4">
    <div class="col-xl-8"><?= $this->insert('partials/feedback-thread', ['item' => $item]) ?></div>
    <div class="col-xl-4">
        <div class="panel mb-3">
            <div class="panel-head"><h2><?= e(t('feedback.admin.reply')) ?></h2></div>
            <div class="panel-body">
                <form method="post" action="<?= e(url('/admin/feedback/' . $item['id'] . '/reply')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <?= $this->component('field', ['name' => 'admin_reply', 'type' => 'textarea', 'rows' => 6, 'label' => t('feedback.admin.reply_text'), 'value' => old('admin_reply', $item['admin_reply'] ?? ''), 'required' => true, 'help' => t('feedback.admin.reply_help'), 'attrs' => ['maxlength' => 5000]]) ?>
                    <?php $statusOptions = []; foreach (\App\Domain\FeedbackStatus::cases() as $c) { $statusOptions[$c->value] = t($c->labelKey()); } ?>
                    <?= $this->component('field', ['name' => 'status', 'id' => 'reply-status', 'type' => 'select', 'label' => t('feedback.admin.status_after'), 'value' => old('status', $item['status'] === 'OPEN' ? 'IN_PROGRESS' : $item['status']), 'options' => $statusOptions, 'required' => true]) ?>
                    <button class="btn btn-primary w-100" type="submit"><?= e(t('feedback.admin.send_reply')) ?></button>
                </form>
            </div>
        </div>
        <div class="panel mb-3">
            <div class="panel-head"><h2><?= e(t('feedback.admin.change_status')) ?></h2></div>
            <div class="panel-body d-flex flex-wrap gap-2">
                <?php foreach (\App\Domain\FeedbackStatus::cases() as $c): ?>
                    <form method="post" action="<?= e(url('/admin/feedback/' . $item['id'] . '/status')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="status" value="<?= e($c->value) ?>">
                        <button class="btn btn-sm <?= $item['status'] === $c->value ? 'btn-primary' : 'btn-light' ?>" type="submit"<?= $item['status'] === $c->value ? ' aria-pressed="true" disabled' : '' ?>><?= e(t($c->labelKey())) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
        <form method="post" action="<?= e(url('/admin/feedback/' . $item['id'] . '/archive')) ?>">
            <?= csrf_field() ?><input type="hidden" name="archive" value="<?= $archived ? '0' : '1' ?>">
            <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-archive me-1" aria-hidden="true"></i><?= e(t($archived ? 'feedback.admin.unarchive' : 'feedback.admin.archive')) ?></button>
        </form>
    </div>
</div>
