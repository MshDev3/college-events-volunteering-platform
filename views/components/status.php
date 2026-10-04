<?php /** @var \App\Domain\Status $status */ ?>
<span class="status status-<?= e($status->tone()) ?>"><?= e(t($status->labelKey())) ?></span>
