<?php
declare(strict_types=1);

namespace App\Core;

/** Plain PHP templates in app/Views. Always escape output with e(). */
final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::file($template, $data);
        return $layout === null ? $content : self::file($layout, array_merge($data, ['content' => $content]));
    }

    public static function file(string $template, array $data = []): string
    {
        $path = APP_PATH . '/Views/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        $__data = array_merge(self::$shared, $data);
        $__path = $path;
        ob_start();
        try {
            (static function () use ($__data, $__path): void {
                extract($__data, EXTR_SKIP);
                include $__path;
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
