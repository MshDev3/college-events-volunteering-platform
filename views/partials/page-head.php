<?php
/**
 * @var string $heading
 * @var string|null $subheading
 * @var list<array{0:string,1:?string}> $crumbs  [label, href|null]
 * @var string|null $actions  pre-rendered HTML for the right-hand side
 */
$crumbs ??= [];
?>
<div class="page-head">
    <div class="container">
        <?php if ($crumbs !== []): ?>
            <nav aria-label="<?= e(t('common.a11y.breadcrumb')) ?>">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>"><?= e(t('common.nav.home')) ?></a></li>
                    <?php foreach ($crumbs as $i => [$label, $href]): ?>
                        <?php if ($href !== null && $i < count($crumbs) - 1): ?>
                            <li class="breadcrumb-item"><a href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
                        <?php else: ?>
                            <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
            <div>
                <h1><?= e($heading) ?></h1>
                <?php if (!empty($subheading)): ?><p><?= e($subheading) ?></p><?php endif; ?>
            </div>
            <?php if (!empty($actions)): ?><div class="d-flex gap-2 flex-wrap"><?= $actions ?></div><?php endif; ?>
        </div>
    </div>
</div>
