<?php
/**
 * @var \App\Core\Template $this
 * @var list<array<string,mixed>> $nextEvents
 * @var list<array<string,mixed>> $events
 * @var list<array<string,mixed>> $opportunities
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $facilities
 * @var array<string,int|float> $stats
 */
$this->layout('layouts/app', ['title' => t('home.title')]);
$d = dates();
$user = auth_user();
?>

<section class="hero">
    <div class="container py-5 position-relative">
        <div class="row g-5 align-items-center py-lg-4">
            <div class="col-lg-6">
                <h1 class="mb-3"><?= e(t('home.hero.title')) ?></h1>
                <p class="lead mb-4"><?= e(t('home.hero.lead')) ?></p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-primary btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.hero.explore_events')) ?></a>
                    <a class="btn btn-outline-light btn-lg" href="<?= e(url('/volunteering')) ?>"><?= e(t('home.hero.volunteer')) ?></a>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="timetable" aria-labelledby="next-up">
                    <h2 class="timetable-title" id="next-up"><?= e(t('home.hero.next_up')) ?></h2>
                    <?php if ($nextEvents === []): ?>
                        <p class="timetable-empty mb-0"><?= e(t('home.hero.no_events')) ?></p>
                    <?php endif; ?>
                    <?php foreach ($nextEvents as $event): ?>
                        <a class="timetable-row" href="<?= e(url('/events/' . $event['id'])) ?>">
                            <?= $this->component('datebox', ['date' => $event['start_datetime']]) ?>
                            <span class="min-w-0">
                                <span class="d-block fw-semibold text-truncate"><?= e(localized($event, 'title')) ?></span>
                                <span class="d-block small text-muted"><?= e($d->timeRange($event['start_datetime'], $event['end_datetime'])) ?></span>
                                <span class="d-block small text-muted"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e(localized($event, 'location')) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="band" aria-label="<?= e(t('home.stats.label')) ?>">
    <div class="container">
        <div class="figures">
            <div class="figure"><div class="n"><?= e(fmt_number($stats['upcoming_events'])) ?></div><div class="l"><?= e(t('home.stats.upcoming_events')) ?></div></div>
            <div class="figure"><div class="n"><?= e(fmt_number($stats['opportunities'])) ?></div><div class="l"><?= e(t('home.stats.opportunities')) ?></div></div>
            <div class="figure"><div class="n"><?= e(fmt_number($stats['volunteer_hours'], 1)) ?></div><div class="l"><?= e(t('home.stats.hours')) ?></div></div>
            <div class="figure"><div class="n"><?= e(fmt_number($stats['students'])) ?></div><div class="l"><?= e(t('home.stats.students')) ?></div></div>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="events-heading">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 id="events-heading"><?= e(t('home.events.title')) ?></h2>
                <p><?= e(t('home.events.lead')) ?></p>
            </div>
            <a class="btn btn-light-teal" href="<?= e(url('/events')) ?>"><?= e(t('home.events.all')) ?></a>
        </div>
        <?php if ($events === []): ?>
            <?= $this->component('empty', ['title' => t('events.empty.title'), 'text' => t('events.empty.upcoming'), 'icon' => 'bi-calendar-x']) ?>
        <?php else: ?>
            <div class="row g-3 g-lg-4">
                <?php foreach ($events as $event): ?>
                    <div class="col-md-6 col-lg-4"><?= $this->component('activity-card', ['item' => $event, 'kind' => 'event']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section band" aria-labelledby="volunteer-heading">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 id="volunteer-heading"><?= e(t('home.volunteering.title')) ?></h2>
                <p><?= e(t('home.volunteering.lead')) ?></p>
            </div>
            <a class="btn btn-primary" href="<?= e(url('/volunteering')) ?>"><?= e(t('home.volunteering.all')) ?></a>
        </div>
        <ul class="list-plain row g-2 mb-4">
            <?php foreach ($categories as $category): ?>
                <li class="col-6 col-lg-3">
                    <a class="category-link" href="<?= e(url('/volunteering', ['category' => $category['id']])) ?>">
                        <img src="<?= e(asset('assets/img/volunteering/' . $category['code'] . '.png')) ?>" alt="" width="40" height="40">
                        <span><?= e(localized($category, 'name')) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($opportunities === []): ?>
            <?= $this->component('empty', ['title' => t('volunteering.empty.title'), 'text' => t('volunteering.empty.upcoming'), 'icon' => 'bi-heart']) ?>
        <?php else: ?>
            <div class="row g-3 g-lg-4">
                <?php foreach ($opportunities as $opportunity): ?>
                    <div class="col-md-6 col-lg-4"><?= $this->component('activity-card', ['item' => $opportunity, 'kind' => 'volunteer']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section" aria-labelledby="services-heading">
    <div class="container">
        <div class="section-head">
            <div>
                <h2 id="services-heading"><?= e(t('home.services.title')) ?></h2>
                <p><?= e(t('home.services.lead')) ?></p>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6 col-lg-3"><a class="service" href="<?= e(url('/facilities')) ?>"><span class="ico"><i class="bi bi-building" aria-hidden="true"></i></span><span><h3><?= e(t('home.services.facilities.title')) ?></h3><p><?= e(t('home.services.facilities.text')) ?></p></span></a></div>
            <div class="col-md-6 col-lg-3"><a class="service" href="<?= e(url('/volunteering')) ?>"><span class="ico"><i class="bi bi-heart" aria-hidden="true"></i></span><span><h3><?= e(t('home.services.volunteering.title')) ?></h3><p><?= e(t('home.services.volunteering.text')) ?></p></span></a></div>
            <div class="col-md-6 col-lg-3"><a class="service" href="<?= e(url('/student/feedback/new')) ?>"><span class="ico"><i class="bi bi-chat-square-text" aria-hidden="true"></i></span><span><h3><?= e(t('home.services.feedback.title')) ?></h3><p><?= e(t('home.services.feedback.text')) ?></p></span></a></div>
            <div class="col-md-6 col-lg-3"><a class="service" href="<?= e(url('/contact')) ?>"><span class="ico"><i class="bi bi-envelope" aria-hidden="true"></i></span><span><h3><?= e(t('home.services.contact.title')) ?></h3><p><?= e(t('home.services.contact.text')) ?></p></span></a></div>
        </div>

        <?php if ($facilities !== []): ?>
            <div class="row g-3 mt-2">
                <?php foreach ($facilities as $facility): ?>
                    <div class="col-6 col-lg-3">
                        <a class="d-block text-decoration-none" href="<?= e(url('/facilities/' . $facility['id'])) ?>">
                            <?php if (!empty($facility['image_path'])): ?>
                                <img class="detail-cover mb-2 ratio-img" src="<?= e(asset((string) $facility['image_path'])) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                            <span class="fw-semibold text-body"><?= e(localized($facility, 'name')) ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section band" aria-labelledby="how-heading">
    <div class="container">
        <h2 id="how-heading" class="mb-4"><?= e(t('home.how.title')) ?></h2>
        <ol class="steps">
            <?php foreach (['account', 'choose', 'register', 'track'] as $step): ?>
                <li>
                    <h3><?= e(t("home.how.steps.$step.title")) ?></h3>
                    <p><?= e(t("home.how.steps.$step.text")) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="cta d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
            <div>
                <h2 class="mb-2"><?= e(t($user === null ? 'home.cta.guest_title' : 'home.cta.user_title')) ?></h2>
                <p class="mb-0"><?= e(t($user === null ? 'home.cta.guest_text' : 'home.cta.user_text')) ?></p>
            </div>
            <?php if ($user === null): ?>
                <a class="btn btn-light btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('home.cta.guest_button')) ?></a>
            <?php else: ?>
                <a class="btn btn-light btn-lg" href="<?= e(url($user->role->homePath())) ?>"><?= e(t('home.cta.user_button')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
