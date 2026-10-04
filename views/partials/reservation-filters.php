<?php
/** @var array<string,string> $filters  @var list<array<string,mixed>> $facilities  @var string $action  @var bool $withSearch */
$facilityOptions = [];
foreach ($facilities as $f) {
    $facilityOptions[$f['id']] = localized($f, 'name');
}
$fields = $withSearch
    ? [['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search'), 'placeholder' => t('reservations.search_placeholder'), 'col' => 'col-12 col-lg-3']]
    : [];
$fields[] = ['name' => 'facility', 'type' => 'select', 'label' => t('reservations.cols.facility'), 'options' => $facilityOptions];
$fields[] = ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => enum_options(\App\Domain\ReservationStatus::cases())];
$fields[] = ['name' => 'from', 'type' => 'date', 'label' => t('common.filters.from')];
$fields[] = ['name' => 'to', 'type' => 'date', 'label' => t('common.filters.to')];
?>
<?= $this->component('filter-bar', ['action' => $action, 'filters' => $filters, 'fields' => $fields]) ?>
