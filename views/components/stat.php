<?php /** @var string $label  @var int|float|string $value  @var string $icon  @var string|null $tone  @var string|null $href */ ?>
<?php $tag = !empty($href) ? 'a' : 'div'; ?>
<<?= $tag ?> class="stat"<?= !empty($href) ? ' href="' . e(url($href)) . '"' : '' ?>>
    <span class="ico <?= e($tone ?? '') ?>" aria-hidden="true"><i class="bi <?= e($icon) ?>"></i></span>
    <span>
        <span class="n d-block"><?= e(is_numeric($value) ? fmt_number($value, 1) : $value) ?></span>
        <span class="l d-block"><?= e($label) ?></span>
    </span>
</<?= $tag ?>>
