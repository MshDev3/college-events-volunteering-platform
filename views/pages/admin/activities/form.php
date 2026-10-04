<?php
/**
 * Create / edit form for events and volunteering.
 * @var \App\Core\Template $this  @var string $ns  @var array<string,mixed>|null $item
 * @var string $lookupField  @var array<int,string> $lookupOptions
 */
$editing = $item !== null;
$heading = $editing ? t("$ns.admin.edit_title") : t("$ns.admin.create");
$this->layout('layouts/admin', ['title' => $heading]);
$v = static fn (string $field, mixed $default = '') => old($field, $item[$field] ?? $default);
$dt = static fn (string $field) => old($field, \App\Support\DateFormatter::toInput($item[$field] ?? null));
$isVolunteer = $ns === 'volunteering';
$action = $editing ? "/admin/$ns/" . $item['id'] : "/admin/$ns";
$maxMb = (int) round((int) config('uploads.max_bytes') / 1048576);
?>
<?= $this->insert('partials/admin-head', [
    'heading' => $heading,
    'back' => [$editing ? "/admin/$ns/" . $item['id'] : "/admin/$ns", $editing ? localized($item, 'title') : t("$ns.admin.title")],
]) ?>

<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="panel mb-4">
                <div class="panel-head"><h2><?= e(t('events.admin.section_content')) ?></h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6"><?= $this->component('field', ['name' => 'title_ar', 'label' => t('events.admin.fields.title_ar'), 'value' => $v('title_ar'), 'required' => true, 'attrs' => ['lang' => 'ar', 'dir' => 'rtl', 'maxlength' => 200]]) ?></div>
                        <div class="col-md-6"><?= $this->component('field', ['name' => 'title_en', 'label' => t('events.admin.fields.title_en'), 'value' => $v('title_en'), 'required' => true, 'attrs' => ['lang' => 'en', 'dir' => 'ltr', 'maxlength' => 200]]) ?></div>
                        <div class="col-md-6"><?= $this->component('field', ['name' => 'description_ar', 'type' => 'textarea', 'rows' => 6, 'label' => t('events.admin.fields.description_ar'), 'value' => $v('description_ar'), 'required' => true, 'attrs' => ['lang' => 'ar', 'dir' => 'rtl', 'maxlength' => 5000]]) ?></div>
                        <div class="col-md-6"><?= $this->component('field', ['name' => 'description_en', 'type' => 'textarea', 'rows' => 6, 'label' => t('events.admin.fields.description_en'), 'value' => $v('description_en'), 'required' => true, 'attrs' => ['lang' => 'en', 'dir' => 'ltr', 'maxlength' => 5000]]) ?></div>
                        <div class="col-md-6"><?= $this->component('field', ['name' => 'location_ar', 'label' => t('events.admin.fields.location_ar'), 'value' => $v('location_ar'), 'required' => true, 'attrs' => ['lang' => 'ar', 'dir' => 'rtl', 'maxlength' => 200]]) ?></div>
                        <div class="col-md-6"><?= $this->component('field', ['name' => 'location_en', 'label' => t('events.admin.fields.location_en'), 'value' => $v('location_en'), 'required' => true, 'attrs' => ['lang' => 'en', 'dir' => 'ltr', 'maxlength' => 200]]) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="panel mb-4">
                <div class="panel-head"><h2><?= e(t('events.admin.section_schedule')) ?></h2></div>
                <div class="panel-body">
                    <?= $this->component('field', ['name' => $lookupField, 'type' => 'select', 'label' => t($isVolunteer ? 'volunteering.fields.category' : 'events.fields.type'), 'value' => $v($lookupField), 'options' => $lookupOptions, 'placeholderOption' => t('common.forms.choose'), 'required' => true]) ?>
                    <?= $this->component('field', ['name' => 'start_datetime', 'type' => 'datetime-local', 'label' => t('events.admin.fields.start'), 'value' => $dt('start_datetime'), 'required' => true, 'attrs' => ['data-starts' => 'f-end_datetime']]) ?>
                    <?= $this->component('field', ['name' => 'end_datetime', 'type' => 'datetime-local', 'label' => t('events.admin.fields.end'), 'value' => $dt('end_datetime'), 'required' => true]) ?>
                    <?= $this->component('field', ['name' => 'capacity', 'type' => 'number', 'label' => t('events.fields.capacity'), 'value' => $v('capacity'), 'required' => true, 'help' => $editing ? t('events.admin.capacity_help', ['count' => (int) $item['registered_count']]) : null, 'attrs' => ['min' => max(1, (int) ($item['registered_count'] ?? 1)), 'max' => 100000, 'inputmode' => 'numeric']]) ?>
                    <?php if ($isVolunteer): ?>
                        <?= $this->component('field', ['name' => 'volunteer_hours', 'type' => 'number', 'label' => t('volunteering.fields.hours'), 'value' => $v('volunteer_hours'), 'required' => true, 'help' => t('volunteering.admin.hours_help'), 'attrs' => ['min' => 0, 'max' => 200, 'step' => 0.5]]) ?>
                    <?php endif; ?>
                    <?php if ($editing): ?>
                        <img class="detail-cover mb-2" src="<?= e(asset(activity_image($item, $isVolunteer ? 'volunteer' : 'event'))) ?>" alt="">
                    <?php endif; ?>
                    <?= $this->component('field', ['name' => 'image', 'type' => 'file', 'label' => t('events.admin.fields.image'), 'help' => t('events.admin.image_help', ['max' => $maxMb]) . ' ' . t('events.admin.image_default_help'), 'attrs' => ['accept' => '.jpg,.jpeg,.png,.webp']]) ?>
                    <?php if ($editing && !empty($item['image_path'])): ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                            <label class="form-check-label" for="remove_image"><?= e(t('events.admin.fields.remove_image')) ?></label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-primary btn-lg" type="submit"><?= e($editing ? t('common.actions.save_changes') : t("$ns.admin.create")) ?></button>
                <a class="btn btn-light" href="<?= e(url($editing ? "/admin/$ns/" . $item['id'] : "/admin/$ns")) ?>"><?= e(t('common.actions.cancel')) ?></a>
            </div>
            <?php if ($editing): ?><p class="small text-muted mt-3"><?= e(t('events.admin.notify_help')) ?></p><?php endif; ?>
        </div>
    </div>
</form>
