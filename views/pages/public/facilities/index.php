<?php
/** @var \App\Core\Template $this  @var list<array<string,mixed>> $facilities */
$this->layout('layouts/app', ['title' => t('facilities.title')]);
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('facilities.title'),
    'subheading' => t('facilities.subtitle'),
    'crumbs' => [[t('facilities.title'), null]],
]) ?>
<section class="section-tight">
    <div class="container">
        <?php if ($facilities === []): ?>
            <?= $this->component('empty', ['title' => t('facilities.empty'), 'icon' => 'bi-building']) ?>
        <?php else: ?>
            <div class="row g-3 g-lg-4">
                <?php foreach ($facilities as $facility): ?>
                    <div class="col-md-6 col-lg-3">
                        <article class="activity-card">
                            <?php if (!empty($facility['image_path'])): ?>
                                <img class="cover" src="<?= e(asset((string) $facility['image_path'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="cover-fallback" aria-hidden="true"><i class="bi bi-building"></i></div>
                            <?php endif; ?>
                            <div class="p-3 flex-grow-1">
                                <h3 class="h6 mb-1"><a href="<?= e(url('/facilities/' . $facility['id'])) ?>"><?= e(localized($facility, 'name')) ?></a></h3>
                                <p class="small text-muted mb-2"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= e(localized($facility, 'location')) ?></p>
                                <?php if ($facility['capacity'] !== null): ?>
                                    <span class="chip"><?= e(tc('facilities.capacity_people', (int) $facility['capacity'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
