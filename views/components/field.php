<?php
/**
 * Labelled form control with validation state.
 * @var string $name
 * @var string $label
 * @var string|null $type        text|email|password|tel|number|date|datetime-local|textarea|select|file
 * @var mixed $value
 * @var bool|null $required
 * @var string|null $help
 * @var array<string|int, string>|null $options   for select: value => label
 * @var array<string, string|int|bool>|null $attrs extra attributes
 * @var string|null $placeholderOption            for select
 */
$type ??= 'text';
$help ??= null;
$value ??= null;
$id = $id ?? 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$err = error($name);
$attrs ??= [];
$required = !empty($required);
$describedBy = trim(($help ? "$id-help " : '') . ($err ? "$id-error" : ''));
$attrHtml = '';
foreach ($attrs as $k => $v) {
    if ($v === false || $v === null) {
        continue;
    }
    $attrHtml .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
}
$common = 'id="' . e($id) . '" name="' . e($name) . '"' . ($required ? ' required' : '')
    . ($err ? ' aria-invalid="true"' : '') . ($describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '') . $attrHtml;
$class = ($type === 'select' ? 'form-select' : 'form-control') . ($err ? ' is-invalid' : '');
?>
<div class="<?= e($wrapperClass ?? 'mb-3') ?>">
    <label class="form-label<?= $required ? ' required' : '' ?>" for="<?= e($id) ?>"><?= e($label) ?></label>
    <?php if ($type === 'textarea'): ?>
        <textarea class="<?= $class ?>" <?= $common ?> rows="<?= e($rows ?? 5) ?>"><?= e($value ?? '') ?></textarea>
    <?php elseif ($type === 'select'): ?>
        <select class="<?= $class ?>" <?= $common ?>>
            <?php if (isset($placeholderOption)): ?><option value=""><?= e($placeholderOption) ?></option><?php endif; ?>
            <?php foreach ($options ?? [] as $optValue => $optLabel): ?>
                <option value="<?= e($optValue) ?>"<?= (string) $optValue === (string) ($value ?? '') ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($type === 'password'): ?>
        <div class="input-affix">
            <input class="<?= $class ?>" type="password" <?= $common ?>>
            <button class="affix-btn" type="button" data-toggle-password="<?= e($id) ?>" aria-pressed="false" aria-label="<?= e(t('common.actions.show_password')) ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
        </div>
    <?php else: ?>
        <input class="<?= $class ?>" type="<?= e($type) ?>" <?= $common ?><?= $type !== 'file' ? ' value="' . e($value ?? '') . '"' : '' ?>>
    <?php endif; ?>
    <?php if ($err): ?><div class="invalid-feedback d-block" id="<?= e($id) ?>-error"><?= e($err) ?></div><?php endif; ?>
    <?php if ($help): ?><div class="form-text" id="<?= e($id) ?>-help"><?= e($help) ?></div><?php endif; ?>
</div>
