<?php /** @var string $name  @var string $link  @var int $minutes  @var string $subject */ ?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(dir_attr()) ?>">
<head><meta charset="UTF-8"><title><?= e($subject) ?></title></head>
<body style="margin:0;background:#f5f8f8;font-family:Tahoma,Arial,sans-serif;color:#16324f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dbe4e6;border-radius:14px;">
            <tr><td style="padding:24px 28px;border-bottom:1px solid #dbe4e6;font-weight:bold;font-size:18px;color:#047e79;"><?= e(t('common.site_name')) ?></td></tr>
            <tr><td style="padding:28px;line-height:1.7;font-size:15px;text-align:<?= is_rtl() ? 'right' : 'left' ?>;">
                <p style="margin:0 0 12px;"><?= e(t('emails.password_reset.greeting', ['name' => $name])) ?></p>
                <p style="margin:0 0 20px;"><?= e(t('emails.password_reset.intro')) ?></p>
                <p style="margin:0 0 24px;text-align:center;">
                    <a href="<?= e($link) ?>" style="display:inline-block;background:#047e79;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:bold;"><?= e(t('emails.password_reset.button')) ?></a>
                </p>
                <p style="margin:0 0 12px;"><?= e(t('emails.password_reset.expiry', ['minutes' => $minutes])) ?></p>
                <p style="margin:0 0 12px;color:#5b6b78;"><?= e(t('emails.password_reset.ignore')) ?></p>
                <p style="margin:16px 0 0;font-size:12px;color:#5b6b78;word-break:break-all;" dir="ltr"><?= e($link) ?></p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
