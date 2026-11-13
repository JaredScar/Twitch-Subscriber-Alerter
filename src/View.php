<?php
declare(strict_types=1);

namespace App;

final class View
{
    /** HTML-escape. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render templates/<name>.php, optionally inside templates/<layout>.php
     * @param array<string,mixed> $vars
     */
    public static function render(string $name, array $vars = [], ?string $layout = 'layout'): void
    {
        $content = self::capture($name, $vars);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo self::capture($layout, $vars + ['content' => $content]);
    }

    /** An inline SVG icon from templates/_icons.php (stroke follows the text colour). */
    public static function icon(string $name, string $extraClass = ''): string
    {
        return '<svg class="i' . ($extraClass !== '' ? ' ' . self::e($extraClass) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . self::e($name) . '"/></svg>';
    }

    /** Render templates/<name>.php on its own and return the HTML (for shared fragments). */
    public static function partial(string $name, array $vars = []): string
    {
        return self::capture($name, $vars);
    }

    /** @param array<string,mixed> $vars */
    private static function capture(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }

    public static function ago(string $utcDatetime): string
    {
        $t = strtotime($utcDatetime . ' UTC');
        if ($t === false) {
            return $utcDatetime;
        }
        $d = time() - $t;
        if ($d < 60) {
            return 'just now';
        }
        if ($d < 3600) {
            return intdiv($d, 60) . ' min ago';
        }
        if ($d < 86400) {
            return intdiv($d, 3600) . ' h ago';
        }
        return intdiv($d, 86400) . ' d ago';
    }
}
