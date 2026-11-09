<?php
declare(strict_types=1);
namespace Wx\Themes\Glass;

/**
 * 布局解析协作者 —— 只回答「这个布局我有没有、用哪些资源」。
 * 设计系统 glass.css 全布局共享；布局私有 CSS/JS 按同名文件可选叠加。
 */
final class LayoutResolver
{
    public function __construct(private string $dir) {}

    /** @return array{file: string, css: string, js: string}|null */
    public function resolve(string $layout): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $layout)) return null;
        $file = $layout === 'site' ? 'page.php' : $layout . '.php';
        if (!is_file($this->dir . '/templates/' . $file)) return null;

        $css = '';
        $shared = $this->dir . '/assets/glass.css';
        if (is_file($shared)) $css .= (string) file_get_contents($shared);
        $own = $this->dir . '/assets/' . $layout . '.css';
        if (is_file($own)) $css .= "\n" . (string) file_get_contents($own);

        $js = '';
        $script = $this->dir . '/assets/' . $layout . '.js';
        if (is_file($script)) $js .= (string) file_get_contents($script);

        return ['file' => $file, 'css' => $css, 'js' => $js];
    }
}
