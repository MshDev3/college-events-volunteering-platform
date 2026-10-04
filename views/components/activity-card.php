<?php
/**
 * Event or volunteer opportunity card.
 * @var array<string, mixed> $item   row from EventRepository / VolunteerRepository
 * @var string $kind                 'event' | 'volunteer'
 */
$status = \App\Domain\ActivityStatus::from((string) $item['status']);
$href = ($kind === 'event' ? '/events/' : '/volunteering/') . $item['id'];
$d = dates();
$typeLabel = localized($item, 'type_name');
?>
<article class="activity-card">
    <img class="cover" src="<?= e(asset(activity_image($item, $kind))) ?>" alt="" loading="lazy" width="1600" height="900">
    <div class="body">
        <?= $this->component('datebox', ['date' => $item['start_datetime'], 'status' => $status]) ?>
        <div class="min-w-0">
            <div class="d-flex flex-wrap gap-2 mb-2">
                <?= $this->component('status', ['status' => $status]) ?>
                <?php if ($typeLabel !== ''): ?><span class="chip"><?= e($typeLabel) ?></span><?php endif; ?>
            </div>
            <h3><a href="<?= e(url($href)) ?>"><?= e(localized($item, 'title')) ?></a></h3>
            <ul class="facts">
                <li><i class="bi bi-clock" aria-hidden="true"></i><span><?= e($d->timeRange($item['start_datetime'], $item['end_datetime'])) ?></span></li>
                <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span><?= e(localized($item, 'location')) ?></span></li>
                <?php if ($kind === 'volunteer'): ?>
                    <li><i class="bi bi-hourglass-split" aria-hidden="true"></i><span><?= e(tc('volunteering.hours_count', (float) $item['volunteer_hours'])) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="foot">
        <?= $this->component('meter', ['registered' => (int) $item['registered_count'], 'capacity' => (int) $item['capacity']]) ?>
        <?php if (!empty($item['my_status']) && $item['my_status'] !== 'CANCELLED'): ?>
            <span class="status status-success"><?= e(t('common.capacity.you_are_in')) ?></span>
        <?php endif; ?>
    </div>
</article>
