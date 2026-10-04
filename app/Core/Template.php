<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** @internal One render pass; holds layout + section state. */
final class Template
{
    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var list<string> */
    private array $sectionStack = [];

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly View $view, private readonly string $basePath)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data): string
    {
        $this->data = $data;
        $content = $this->capture($template, $data);

        // Layouts may themselves extend other layouts.
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            $this->sections['content'] = $content;
            $content = $this->capture($layout, array_merge($this->data, $this->layoutData));
        }

        return $content;
    }

    /** @param array<string, mixed> $data */
    public function renderPartial(string $template, array $data): string
    {
        $this->data = $data;

        return $this->capture($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = array_merge($this->layoutData, $data);
    }

    public function start(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function stop(): void
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            throw new RuntimeException('stop() called without start().');
        }
        $this->sections[$name] = ($this->sections[$name] ?? '') . (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** Render a partial with the current template data plus $data. @param array<string, mixed> $data */
    public function insert(string $name, array $data = []): string
    {
        return $this->view->partial($name, array_merge($this->data, $data));
    }

    /** Render views/components/{name}.php with only the given props. @param array<string, mixed> $props */
    public function component(string $name, array $props = []): string
    {
        return $this->view->partial('components/' . $name, $props);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->basePath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View [$template] not found.");
        }

        $level = ob_get_level();
        ob_start();
        try {
            (function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })->call($this, $file, $data);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
