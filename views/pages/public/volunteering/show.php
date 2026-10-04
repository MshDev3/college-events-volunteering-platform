<?php
/** @var \App\Core\Template $this  @var array<string,mixed> $opportunity */
$item = $opportunity;
$title = localized($item, 'title');
$this->layout('layouts/app', ['title' => $title]);
$status = \App\Domain\ActivityStatus::from((string) $item['status']);
$d = dates();
?>
<?= $this->insert('partials/page-head', [
    'heading' => $title,
    'crumbs' => [[t('volunteering.title'), '/volunteering'], [$title, null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8">
                <img class="detail-cover mb-4" src="<?= e(asset(activity_image($item, 'volunteer'))) ?>" alt="">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?= $this->component('status', ['status' => $status]) ?>
                    <span class="chip"><i class="bi <?= e($item['type_icon'] ?? 'bi-heart') ?> me-1" aria-hidden="true"></i><?= e(localized($item, 'type_name')) ?></span>
                </div>
                <?php if ($status === \App\Domain\ActivityStatus::CANCELLED): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong><?= e(t('volunteering.cancelled_notice')) ?></strong>
                        <?php if (!empty($item['cancel_reason'])): ?><div class="mt-1"><?= e($item['cancel_reason']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <h2 class="h5"><?= e(t('volunteering.about')) ?></h2>
                <div class="prose mb-4"><?= e(localized($item, 'description')) ?></div>
                <div class="alert alert-info d-flex gap-2" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div><?= e(t('volunteering.hours_policy')) ?></div>
                </div>
            </div>
            <aside class="col-lg-4">
                <div class="panel mb-3">
                    <div class="panel-body">
                        <ul class="fact-list">
                            <li><i class="bi bi-calendar3" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.date')) ?></span><span class="v"><?= e($d->fullDate($item['start_datetime'])) ?></span></span></li>
                            <li><i class="bi bi-clock" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.time')) ?></span><span class="v"><?= e($d->timeRange($item['start_datetime'], $item['end_datetime'])) ?></span></span></li>
                            <?php if (substr((string) $item['start_datetime'], 0, 10) !== substr((string) $item['end_datetime'], 0, 10)): ?>
                                <li><i class="bi bi-calendar-range" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.ends')) ?></span><span class="v"><?= e($d->dateTime($item['end_datetime'])) ?></span></span></li>
                            <?php endif; ?>
                            <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.location')) ?></span><span class="v"><?= e(localized($item, 'location')) ?></span></span></li>
                            <li><i class="bi bi-hourglass-split" aria-hidden="true"></i><span><span class="k"><?= e(t('volunteering.fields.hours')) ?></span><span class="v"><?= e(tc('volunteering.hours_count', (float) $item['volunteer_hours'])) ?></span></span></li>
                        </ul>
                    </div>
                </div>
                <?= $this->insert('partials/activity-action', ['item' => $item, 'kind' => 'volunteer']) ?>
            </aside>
        </div>
    </div>
</section>
