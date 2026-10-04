<?php
/** @var \App\Core\Template $this  @var list<array<string,string>> $messages */
$this->layout('layouts/app', ['title' => t('dev.mail.title')]);
?>
<?= $this->insert('partials/page-head', ['heading' => t('dev.mail.title'), 'subheading' => t('dev.mail.subtitle')]) ?>
<section class="section-tight">
    <div class="container">
        <?php if ($messages === []): ?>
            <?= $this->component('empty', ['title' => t('dev.mail.empty'), 'icon' => 'bi-envelope-open']) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead><tr><th><?= e(t('dev.mail.to')) ?></th><th><?= e(t('dev.mail.subject')) ?></th><th><?= e(t('dev.mail.sent')) ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($messages as $m): ?>
                        <tr>
                            <td data-label="<?= e(t('dev.mail.to')) ?>" dir="ltr"><?= e($m['to']) ?></td>
                            <td class="cell-main"><?= e($m['subject']) ?></td>
                            <td data-label="<?= e(t('dev.mail.sent')) ?>"><?= e(dates()->relative($m['sent_at'])) ?></td>
                            <td><a class="btn btn-sm btn-primary" href="<?= e(url('/_dev/mail/' . $m['id'])) ?>" target="_blank" rel="noopener"><?= e(t('dev.mail.open')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
