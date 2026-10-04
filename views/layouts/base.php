<?php
/** @var \App\Core\Template $this */
/** @var string|null $title */
$siteName = t('common.site_name');
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . $siteName : $siteName;
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(dir_attr()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($description ?? t('common.site_description')) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(asset('assets/img/brand/tvtc.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(asset(is_rtl() ? 'assets/vendor/bootstrap/bootstrap.rtl.min.css' : 'assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main"><?= e(t('common.a11y.skip_to_content')) ?></a>

<?= $this->section('content') ?>

<?= $this->insert('partials/flash') ?>
<?= $this->insert('partials/confirm-modal') ?>

<script type="application/json" id="i18n-js"><?= json_encode([
    'showPassword' => t('common.actions.show_password'),
    'hidePassword' => t('common.actions.hide_password'),
    'confirm' => t('common.actions.confirm'),
    'confirmDefault' => t('common.confirm.default'),
    'loading' => t('common.states.loading'),
    // Loaded by app.js only on pages that have a date filter.
    'datepicker' => [
        'js' => asset('assets/lib/flatpickr/flatpickr.min.js'),
        'css' => asset('assets/lib/flatpickr/flatpickr.min.css'),
        'l10n' => locale() === 'ar' ? asset('assets/lib/flatpickr/l10n/ar.js') : null,
        'locale' => locale(),
        'placeholder' => t('common.filters.date_placeholder'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
