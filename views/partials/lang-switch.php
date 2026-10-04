<?php $current = locale(); ?>
<div class="lang-switch" role="group" aria-label="<?= e(t('common.a11y.language')) ?>">
    <a href="<?= e(url('/lang/ar')) ?>" lang="ar" hreflang="ar" aria-current="<?= $current === 'ar' ? 'true' : 'false' ?>">العربية</a>
    <a href="<?= e(url('/lang/en')) ?>" lang="en" hreflang="en" aria-current="<?= $current === 'en' ? 'true' : 'false' ?>">English</a>
</div>
