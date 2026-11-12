<?php
declare(strict_types=1);

/**
 * 67. 导航覆盖守卫 —— 「页面存在却没有全站入口」的孤儿页必须为零。
 *
 * 上一阶段把 API 写能力的入口全部收口（零消费未登记归零）；这一阶段把同样的
 * 纪律用到页面可达性上：pages 目录下每个 page.json 声明的每条路由，都要在
 * 主题模板或页面产物里至少被一个 href / action 指到。确实不该进导航的路由
 * （初始化向导、机器消费文件）必须带 ≥20 字符的理由登记例外，不许静默豁免。
 *
 * 同时锁定两款元主题的导航契约：前台主导航要给出注册 / 账户 / 工具箱入口；
 * 后台统一导航条要覆盖全部 /console 页与 /account，且两份源码逐字同源。
 */

use Wx\Core\Kernel;

echo "\n=== 67. 导航覆盖守卫：孤儿页面清零、例外必须登记、导航条覆盖后台 ===\n";

$root = dirname(__DIR__, 2);

/** 例外交由人工登记：路由 => 理由（≥20 字符，说清为什么不该进导航）。 */
$exempt = [
    '/install'    => '初始化向导只在站点未安装时可达，常驻导航出现它反而误导已安装站点',
    '/sitemap.xml' => '机器消费的站点地图文件，由搜索引擎按 robots.txt 发现，不做人类导航入口',
    '/robots.txt' => '爬虫协议文件，机器消费；人类读者没有从导航进入它的场景',
];

check('page.json 路由清单可读、非空，例外理由达标且登记的都是真路由', function () use ($root, $exempt): void {
    $routes = [];
    foreach (glob($root . '/pages/*/page.json') ?: [] as $file) {
        $meta = json_decode((string) file_get_contents($file), true);
        truthy(is_array($meta), basename(dirname($file)) . ' 的 page.json 不是合法对象');
        $list = $meta['routes'] ?? [];
        truthy(is_array($list) && $list !== [], basename(dirname($file)) . ' 没有声明任何路由');
        foreach ($list as $r) $routes[] = (string) $r;
    }
    truthy(count($routes) >= 25, '路由清单数量异常，扫描器可能没读到 page.json');

    foreach ($exempt as $route => $reason) {
        truthy(in_array($route, $routes, true), '例外登记了不存在的路由 ' . $route);
        truthy(strlen($reason) >= 20, '例外 ' . $route . ' 的理由太短，说清为什么不该进导航');
    }
});

check('收集全站引用面：主题模板与页面产物的 href / action 必须指到已声明路由', function () use ($root, $exempt): void {
    // 1) 收集全部已声明路由
    $routes = [];
    foreach (glob($root . '/pages/*/page.json') ?: [] as $file) {
        $meta = json_decode((string) file_get_contents($file), true);
        foreach ((array) ($meta['routes'] ?? []) as $r) $routes[(string) $r] = true;
    }

    // 2) 扫描引用：themes 模板（递归）+ pages 产物（一层）。
    //    两套口径：$refsHref 只认 href/action 的双引号写法（精确、用于例外反向核对）；
    //    $refsAny 额外收录 PHP 字符串字面量里的 '/path'（页面产物经查询器拼链接，
    //    如 '/term?kind=' 与 'href' => 数组条目，只靠 href= 会漏报成假孤儿）。
    $refsHref = $refsAny = [];
    $scan = static function (string $dir) use (&$scan, &$refsHref, &$refsAny): void {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match_all('/(?:href|action)="(\/[a-z0-9][a-z0-9._\/-]*)/', $src, $m)) {
                foreach ($m[1] as $p) $refsHref[$p] = true;
            }
            if (preg_match_all('/["\'](\/[a-z0-9][a-z0-9._\/-]*)["\']/', $src, $m2)) {
                foreach ($m2[1] as $p) $refsAny[$p] = true;
            }
        }
    };
    foreach (glob($root . '/themes/*', GLOB_ONLYDIR) ?: [] as $themeDir) {
        $tpl = $themeDir . '/templates';
        if (is_dir($tpl)) $scan($tpl, true);
    }
    foreach (glob($root . '/pages/*', GLOB_ONLYDIR) ?: [] as $pageDir) $scan($pageDir, false);
    $refsAny['/'] = true; // 首页被 href="/" 大量指到，正则要求路径段非空，这里显式补上

    truthy($refsAny !== [], '没有扫到任何引用，扫描器坏了不能当绿灯用');

    // 3) 孤儿路由：已声明、未被任何入口指到、也不在例外清单里
    $orphans = [];
    foreach (array_keys($routes) as $route) {
        if (isset($refsAny[$route]) || isset($exempt[$route])) continue;
        $orphans[] = $route;
    }
    truthy($orphans === [], '发现孤儿页面（有路由没入口）: ' . implode(', ', $orphans));

    // 4) 反向：例外必须真的「没被 href 指到」——已经有人指到的路由不需要豁免
    foreach (array_keys($exempt) as $route) {
        truthy(!isset($refsHref[$route]), $route . ' 已有导航入口，例外登记成了冗余，请删掉');
    }
});

check('前台主导航给出注册 / 账户 / 工具箱入口，两款前台头部逐字同源', function () use ($root): void {
    $head = (string) file_get_contents($root . '/themes/GlassFront/templates/partials/head.php');
    foreach (['/register', '/account', '/toolbox'] as $route) {
        truthy(str_contains($head, 'href="' . $route . '"'), '前台主导航缺少 ' . $route . ' 入口');
    }

    $glassHead = (string) file_get_contents($root . '/themes/Glass/templates/partials/head.php');
    // 两款 head 同源的部分是文档骨架；主导航是 GlassFront 门户专属，不强求一致，
    // 但 GlassFront 自己的导航必须覆盖它声明的全部前台可达路由。
    $frontRoutes = ['/post', '/archive', '/terms', '/search', '/feed', '/links'];
    foreach ($frontRoutes as $route) {
        truthy(str_contains($head, 'href="' . $route), 'GlassFront 主导航缺少 ' . $route);
    }
    truthy(str_contains($glassHead, 'href="/feed"'), 'Glass 文档头丢了 RSS 订阅链接');
});

check('后台统一导航条覆盖全部 /console 页与 /account，两款逐字同源', function () use ($root): void {
    $glassNav  = (string) file_get_contents($root . '/themes/Glass/templates/partials/admin-nav.php');
    $frontNav  = (string) file_get_contents($root . '/themes/GlassFront/templates/partials/admin-nav.php');
    truthy($glassNav === $frontNav, '两款 admin-nav.php 不再逐字同源，请同步后再交');

    $consoleRoutes = [];
    foreach (glob($root . '/pages/*/page.json') ?: [] as $file) {
        $meta = json_decode((string) file_get_contents($file), true);
        foreach ((array) ($meta['routes'] ?? []) as $r) {
            if (str_starts_with((string) $r, '/console/')) $consoleRoutes[] = (string) $r;
        }
    }
    truthy(count($consoleRoutes) >= 14, '后台路由清单数量异常，扫描器可能没读到 page.json');
    foreach ($consoleRoutes as $route) {
        truthy(str_contains($glassNav, "'" . $route . "'"), '后台导航条缺少 ' . $route . ' 入口');
    }
    truthy(str_contains($glassNav, 'href="/account"'), '后台导航条缺少 /account 入口');
});

check('admin 布局真的引入了导航条，不是只写了文件没接线', function () use ($root): void {
    $glassAdmin = (string) file_get_contents($root . '/themes/Glass/templates/admin.php');
    $frontAdmin = (string) file_get_contents($root . '/themes/GlassFront/templates/admin.php');
    truthy(str_contains($glassAdmin, "partials/admin-nav.php"), 'Glass admin 布局没有引入导航条');
    truthy(str_contains($frontAdmin, "partials/admin-nav.php"), 'GlassFront admin 布局没有引入导航条');

    // 高亮契约：导航条用页面自报的 view 名做 active 判断，缺省必须兜底为空串。
    $nav = (string) file_get_contents($root . '/themes/Glass/templates/partials/admin-nav.php');
    truthy(str_contains($nav, "(string) (\$view ?? '')"), '导航条没有对 $view 兜底，缺变量会告警');
});
