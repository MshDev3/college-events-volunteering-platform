<?php
/** @var int $registered  @var int $capacity */
$capacity = max(1, (int) $capacity);
$registered = (int) $registered;
$ratio = $registered / $capacity;
$pct = (int) (min(100, round($ratio * 20) * 5)); // nearest 5%
$state = $registered >= $capacity ? ' is-full' : ($ratio >= 0.8 ? ' is-almost' : '');
$remaining = max(0, $capacity - $registered);
?>
<div class="meter<?= $state ?>">
    <div class="meter-label">
        <span><strong dir="ltr" class="d-inline-block"><?= e(fmt_number($registered)) ?> / <?= e(fmt_number($capacity)) ?></strong> <?= e(t('common.capacity.registered')) ?></span>
        <span><?= e($remaining === 0 ? t('common.capacity.full') : tc('common.capacity.seats_left', $remaining)) ?></span>
    </div>
    <div class="meter-track" role="progressbar" aria-valuemin="0" aria-valuemax="<?= e($capacity) ?>" aria-valuenow="<?= e($registered) ?>" aria-label="<?= e(t('common.capacity.label')) ?>">
        <div class="meter-fill pct-<?= e($pct) ?>"></div>
    </div>
</div>
