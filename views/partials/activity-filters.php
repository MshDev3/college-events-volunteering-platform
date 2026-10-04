<?php
/**
 * Public events / volunteering filters.
 * @var array<string,string> $filters  @var array<int,string> $lookupOptions
 * @var string $lookupName "type" | "category"  @var string $lookupLabel  @var string $action
 */
?>
<?= $this->component('filter-bar', [
    'action' => $action,
    'filters' => $filters,
    'fields' => [
        ['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search'), 'placeholder' => t('common.filters.search_placeholder')],
        ['name' => $lookupName, 'type' => 'select', 'label' => $lookupLabel, 'options' => $lookupOptions],
        ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\ActivityStatus::cases())],
        ['name' => 'from', 'type' => 'date', 'label' => t('common.filters.from')],
        ['name' => 'to', 'type' => 'date', 'label' => t('common.filters.to')],
        ['name' => 'location', 'type' => 'text', 'label' => t('common.filters.location'), 'col' => 'col-12 col-md-4 col-lg-4'],
    ],
]) ?>
