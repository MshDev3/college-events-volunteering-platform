<?php
/**
 * Register / cancel panel for an event or opportunity detail page.
 * The decision comes from the service (`registration_state`, see ActivityService::registrationState);
 * this partial only chooses what to show for it and for the viewer.
 * @var array<string,mixed> $item
 * @var string $kind  'event' | 'volunteer'
 */
$ns = $kind === 'event' ? 'events' : 'volunteering';
$base = $kind === 'event' ? '/events/' : '/volunteering/';
$state = (string) ($item['registration_state'] ?? 'closed_started');
$user = auth_user();
?>
<div class="panel">
    <div class="panel-body">
        <?= $this->component('meter', ['registered' => (int) $item['registered_count'], 'capacity' => (int) $item['capacity']]) ?>
        <hr>
        <?php if ($state === 'registered' || $state === 'registered_cancellable'): ?>
            <p class="d-flex align-items-center gap-2 fw-semibold text-success mb-3"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?= e(t("$ns.action.registered")) ?></p>
            <?php if ($state === 'registered_cancellable'): ?>
                <form method="post" action="<?= e(url($base . $item['id'] . '/unregister')) ?>" data-confirm="<?= e(t("$ns.action.unregister_confirm")) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t("$ns.action.unregister")) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger w-100" type="submit"><?= e(t("$ns.action.unregister")) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($state === 'closed_cancelled'): ?>
            <p class="text-danger mb-0"><?= e(t("$ns.action.closed_cancelled")) ?></p>
        <?php elseif ($state === 'closed_started'): ?>
            <p class="text-muted mb-0"><?= e(t("$ns.action.closed_started")) ?></p>
        <?php elseif ($state === 'full'): ?>
            <p class="text-danger fw-semibold mb-0"><?= e(t("$ns.action.full")) ?></p>
        <?php elseif ($user === null): ?>
            <p class="text-muted"><?= e(t("$ns.action.login_to_register")) ?></p>
            <a class="btn btn-primary w-100" href="<?= e(url('/login', ['next' => $base . $item['id']])) ?>"><?= e(t('common.nav.login')) ?></a>
        <?php elseif ($user->isAdmin()): ?>
            <p class="text-muted mb-2"><?= e(t("$ns.action.admin_note")) ?></p>
            <a class="btn btn-light-teal w-100" href="<?= e(url('/admin/' . $ns . '/' . $item['id'])) ?>"><?= e(t('common.actions.manage')) ?></a>
        <?php else: ?>
            <form method="post" action="<?= e(url($base . $item['id'] . '/register')) ?>">
                <?= csrf_field() ?>
                <?php if ($kind === 'volunteer'): ?>
                    <?= $this->component('field', ['name' => 'motivation', 'type' => 'textarea', 'rows' => 3, 'label' => t('volunteering.fields.motivation'), 'value' => old('motivation'), 'help' => t('volunteering.help.motivation'), 'attrs' => ['maxlength' => 1000]]) ?>
                <?php endif; ?>
                <button class="btn btn-primary btn-lg w-100" type="submit"><?= e(t("$ns.action.register")) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
