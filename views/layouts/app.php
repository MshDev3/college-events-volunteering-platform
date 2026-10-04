<?php
/** @var \App\Core\Template $this  Public + student pages: header, content, footer. */
$this->layout('layouts/base', ['title' => $title ?? '', 'description' => $description ?? null]);
?>
<?= $this->insert('partials/header') ?>
<main id="main" tabindex="-1">
    <?= $this->section('content') ?>
</main>
<?= $this->insert('partials/footer') ?>
