<?php
/** @var \App\Core\Template $this  @var array<string,int|float> $stats  @var list<array<string,mixed>> $categories  @var string $aboutImage */
$this->layout('layouts/app', ['title' => t('about.title')]);
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('about.title'),
    'subheading' => t('about.subtitle'),
    'crumbs' => [[t('about.title'), null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-7">
                <h2 class="h4"><?= e(t('about.mission_title')) ?></h2>
                <p class="measure"><?= e(t('about.mission')) ?></p>
                <h2 class="h4 mt-4"><?= e(t('about.offer_title')) ?></h2>
                <ul class="measure">
                    <li><?= e(t('about.offer.events')) ?></li>
                    <li><?= e(t('about.offer.volunteering')) ?></li>
                    <li><?= e(t('about.offer.facilities')) ?></li>
                    <li><?= e(t('about.offer.feedback')) ?></li>
                </ul>
                <p class="measure text-muted"><?= e(t('about.history')) ?></p>
            </div>
            <div class="col-lg-5">
                <img class="detail-cover ratio-img" src="<?= e(asset($aboutImage)) ?>" alt="<?= e(t('about.image_alt')) ?>">
            </div>
        </div>
        <div class="panel mt-5">
            <div class="figures">
                <div class="figure"><div class="n"><?= e(fmt_number($stats['upcoming_events'])) ?></div><div class="l"><?= e(t('home.stats.upcoming_events')) ?></div></div>
                <div class="figure"><div class="n"><?= e(fmt_number($stats['opportunities'])) ?></div><div class="l"><?= e(t('home.stats.opportunities')) ?></div></div>
                <div class="figure"><div class="n"><?= e(fmt_number($stats['volunteer_hours'], 1)) ?></div><div class="l"><?= e(t('home.stats.hours')) ?></div></div>
                <div class="figure"><div class="n"><?= e(fmt_number($stats['students'])) ?></div><div class="l"><?= e(t('home.stats.students')) ?></div></div>
            </div>
        </div>
    </div>
</section>
