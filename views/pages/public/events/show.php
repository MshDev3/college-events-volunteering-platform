<?php
/** @var \App\Core\Template $this  @var array<string,mixed> $event */
$title = localized($event, 'title');
$this->layout('layouts/app', ['title' => $title]);
$status = \App\Domain\ActivityStatus::from((string) $event['status']);
$d = dates();
?>
<?= $this->insert('partials/page-head', [
    'heading' => $title,
    'crumbs' => [[t('events.title'), '/events'], [$title, null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8">
                <img class="detail-cover mb-4" src="<?= e(asset(activity_image($event, 'event'))) ?>" alt="">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?= $this->component('status', ['status' => $status]) ?>
                    <span class="chip"><?= e(localized($event, 'type_name')) ?></span>
                </div>
                <?php if ($status === \App\Domain\ActivityStatus::CANCELLED): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong><?= e(t('events.cancelled_notice')) ?></strong>
                        <?php if (!empty($event['cancel_reason'])): ?><div class="mt-1"><?= e($event['cancel_reason']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <h2 class="h5"><?= e(t('events.about')) ?></h2>
                <div class="prose"><?= e(localized($event, 'description')) ?></div>
            </div>
            <aside class="col-lg-4">
                <div class="panel mb-3">
                    <div class="panel-body">
                        <ul class="fact-list">
                            <li><i class="bi bi-calendar3" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.date')) ?></span><span class="v"><?= e($d->fullDate($event['start_datetime'])) ?></span></span></li>
                            <li><i class="bi bi-clock" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.time')) ?></span><span class="v"><?= e($d->timeRange($event['start_datetime'], $event['end_datetime'])) ?></span></span></li>
                            <?php if (substr((string) $event['start_datetime'], 0, 10) !== substr((string) $event['end_datetime'], 0, 10)): ?>
                                <li><i class="bi bi-calendar-range" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.ends')) ?></span><span class="v"><?= e($d->dateTime($event['end_datetime'])) ?></span></span></li>
                            <?php endif; ?>
                            <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.location')) ?></span><span class="v"><?= e(localized($event, 'location')) ?></span></span></li>
                            <li><i class="bi bi-people" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.capacity')) ?></span><span class="v"><?= e(fmt_number($event['capacity'])) ?></span></span></li>
                        </ul>
                    </div>
                </div>
                <?= $this->insert('partials/activity-action', ['item' => $event, 'kind' => 'event']) ?>
            </aside>
        </div>
    </div>
</section>
