<?php /** @var string $name  @var string $new_email  @var string $subject */ ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(dir_attr()) ?>">
<head><meta charset="UTF-8"><title><?= e($subject) ?></title></head>
<body style="margin:0;background:#f5f8f8;font-family:Tahoma,Arial,sans-serif;color:#16324f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dbe4e6;border-radius:14px;">
            <tr><td style="padding:24px 28px;border-bottom:1px solid #dbe4e6;font-weight:bold;font-size:18px;color:#047e79;"><?= e(t('common.site_name')) ?></td></tr>
            <tr><td style="padding:28px;line-height:1.7;font-size:15px;text-align:<?= is_rtl() ? 'right' : 'left' ?>;">
                <p style="margin:0 0 12px;"><?= e(t('emails.email_changed.greeting', ['name' => $name])) ?></p>
                <p style="margin:0 0 12px;"><?= e(t('emails.email_changed.intro', ['new_email' => $new_email])) ?></p>
                <p style="margin:0 0 12px;"><?= e(t('emails.email_changed.sessions')) ?></p>
                <p style="margin:0 0 12px;font-weight:bold;"><?= e(t('emails.email_changed.not_you')) ?></p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
