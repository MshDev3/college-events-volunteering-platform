<?php
/** @var string $date  @var \App\Domain\ActivityStatus|null $status */
$status ??= null;
$class = match ($status) {
    \App\Domain\ActivityStatus::CANCELLED => ' is-cancelled',
    \App\Domain\ActivityStatus::COMPLETED => ' is-muted',
    default => '',
};
$d = dates();
?>
<time class="datebox<?= $class ?>" datetime="<?= e(\App\Support\DateFormatter::parse($date)->format('Y-m-d\TH:i')) ?>">
    <span class="d"><?= e(\App\Support\DateFormatter::parse($date)->format('j')) ?></span>
    <span class="m"><?= e($d->monthName($date, true)) ?></span>
    <span class="w"><?= e($d->dayName($date)) ?></span>
</time>
