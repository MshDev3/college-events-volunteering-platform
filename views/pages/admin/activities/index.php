<?php
/**
 * Admin list for events and volunteering ($ns = "events" | "volunteering").
 * @var \App\Core\Template $this  @var string $ns  @var \App\Core\Paginator $paginator
 * @var array<string,string> $filters  @var array<int,string> $lookupOptions
 */
$this->layout('layouts/admin', ['title' => t("$ns.admin.title")]);
$d = dates();
?>
<?= $this->insert('partials/admin-head', [
    'heading' => t("$ns.admin.title"),
    'actions' => '<a class="btn btn-primary" href="' . e(url("/admin/$ns/create")) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>' . e(t("$ns.admin.create")) . '</a>',
]) ?>

<?= $this->component('filter-bar', [
    'action' => "/admin/$ns",
    'filters' => $filters,
    'fields' => [
        ['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search'), 'placeholder' => t('common.filters.search_placeholder')],
        ['name' => 'lookup', 'type' => 'select', 'label' => t($ns === 'events' ? 'events.fields.type' : 'volunteering.fields.category'), 'options' => $lookupOptions],
        ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\ActivityStatus::cases())],
        ['name' => 'from', 'type' => 'date', 'label' => t('common.filters.from')],
        ['name' => 'to', 'type' => 'date', 'label' => t('common.filters.to')],
    ],
]) ?>

<?php if ($paginator->isEmpty()): ?>
    <div class="panel"><?= $this->component('empty', ['title' => t("$ns.empty.title"), 'text' => t('common.states.no_results'), 'icon' => 'bi-calendar-x', 'actionHref' => "/admin/$ns/create", 'actionLabel' => t("$ns.admin.create")]) ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead><tr>
                <th scope="col"><?= e(t("$ns.admin.cols.title")) ?></th>
                <th scope="col"><?= e(t('events.admin.cols.date')) ?></th>
                <th scope="col"><?= e(t('events.admin.cols.location')) ?></th>
                <th scope="col"><?= e(t('events.admin.cols.registered')) ?></th>
                <th scope="col"><?= e(t('events.admin.cols.status')) ?></th>
                <th scope="col" class="text-end"><?= e(t('common.actions.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($paginator->items as $row): ?>
                <tr>
                    <td class="cell-main">
                        <a class="cell-title" href="<?= e(url("/admin/$ns/" . $row['id'])) ?>"><?= e(localized($row, 'title')) ?></a>
                        <div class="cell-sub"><?= e(localized($row, 'type_name')) ?></div>
                    </td>
                    <td data-label="<?= e(t('events.admin.cols.date')) ?>"><?= e($d->date($row['start_datetime'])) ?><div class="cell-sub"><?= e($d->timeRange($row['start_datetime'], $row['end_datetime'])) ?></div></td>
                    <td data-label="<?= e(t('events.admin.cols.location')) ?>"><?= e(localized($row, 'location')) ?></td>
                    <td data-label="<?= e(t('events.admin.cols.registered')) ?>"><span dir="ltr" class="d-inline-block"><strong><?= e(fmt_number($row['registered_count'])) ?></strong> / <?= e(fmt_number($row['capacity'])) ?></span></td>
                    <td data-label="<?= e(t('events.admin.cols.status')) ?>"><?= $this->component('status', ['status' => \App\Domain\ActivityStatus::from((string) $row['status'])]) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= e(url("/admin/$ns/" . $row['id'])) ?>"><?= e(t('common.actions.view')) ?></a>
                        <a class="btn btn-sm btn-light" href="<?= e(url("/admin/$ns/" . $row['id'] . '/edit')) ?>"><?= e(t('common.actions.edit')) ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
<?php endif; ?>
