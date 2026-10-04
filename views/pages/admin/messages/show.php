<?php
/** @var \App\Core\Template $this  @var array<string,mixed> $message */
$this->layout('layouts/admin', ['title' => (string) $message['subject']]);
$d = dates();
?>
<?= $this->insert('partials/admin-head', ['heading' => (string) $message['subject'], 'back' => ['/admin/messages', t('contact.admin.title')]]) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="panel">
            <div class="panel-head">
                <div><span class="fw-semibold"><?= e($message['name']) ?></span> <a class="small" dir="ltr" href="<?= e(mailto_href((string) $message['email'], 'Re: ' . $message['subject'])) ?>"><?= e($message['email']) ?></a></div>
                <span class="small text-muted"><?= e($d->dateTime($message['created_at'])) ?></span>
            </div>
            <div class="panel-body"><div class="prose"><?= e($message['message']) ?></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="panel">
            <div class="panel-head"><h2><?= e(t('common.filters.status')) ?></h2><?= $this->component('status', ['status' => \App\Domain\ContactStatus::from((string) $message['status'])]) ?></div>
            <div class="panel-body d-grid gap-2">
                <?php foreach (\App\Domain\ContactStatus::cases() as $c): ?>
                    <?php if ($c->value === $message['status']) { continue; } ?>
                    <form method="post" action="<?= e(url('/admin/messages/' . $message['id'] . '/status')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="status" value="<?= e($c->value) ?>">
                        <?php if ($c === \App\Domain\ContactStatus::UNREAD): ?><input type="hidden" name="return" value="list"><?php endif; ?>
                        <button class="btn btn-light w-100" type="submit"><?= e(t('contact.admin.mark_as', ['status' => t($c->labelKey())])) ?></button>
                    </form>
                <?php endforeach; ?>
                <a class="btn btn-primary" href="<?= e(mailto_href((string) $message['email'], 'Re: ' . $message['subject'])) ?>"><i class="bi bi-reply me-1" aria-hidden="true"></i><?= e(t('contact.admin.reply_by_email')) ?></a>
            </div>
        </div>
    </div>
</div>
