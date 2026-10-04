<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer with layouts, named sections and reusable components.
 *
 * Inside a template:
 *   <?php $this->layout('layouts/app', ['title' => t('events.title')]) ?>
 *   <?php $this->start('scripts') ?> ... <?php $this->stop() ?>
 *   <?= $this->component('badge', ['status' => $status]) ?>
 *
 * Every dynamic value must be printed with e() (HTML-escaping).
 */
final class View
{
    /** @var array<string, mixed> Data shared with every template (current user, locale, ...). */
    private array $shared = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @return mixed */
    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return (new Template($this, $this->basePath))->render($template, array_merge($this->shared, $data));
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return (new Template($this, $this->basePath))->renderPartial($template, array_merge($this->shared, $data));
    }
}
