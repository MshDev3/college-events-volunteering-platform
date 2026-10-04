<?php
/** @var \App\Core\Template $this  Split-screen layout for login / register / password pages. */
$this->layout('layouts/base', ['title' => $title ?? '']);
?>
<div class="auth-shell">
    <aside class="auth-aside" aria-hidden="true">
        <a class="brand" href="<?= e(url('/')) ?>">
            <img src="<?= e(asset('assets/img/brand/tvtc.svg')) ?>" alt="">
            <span class="brand-name"><?= e(t('common.site_name')) ?><span class="brand-sub"><?= e(t('common.site_tagline')) ?></span></span>
        </a>
        <div class="position-relative">
            <h2><?= e(t('auth.aside.title')) ?></h2>
            <p><?= e(t('auth.aside.body')) ?></p>
        </div>
        <p class="small mb-0 position-relative">&copy; <?= e(date('Y')) ?> <?= e(t('common.site_name')) ?></p>
    </aside>
    <main class="auth-main" id="main" tabindex="-1">
        <div class="auth-top">
            <a class="brand d-lg-none" href="<?= e(url('/')) ?>">
                <img src="<?= e(asset('assets/img/brand/tvtc.svg')) ?>" alt="">
                <span class="brand-name"><?= e(t('common.site_name')) ?></span>
            </a>
            <a class="d-none d-lg-inline small text-decoration-none" href="<?= e(url('/')) ?>">
                <?= e(t('common.actions.back_home')) ?>
            </a>
            <?= $this->insert('partials/lang-switch') ?>
        </div>
        <div class="auth-card">
            <?= $this->section('content') ?>
        </div>
    </main>
</div>
