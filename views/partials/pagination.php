<?php
/** @var \App\Core\Paginator $paginator */
if ($paginator->total === 0) {
    return;
}
$page = $paginator->page;
$last = $paginator->lastPage;
$window = array_unique(array_filter([1, $page - 1, $page, $page + 1, $last], static fn ($p) => $p >= 1 && $p <= $last));
sort($window);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
    <p class="small text-muted mb-0" aria-live="polite">
        <?= e(t('common.pagination.showing', ['from' => $paginator->from(), 'to' => $paginator->to(), 'total' => $paginator->total])) ?>
    </p>
    <?php if ($last > 1): ?>
        <nav aria-label="<?= e(t('common.pagination.label')) ?>">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e(query_with(['page' => max(1, $page - 1)])) ?>" aria-label="<?= e(t('common.pagination.previous')) ?>"><i class="bi bi-chevron-left flip-rtl" aria-hidden="true"></i></a>
                </li>
                <?php $prev = 0; foreach ($window as $p): ?>
                    <?php if ($p - $prev > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= e(query_with(['page' => $p])) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= e($p) ?></a>
                    </li>
                <?php $prev = $p; endforeach; ?>
                <li class="page-item<?= $page >= $last ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e(query_with(['page' => min($last, $page + 1)])) ?>" aria-label="<?= e(t('common.pagination.next')) ?>"><i class="bi bi-chevron-right flip-rtl" aria-hidden="true"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
