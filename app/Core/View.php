<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout/main'): string
    {
        $content = $this->renderPartial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderPartial($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public function renderPartial(string $template, array $data = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data + $this->shared, EXTR_SKIP);
        $view = $this;
        ob_start();
        try {
            include $file;
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }
}
