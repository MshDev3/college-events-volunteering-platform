<?php
/** @var \App\Core\Template $this  @var array<string,mixed> $item */
$this->layout('layouts/app', ['title' => (string) $item['subject']]);
?>
<?= $this->insert('partials/page-head', [
    'heading' => (string) $item['subject'],
    'crumbs' => [[t('feedback.my_title'), '/student/feedback'], [(string) $item['subject'], null]],
]) ?>
<section class="section-tight">
    <div class="container narrow-lg">
        <?= $this->insert('partials/feedback-thread', ['item' => $item]) ?>
    </div>
</section>
