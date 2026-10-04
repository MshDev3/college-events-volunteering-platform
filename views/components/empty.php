<?php
/** @var string $title  @var string|null $text  @var string|null $icon  @var string|null $actionHref  @var string|null $actionLabel */
?>
<div class="empty">
    <i class="bi <?= e($icon ?? 'bi-inbox') ?> ico" aria-hidden="true"></i>
    <h3><?= e($title) ?></h3>
    <?php if (!empty($text)): ?><p class="mb-3"><?= e($text) ?></p><?php endif; ?>
    <?php if (!empty($actionHref)): ?><a class="btn btn-primary" href="<?= e(url($actionHref)) ?>"><?= e($actionLabel ?? '') ?></a><?php endif; ?>
</div>
