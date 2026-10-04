<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var bool $unreadOnly */
$this->layout('layouts/app', ['title' => t('notifications.title')]);
$d = dates();
$actions = '<form method="post" action="' . e(url('/notifications/read-all')) . '">' . csrf_field()
    . '<button class="btn btn-light-teal" type="submit">' . e(t('notifications.mark_all')) . '</button></form>';
?>
<?= $this->insert('partials/page-head', [
    'heading' => t('notifications.title'),
    'crumbs' => [[t('notifications.title'), null]],
    'actions' => ($unreadNotifications ?? 0) > 0 ? $actions : null,
]) ?>
<section class="section-tight">
    <div class="container narrow-lg">
        <nav class="tabs mb-3" aria-label="<?= e(t('notifications.title')) ?>">
            <a href="<?= e(url('/notifications')) ?>"<?= !$unreadOnly ? ' aria-current="page"' : '' ?>><?= e(t('notifications.all')) ?></a>
            <a href="<?= e(url('/notifications', ['filter' => 'unread'])) ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?>><?= e(t('notifications.unread')) ?><span class="count"><?= e($unreadNotifications ?? 0) ?></span></a>
        </nav>
        <div class="panel">
            <?php if ($paginator->isEmpty()): ?>
                <?= $this->component('empty', ['title' => t('notifications.empty'), 'icon' => 'bi-bell-slash']) ?>
            <?php else: ?>
                <ul class="list-plain">
                    <?php foreach ($paginator->items as $n): ?>
                        <li class="list-row<?= $n['is_read'] ? '' : ' notif-unread' ?>">
                            <span class="notif-ico" aria-hidden="true"><i class="bi <?= e($n['icon']) ?>"></i></span>
                            <span class="grow">
                                <span class="title"><?= e($n['title']) ?></span>
                                <span class="sub d-block"><?= e($n['body']) ?></span>
                                <span class="sub"><time datetime="<?= e($n['created_at']) ?>"><?= e($d->relative($n['created_at'])) ?></time></span>
                            </span>
                            <form method="post" action="<?= e(url('/notifications/' . $n['id'] . '/read')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-light" type="submit" data-no-loading><?= e(t($n['link'] ? 'notifications.open' : 'notifications.mark_read')) ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
    </div>
</section>
