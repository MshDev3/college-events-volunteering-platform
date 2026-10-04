<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirmModalTitle"><?= e(t('common.confirm.title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.actions.close')) ?>"></button>
            </div>
            <div class="modal-body" data-confirm-body></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= e(t('common.actions.cancel')) ?></button>
                <button type="button" class="btn btn-primary" data-confirm-ok><?= e(t('common.actions.confirm')) ?></button>
            </div>
        </div>
    </div>
</div>
