<?php
/** @var \App\Core\Template $this  @var list<array<string,mixed>> $facilities  @var int $selected  @var list<array<string,mixed>> $bookings */
$this->layout('layouts/app', ['title' => t('reservations.new')]);
$options = [];
foreach ($facilities as $f) {
    $options[$f['id']] = localized($f, 'name') . ($f['capacity'] !== null ? ' — ' . tc('facilities.capacity_people', (int) $f['capacity']) : '');
}
$d = dates();
$today = date('Y-m-d');
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('reservations.new'),
    'subheading' => t('reservations.new_subtitle'),
    'crumbs' => [[t('reservations.my_title'), '/student/reservations'], [t('reservations.new'), null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="panel">
                    <div class="panel-body">
                        <form method="post" action="<?= e(url('/student/reservations')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?= $this->component('field', ['name' => 'facility_id', 'type' => 'select', 'label' => t('reservations.fields.facility'), 'value' => old('facility_id', $selected ?: ''), 'options' => $options, 'placeholderOption' => t('common.forms.choose'), 'required' => true]) ?>
                            <div class="row g-3">
                                <div class="col-md-4"><?= $this->component('field', ['name' => 'date', 'type' => 'date', 'label' => t('reservations.fields.date'), 'value' => old('date'), 'required' => true, 'attrs' => ['min' => $today]]) ?></div>
                                <div class="col-6 col-md-4"><?= $this->component('field', ['name' => 'start_time', 'type' => 'time', 'label' => t('reservations.fields.start_time'), 'value' => old('start_time'), 'required' => true, 'attrs' => ['step' => 900]]) ?></div>
                                <div class="col-6 col-md-4"><?= $this->component('field', ['name' => 'end_time', 'type' => 'time', 'label' => t('reservations.fields.end_time'), 'value' => old('end_time'), 'required' => true, 'attrs' => ['step' => 900]]) ?></div>
                            </div>
                            <?= $this->component('field', ['name' => 'purpose', 'label' => t('reservations.fields.purpose'), 'value' => old('purpose'), 'required' => true, 'help' => t('reservations.help.purpose'), 'attrs' => ['maxlength' => 200]]) ?>
                            <?= $this->component('field', ['name' => 'expected_attendees', 'type' => 'number', 'label' => t('reservations.fields.attendees'), 'value' => old('expected_attendees'), 'attrs' => ['min' => 1, 'max' => 10000, 'inputmode' => 'numeric']]) ?>
                            <?= $this->component('field', ['name' => 'notes', 'type' => 'textarea', 'rows' => 3, 'label' => t('reservations.fields.notes'), 'value' => old('notes'), 'attrs' => ['maxlength' => 1000]]) ?>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" type="submit"><?= e(t('reservations.submit')) ?></button>
                                <a class="btn btn-light" href="<?= e(url('/student/reservations')) ?>"><?= e(t('common.actions.cancel')) ?></a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <aside class="col-lg-4">
                <div class="panel mb-3">
                    <div class="panel-body small">
                        <h2 class="h6"><?= e(t('reservations.rules_title')) ?></h2>
                        <ul class="mb-0 ps-3">
                            <li><?= e(t('reservations.rules.approval')) ?></li>
                            <li><?= e(t('reservations.rules.duration', ['hours' => \App\Services\ReservationService::MAX_DURATION_HOURS])) ?></li>
                            <li><?= e(t('reservations.rules.ahead', ['days' => \App\Services\ReservationService::MAX_DAYS_AHEAD])) ?></li>
                            <li><?= e(t('reservations.rules.conflict')) ?></li>
                        </ul>
                    </div>
                </div>
                <?php if ($selected > 0): ?>
                    <div class="panel">
                        <div class="panel-head"><h2><?= e(t('facilities.booked_slots')) ?></h2></div>
                        <?php if ($bookings === []): ?>
                            <p class="panel-body text-muted mb-0"><?= e(t('facilities.no_bookings')) ?></p>
                        <?php else: ?>
                            <ul class="list-plain">
                                <?php foreach ($bookings as $b): ?>
                                    <li class="list-row"><span class="grow"><span class="title"><?= e($d->fullDate($b['start_datetime'])) ?></span><span class="sub"><?= e($d->timeRange($b['start_datetime'], $b['end_datetime'])) ?></span></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>
