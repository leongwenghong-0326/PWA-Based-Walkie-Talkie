<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        $data['content'] = $content;
        return self::renderFile($layout, $data);
    }

    /** @param array<string, mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        $path = App::root() . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template) . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('View not found.');
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
