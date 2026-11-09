<?php
declare(strict_types=1);
namespace Wx\Themes\GlassFront;

/**
 * 布局解析协作者 —— 只回答「这个布局我有没有、用哪些资源」。
 * glass.css 是与 Glass 后端元主题逐字同源的材质层，全布局共享；
 * front.css 是前端门户专属结构；布局私有 CSS/JS 按同名文件可选叠加。
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
        foreach (['glass.css', 'front.css', $layout . '.css'] as $name) {
            $path = $this->dir . '/assets/' . $name;
            if (is_file($path)) $css .= "\n" . (string) file_get_contents($path);
        }

        $js = '';
        foreach (['front.js', $layout . '.js'] as $name) {
            $path = $this->dir . '/assets/' . $name;
            if (is_file($path)) $js .= "\n" . (string) file_get_contents($path);
        }

        return ['file' => $file, 'css' => trim($css), 'js' => trim($js)];
    }
}
