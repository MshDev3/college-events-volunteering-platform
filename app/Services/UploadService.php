<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;

/**
 * File uploads with server-side checks:
 *  - MIME type detected from the file contents (finfo), never trusted from the browser
 *  - size limit, allow-list of types, random file names (no user-controlled paths)
 *  - event images go to public/uploads (served directly, scripts disabled there);
 *    feedback attachments go to storage/ (outside the web root, served only after an authorization check)
 */
final class UploadService
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return string path relative to public/ (for asset())
     */
    public function storePublicImage(array $file, string $field, string $folder): string
    {
        [$mime, $ext] = $this->check($file, $field, (array) $this->config->get('uploads.image_mimes'));
        $dir = app()->basePath('public/uploads/' . $folder);
        $this->ensureDir($dir);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $this->move($file, $dir . '/' . $name, $field);

        return 'uploads/' . $folder . '/' . $name;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{original_name:string, stored_name:string, mime_type:string, size_bytes:int}
     */
    public function storePrivate(array $file, string $field): array
    {
        [$mime] = $this->check($file, $field, (array) $this->config->get('uploads.attachment_mimes'));
        $dir = app()->basePath('storage/uploads/attachments');
        $this->ensureDir($dir);
        $stored = $this->uuid();
        $this->move($file, $dir . '/' . $stored, $field);

        $original = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', basename($file['name'])) ?: 'file';

        return [
            'original_name' => mb_substr($original, 0, 255),
            'stored_name' => $stored,
            'mime_type' => $mime,
            'size_bytes' => (int) $file['size'],
        ];
    }

    public function privatePath(string $storedName): ?string
    {
        if (preg_match('/^[a-f0-9-]{36}$/', $storedName) !== 1) {
            return null;
        }
        $path = app()->basePath('storage/uploads/attachments/' . $storedName);

        return is_file($path) ? $path : null;
    }

    public function deletePublic(?string $relativePath): void
    {
        if ($relativePath === null || !str_starts_with($relativePath, 'uploads/') || str_contains($relativePath, '..')) {
            return;
        }
        $path = app()->basePath('public/' . $relativePath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @param array<string, string> $allowed mime => extension
     * @return array{0:string,1:string} [mime, extension]
     */
    private function check(array $file, string $field, array $allowed): array
    {
        $max = (int) $this->config->get('uploads.max_bytes');
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > $max) {
            throw ValidationException::withMessage($field, t('validation.file_too_large', ['max' => (int) round($max / 1048576)]));
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) && !$this->isTestFile($file['tmp_name'])) {
            throw ValidationException::withMessage($field, t('validation.file_failed'));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime]) || !$this->contentMatches($file['tmp_name'], $mime)) {
            throw ValidationException::withMessage($field, t('validation.file_type', ['types' => strtoupper(implode(', ', array_unique($allowed)))]));
        }

        return [$mime, $allowed[$mime]];
    }

    /**
     * Second check beyond magic bytes: an image must actually decode as an image of that type
     * (rejects scripts/HTML with a forged header); a PDF must start with the PDF signature.
     */
    private function contentMatches(string $path, string $mime): bool
    {
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($path);

            return is_array($info) && ($info['mime'] ?? '') === $mime && $info[0] > 0 && $info[1] > 0;
        }
        if ($mime === 'application/pdf') {
            return (string) file_get_contents($path, false, null, 0, 5) === '%PDF-';
        }

        return false;
    }

    /** @param array{tmp_name:string} $file */
    private function move(array $file, string $target, string $field): void
    {
        $ok = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : ($this->isTestFile($file['tmp_name']) && copy($file['tmp_name'], $target));
        if (!$ok) {
            throw ValidationException::withMessage($field, t('validation.file_failed'));
        }
    }

    /** Integration tests pass real temp files that were not uploaded over HTTP. */
    private function isTestFile(string $path): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL') && is_file($path);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
