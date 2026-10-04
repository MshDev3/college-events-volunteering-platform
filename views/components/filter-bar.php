<?php
/**
 * GET filter form used by every list page (works without JavaScript; selects/dates auto-apply with JS).
 *
 * @var string $action                      list page path
 * @var array<string, string> $filters      current values
 * @var list<array{name:string, type:string, label:string, options?:array<string|int,string>, placeholder?:string, col?:string}> $fields
 *      type: search | text | select | date
 * @var array<string, string>|null $hidden  extra hidden inputs to keep (e.g. archived=1)
 */
$hidden ??= [];
$uid = 'fb-' . substr(md5($action), 0, 6);
?>
<form class="filters mb-3" method="get" action="<?= e(url($action)) ?>" role="search" data-autosubmit>
    <?php foreach ($hidden as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
    <div class="row g-2 align-items-end">
        <?php foreach ($fields as $field): ?>
            <?php
            $id = $uid . '-' . $field['name'];
            $value = (string) ($filters[$field['name']] ?? '');
            $col = $field['col'] ?? ($field['type'] === 'search' ? 'col-12 col-lg-4' : 'col-6 col-md-3 col-lg-2');
            ?>
            <div class="<?= e($col) ?>">
                <label class="form-label small" for="<?= e($id) ?>"><?= e($field['label']) ?></label>
                <?php if ($field['type'] === 'select'): ?>
                    <select class="form-select" id="<?= e($id) ?>" name="<?= e($field['name']) ?>">
                        <option value=""><?= e(t('common.filters.all')) ?></option>
                        <?php foreach ($field['options'] ?? [] as $optValue => $optLabel): ?>
                            <option value="<?= e($optValue) ?>"<?= (string) $optValue === $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field['type'] === 'date'): ?>
                    <?php /* Native date input without JS; app.js upgrades it to flatpickr (submits Y-m-d, shows d/m/Y). */ ?>
                    <input class="form-control" type="date" id="<?= e($id) ?>" name="<?= e($field['name']) ?>" value="<?= e($value) ?>" lang="<?= e(locale()) ?>" placeholder="<?= e(t('common.filters.date_placeholder')) ?>" data-datepicker>
                <?php else: ?>
                    <input class="form-control" type="<?= e($field['type']) ?>" id="<?= e($id) ?>" name="<?= e($field['name']) ?>" value="<?= e($value) ?>"<?= isset($field['placeholder']) ? ' placeholder="' . e($field['placeholder']) . '"' : '' ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="col-12 col-lg d-flex gap-2 justify-content-lg-end">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i><?= e(t('common.filters.apply')) ?></button>
            <a class="btn btn-light" href="<?= e(url($action, $hidden)) ?>"><?= e(t('common.filters.reset')) ?></a>
        </div>
    </div>
</form>
