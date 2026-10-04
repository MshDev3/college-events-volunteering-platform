<?php
/** @var \App\Core\Template $this  @var array<string,mixed> $facility  @var list<array<string,mixed>> $bookings */
$name = localized($facility, 'name');
$this->layout('layouts/app', ['title' => $name]);
$d = dates();
$user = auth_user();
?>
<?= $this->insert('partials/page-head', [
    'heading' => $name,
    'crumbs' => [[t('facilities.title'), '/facilities'], [$name, null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-7">
                <?php if (!empty($facility['image_path'])): ?>
                    <img class="detail-cover mb-4" src="<?= e(asset((string) $facility['image_path'])) ?>" alt="">
                <?php endif; ?>
                <div class="prose mb-4"><?= e(localized($facility, 'description')) ?></div>
                <ul class="fact-list">
                    <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.location')) ?></span><span class="v"><?= e(localized($facility, 'location')) ?></span></span></li>
                    <?php if ($facility['capacity'] !== null): ?>
                        <li><i class="bi bi-people" aria-hidden="true"></i><span><span class="k"><?= e(t('events.fields.capacity')) ?></span><span class="v"><?= e(tc('facilities.capacity_people', (int) $facility['capacity'])) ?></span></span></li>
                    <?php endif; ?>
                </ul>
            </div>
            <aside class="col-lg-5">
                <div class="panel mb-3">
                    <div class="panel-head"><h2><?= e(t('facilities.booked_slots')) ?></h2></div>
                    <?php if ($bookings === []): ?>
                        <p class="panel-body text-muted mb-0"><?= e(t('facilities.no_bookings')) ?></p>
                    <?php else: ?>
                        <ul class="list-plain">
                            <?php foreach ($bookings as $b): ?>
                                <li class="list-row">
                                    <?= $this->component('datebox', ['date' => $b['start_datetime']]) ?>
                                    <span class="grow"><span class="title"><?= e($d->timeRange($b['start_datetime'], $b['end_datetime'])) ?></span><span class="sub"><?= e(t('facilities.reserved')) ?></span></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php if ($user === null): ?>
                    <a class="btn btn-primary btn-lg w-100" href="<?= e(url('/login', ['next' => '/student/reservations/new?facility=' . $facility['id']])) ?>"><?= e(t('facilities.login_to_book')) ?></a>
                <?php elseif ($user->isStudent()): ?>
                    <a class="btn btn-primary btn-lg w-100" href="<?= e(url('/student/reservations/new', ['facility' => $facility['id']])) ?>"><?= e(t('facilities.book')) ?></a>
                <?php else: ?>
                    <a class="btn btn-light-teal w-100" href="<?= e(url('/admin/reservations', ['facility' => $facility['id']])) ?>"><?= e(t('facilities.admin_view')) ?></a>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</section>
