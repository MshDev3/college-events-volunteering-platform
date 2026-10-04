<?php
/** @var \App\Core\Template $this  @var array<string,string> $values  @var string $aboutImage  @var bool $aboutImageIsCustom */
$this->layout('layouts/admin', ['title' => t('settings.title')]);
$v = static fn (string $k) => old($k, $values[$k] ?? '');
$maxMb = (int) round((int) config('uploads.max_bytes') / 1048576);
?>
<?= $this->insert('partials/admin-head', ['heading' => t('settings.title'), 'subheading' => t('settings.subtitle')]) ?>
<form class="panel narrow-lg" method="post" action="<?= e(url('/admin/settings')) ?>" enctype="multipart/form-data" novalidate>
    <div class="panel-head"><h2><?= e(t('settings.contact_section')) ?></h2></div>
    <div class="panel-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6"><?= $this->component('field', ['name' => 'contact_email', 'type' => 'email', 'label' => t('settings.fields.contact_email'), 'value' => $v('contact_email'), 'required' => true, 'attrs' => ['dir' => 'ltr']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'contact_email_2', 'type' => 'email', 'label' => t('settings.fields.contact_email_2'), 'value' => $v('contact_email_2'), 'attrs' => ['dir' => 'ltr']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'contact_phone', 'type' => 'tel', 'label' => t('settings.fields.contact_phone'), 'value' => $v('contact_phone'), 'required' => true, 'attrs' => ['dir' => 'ltr']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'contact_phone_2', 'type' => 'tel', 'label' => t('settings.fields.contact_phone_2'), 'value' => $v('contact_phone_2'), 'attrs' => ['dir' => 'ltr']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'address_ar', 'label' => t('settings.fields.address_ar'), 'value' => $v('address_ar'), 'help' => t('settings.address_help'), 'attrs' => ['lang' => 'ar', 'dir' => 'rtl']]) ?></div>
            <div class="col-md-6"><?= $this->component('field', ['name' => 'address_en', 'label' => t('settings.fields.address_en'), 'value' => $v('address_en'), 'help' => t('settings.address_help'), 'attrs' => ['lang' => 'en', 'dir' => 'ltr']]) ?></div>
        </div>
    </div>
    <div class="panel-head border-top"><h2><?= e(t('settings.about_section')) ?></h2></div>
    <div class="panel-body">
        <div class="row g-3 align-items-start">
            <div class="col-md-5"><img class="detail-cover ratio-img" src="<?= e(asset($aboutImage)) ?>" alt=""></div>
            <div class="col-md-7">
                <?= $this->component('field', ['name' => 'about_image', 'type' => 'file', 'label' => t('settings.fields.about_image'), 'help' => t('events.admin.image_help', ['max' => $maxMb]) . ' ' . t('settings.about_image_help'), 'attrs' => ['accept' => '.jpg,.jpeg,.png,.webp']]) ?>
                <?php if ($aboutImageIsCustom): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remove_about_image" name="remove_about_image" value="1">
                        <label class="form-check-label" for="remove_about_image"><?= e(t('settings.fields.remove_about_image')) ?></label>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(t('common.actions.save_changes')) ?></button>
    </div>
</form>
