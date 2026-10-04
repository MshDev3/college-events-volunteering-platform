<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Copies front-end vendor files installed by Composer into public/assets/vendor,
 * so the site runs offline with no Node.js toolchain. Runs automatically after composer install/update.
 */
final class AssetPublisher
{
    public function __construct(private readonly string $basePath)
    {
    }

    /** @param callable(string): void $out */
    public function publish(callable $out): void
    {
        $vendor = $this->basePath . '/vendor/twbs';
        $target = $this->basePath . '/public/assets/vendor';

        $files = [
            "$vendor/bootstrap/dist/css/bootstrap.min.css" => "$target/bootstrap/bootstrap.min.css",
            "$vendor/bootstrap/dist/css/bootstrap.rtl.min.css" => "$target/bootstrap/bootstrap.rtl.min.css",
            "$vendor/bootstrap/dist/js/bootstrap.bundle.min.js" => "$target/bootstrap/bootstrap.bundle.min.js",
            "$vendor/bootstrap-icons/font/bootstrap-icons.min.css" => "$target/bootstrap-icons/bootstrap-icons.min.css",
            "$vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2" => "$target/bootstrap-icons/fonts/bootstrap-icons.woff2",
            "$vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff" => "$target/bootstrap-icons/fonts/bootstrap-icons.woff",
        ];

        foreach ($files as $from => $to) {
            if (!is_file($from)) {
                $out("Missing (run composer install): $from");
                continue;
            }
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0775, true);
            }
            // Strip sourceMappingURL comments: the .map files are not published.
            $content = (string) file_get_contents($from);
            if (str_ends_with($to, '.css') || str_ends_with($to, '.js')) {
                $content = (string) preg_replace('~\n?/[*/]# sourceMappingURL=[^\n]*~', '', $content);
            }
            file_put_contents($to, $content);
        }
        $out('Assets published to public/assets/vendor.');
    }
}
