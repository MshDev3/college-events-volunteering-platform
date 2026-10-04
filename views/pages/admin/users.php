<?php
/** @var \App\Core\Template $this  @var \App\Core\Paginator $paginator  @var array<string,string> $filters */
$this->layout('layouts/admin', ['title' => t('users.title')]);
$d = dates();
$me = auth_user();
?>
<?= $this->insert('partials/admin-head', ['heading' => t('users.title'), 'subheading' => t('users.subtitle')]) ?>
<?= $this->component('filter-bar', [
    'action' => '/admin/users',
    'filters' => $filters,
    'fields' => [
        ['name' => 'q', 'type' => 'search', 'label' => t('common.filters.search'), 'placeholder' => t('users.search_placeholder'), 'col' => 'col-12 col-md-5'],
        ['name' => 'role', 'type' => 'select', 'label' => t('users.cols.role'), 'options' => enum_options(\App\Domain\Role::cases())],
        ['name' => 'status', 'type' => 'select', 'label' => t('common.filters.status'), 'options' => ['active' => t('common.states.active'), 'inactive' => t('common.states.inactive')]],
    ],
]) ?>
<?php if ($paginator->isEmpty()): ?>
    <div class="panel"><?= $this->component('empty', ['title' => t('users.empty'), 'icon' => 'bi-people']) ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead><tr>
                <th scope="col"><?= e(t('users.cols.name')) ?></th>
                <th scope="col"><?= e(t('users.cols.student_id')) ?></th>
                <th scope="col"><?= e(t('users.cols.role')) ?></th>
                <th scope="col"><?= e(t('users.cols.created_at')) ?></th>
                <th scope="col"><?= e(t('common.filters.status')) ?></th>
                <th scope="col" class="text-end"><?= e(t('common.actions.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($paginator->items as $u): ?>
                <?php $role = \App\Domain\Role::fromId((int) $u['role_id']); $self = (int) $u['id'] === $me->id; ?>
                <tr>
                    <td class="cell-main"><span class="cell-title"><?= e($u['full_name']) ?></span><?php if ($self): ?> <span class="chip"><?= e(t('users.you')) ?></span><?php endif; ?><div class="cell-sub" dir="ltr"><?= e($u['email']) ?></div></td>
                    <td data-label="<?= e(t('users.cols.student_id')) ?>" dir="ltr"><?= e($u['student_id'] ?? '—') ?></td>
                    <td data-label="<?= e(t('users.cols.role')) ?>"><span class="status status-<?= $role === \App\Domain\Role::ADMIN ? 'info' : 'secondary' ?>"><?= e(t($role->labelKey())) ?></span></td>
                    <td data-label="<?= e(t('users.cols.created_at')) ?>"><?= e($d->date($u['created_at'])) ?></td>
                    <td data-label="<?= e(t('common.filters.status')) ?>"><span class="status status-<?= $u['is_active'] ? 'success' : 'danger' ?>"><?= e(t($u['is_active'] ? 'common.states.active' : 'common.states.inactive')) ?></span></td>
                    <td class="text-end">
                        <?php if ($self): ?>
                            <span class="small text-muted"><?= e(t('users.self_protected')) ?></span>
                        <?php else: ?>
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                <?php $newRole = $role === \App\Domain\Role::ADMIN ? \App\Domain\Role::STUDENT : \App\Domain\Role::ADMIN; ?>
                                <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/role')) ?>" data-confirm="<?= e(t('users.role_confirm', ['name' => $u['full_name'], 'role' => t($newRole->labelKey())])) ?>" data-confirm-tone="danger" data-confirm-ok="<?= e(t('users.change_role')) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="role" value="<?= e($newRole->value) ?>">
                                    <button class="btn btn-sm btn-light" type="submit"><?= e(t($newRole === \App\Domain\Role::ADMIN ? 'users.make_admin' : 'users.make_student')) ?></button>
                                </form>
                                <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/active')) ?>" data-confirm="<?= e(t($u['is_active'] ? 'users.deactivate_confirm' : 'users.activate_confirm', ['name' => $u['full_name']])) ?>"<?= $u['is_active'] ? ' data-confirm-tone="danger"' : '' ?>>
                                    <?= csrf_field() ?><input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
                                    <button class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-primary' ?>" type="submit"><?= e(t($u['is_active'] ? 'users.deactivate' : 'users.activate')) ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->insert('partials/pagination', ['paginator' => $paginator]) ?>
<?php endif; ?>
