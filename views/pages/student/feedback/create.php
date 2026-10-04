<?php
/** @var \App\Core\Template $this  @var array<int,string> $categories  @var string $type */
$this->layout('layouts/app', ['title' => t('feedback.new')]);
$selectedType = (string) old('type', $type !== '' ? $type : 'SUGGESTION');
$maxMb = (int) round((int) config('uploads.max_bytes') / 1048576);
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('feedback.new'),
    'subheading' => t('feedback.new_subtitle'),
    'crumbs' => [[t('feedback.my_title'), '/student/feedback'], [t('feedback.new'), null]],
]) ?>
<section class="section-tight">
    <div class="container narrow-lg">
        <div class="panel">
            <div class="panel-body">
                <form method="post" action="<?= e(url('/student/feedback')) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6 required"><?= e(t('feedback.fields.type')) ?></legend>
                        <div class="segmented">
                            <input type="radio" name="type" id="type-suggestion" value="SUGGESTION"<?= $selectedType === 'SUGGESTION' ? ' checked' : '' ?>>
                            <label for="type-suggestion"><i class="bi bi-lightbulb" aria-hidden="true"></i><?= e(t('common.feedback_type.SUGGESTION')) ?></label>
                            <input type="radio" name="type" id="type-complaint" value="COMPLAINT"<?= $selectedType === 'COMPLAINT' ? ' checked' : '' ?>>
                            <label for="type-complaint"><i class="bi bi-exclamation-diamond" aria-hidden="true"></i><?= e(t('common.feedback_type.COMPLAINT')) ?></label>
                        </div>
                        <?php if (error('type')): ?><div class="invalid-feedback d-block"><?= e(error('type')) ?></div><?php endif; ?>
                    </fieldset>
                    <?= $this->component('field', ['name' => 'category_id', 'type' => 'select', 'label' => t('feedback.fields.category'), 'value' => old('category_id'), 'options' => $categories, 'placeholderOption' => t('common.forms.choose'), 'required' => true]) ?>
                    <?= $this->component('field', ['name' => 'subject', 'label' => t('feedback.fields.subject'), 'value' => old('subject'), 'required' => true, 'attrs' => ['maxlength' => 200]]) ?>
                    <?= $this->component('field', ['name' => 'message', 'type' => 'textarea', 'rows' => 7, 'label' => t('feedback.fields.message'), 'value' => old('message'), 'required' => true, 'help' => t('feedback.help.message'), 'attrs' => ['maxlength' => 5000]]) ?>
                    <?= $this->component('field', ['name' => 'attachment', 'type' => 'file', 'label' => t('feedback.fields.attachment'), 'help' => t('feedback.help.attachment', ['max' => $maxMb]), 'attrs' => ['accept' => '.jpg,.jpeg,.png,.webp,.pdf']]) ?>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><?= e(t('feedback.submit')) ?></button>
                        <a class="btn btn-light" href="<?= e(url('/student/feedback')) ?>"><?= e(t('common.actions.cancel')) ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
