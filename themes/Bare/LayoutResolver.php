<?php
declare(strict_types=1);
namespace Wx\Themes\Bare;

/**
 * 布局解析协作者 —— 把「布局名」解析成本主题包内的模板与资源。
 * 渲染器不该兼任路径校验与文件读取；本类只回答「这个布局我有没有」。
 */
final class LayoutResolver
{
    public function __construct(private string $dir) {}

    /** @return array{file: string, css: string, js: string}|null 未提供该布局时返回 null */
    public function resolve(string $layout): ?array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $layout)) return null;
        $file = $layout === 'site' ? 'page.php' : $layout . '.php';
        if (!is_file($this->dir . '/templates/' . $file)) return null;

        $cssFile = $layout === 'site'
            ? $this->dir . '/style.css'
            : $this->dir . '/assets/' . $layout . '.css';
        $jsFile  = $this->dir . '/assets/' . $layout . '.js';

        // 跨布局共享：状态五态、评论树与表情。它们不是某个页面的私产，
        // 任何布局只要展示内容就需要，因此只在这里拼一次。
        $sharedCss = [$this->dir . '/assets/state.css', $this->dir . '/assets/comment.css'];
        $sharedJs  = $this->dir . '/assets/state.js';

        $css = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
        foreach ($sharedCss as $shared) {
            if (is_file($shared)) $css .= "\n" . (string) file_get_contents($shared);
        }
        $js = is_file($jsFile) ? (string) file_get_contents($jsFile) : '';
        if (is_file($sharedJs)) $js .= "\n" . (string) file_get_contents($sharedJs);

        return ['file' => $file, 'css' => $css, 'js' => $js];
    }
}
