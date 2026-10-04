<?php /** @var string $heading  @var string|null $subheading  @var string|null $actions  @var array{0:string,1:string}|null $back */ ?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
    <div>
        <?php if (!empty($back)): ?>
            <a class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-1" href="<?= e(url($back[0])) ?>"><i class="bi bi-arrow-left flip-rtl" aria-hidden="true"></i><?= e($back[1]) ?></a>
        <?php endif; ?>
        <h1 class="h3 mb-0"><?= e($heading) ?></h1>
        <?php if (!empty($subheading)): ?><p class="text-muted mb-0 mt-1"><?= e($subheading) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($actions)): ?><div class="d-flex flex-wrap gap-2"><?= $actions ?></div><?php endif; ?>
</div>
