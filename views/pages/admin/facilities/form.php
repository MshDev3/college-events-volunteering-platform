<?php
/** @var \App\Core\Template $this  @var array<string,mixed>|null $item */
$editing = $item !== null;
$heading = $editing ? t('facilities.admin.edit') : t('facilities.admin.create');
$this->layout('layouts/admin', ['title' => $heading]);
$v = static fn (string $f, mixed $default = '') => old($f, $item[$f] ?? $default);
$maxMb = (int) round((int) config('uploads.max_bytes') / 1048576);
?>
<?= $this->insert('partials/admin-head', ['heading' => $heading, 'back' => ['/admin/facilities', t('facilities.admin.title')]]) ?>
<form class="panel" method="post" action="<?= e(url($editing ? '/admin/facilities/' . $item['id'] : '/admin/facilities')) ?>" enctype="multipart/form-data" novalidate>
    <div class="panel-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-4"><?= $this->component('field', ['name' => 'code', 'label' => t('facilities.admin.fields.code'), 'value' => $v('code'), 'required' => true, 'help' => t('facilities.admin.code_help'), 'attrs' => ['dir' => 'ltr', 'maxlength' => 40]]) ?></div>
            <div class="col-md-4"><?= $this->component('field', ['name' => 'capacity', 'type' => 'number', 'label' => t('events.fields.capacity'), 'value' => $v('capacity'), 'attrs' => ['min' => 1]]) ?></div>
            <div class="col-md-4 d-flex align-items-center">
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= $v('is_active', 1) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="is_active"><?= e(t('facilities.admin.fields.active')) ?></label>
                </div>
            </div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'name_ar', 'label' => t('facilities.admin.fields.name_ar'), 'value' => $v('name_ar'), 'required' => true, 'attrs' => ['lang' => 'ar', 'dir' => 'rtl']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'name_en', 'label' => t('facilities.admin.fields.name_en'), 'value' => $v('name_en'), 'required' => true, 'attrs' => ['lang' => 'en', 'dir' => 'ltr']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'location_ar', 'label' => t('events.admin.fields.location_ar'), 'value' => $v('location_ar'), 'required' => true, 'attrs' => ['lang' => 'ar', 'dir' => 'rtl']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'location_en', 'label' => t('events.admin.fields.location_en'), 'value' => $v('location_en'), 'required' => true, 'attrs' => ['lang' => 'en', 'dir' => 'ltr']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'description_ar', 'type' => 'textarea', 'label' => t('events.admin.fields.description_ar'), 'value' => $v('description_ar'), 'required' => true, 'attrs' => ['lang' => 'ar', 'dir' => 'rtl']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'description_en', 'type' => 'textarea', 'label' => t('events.admin.fields.description_en'), 'value' => $v('description_en'), 'required' => true, 'attrs' => ['lang' => 'en', 'dir' => 'ltr']]) ?></div>
            <div class="col-md-6">
                <?php if ($editing && !empty($item['image_path'])): ?>
                    <img class="detail-cover mb-2" src="<?= e(asset((string) $item['image_path'])) ?>" alt="">
                <?php endif; ?>
                <?= $this->component('field', ['name' => 'image', 'type' => 'file', 'label' => t('facilities.admin.fields.image'), 'help' => t('events.admin.image_help', ['max' => $maxMb]), 'attrs' => ['accept' => '.jpg,.jpeg,.png,.webp']]) ?>
                <?php if ($editing && !empty($item['image_path'])): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                        <label class="form-check-label" for="remove_image"><?= e(t('facilities.admin.fields.remove_image')) ?></label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= e(t('common.actions.save_changes')) ?></button>
            <a class="btn btn-light" href="<?= e(url('/admin/facilities')) ?>"><?= e(t('common.actions.cancel')) ?></a>
        </div>
    </div>
</form>
