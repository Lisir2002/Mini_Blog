<?php
declare(strict_types=1);

/**
 * 反向锚点的快照 —— 「当前已知未纳管」的候选清单，只用于发现新增。
 *
 * 为什么不是「全部数字都必须有锚点」：文档里大量数字是史实（版本记录里的
 * 「新增 6 个接口」）与快照（性能耗时），要求逐个锚定只会得到几百条噪音，
 * 最终没人看。这里退一步：接受现状，只对**新出现的组合**报警。
 *
 * 条目格式：文件|数值|名词。身份不含行号，改写措辞与上下移行不会触发；
 * 同一文件里同一数值同名次出现多次只记一条。
 *
 * 这个文件是账本，不是许可：新增条目应当先问「该补锚点，还是确属史实」。
 * 更新：php tools/facts.php accept（只写本文件）。
 */
return [
    'docs/ARCHITECTURE.md|10|接口',
    'docs/ARCHITECTURE.md|13|契约接口',
    'docs/ARCHITECTURE.md|14|系统层',
    'docs/ARCHITECTURE.md|17|接口',
    'docs/ARCHITECTURE.md|17|系统层',
    'docs/ARCHITECTURE.md|1|系统层',
    'docs/ARCHITECTURE.md|2|接口',
    'docs/ARCHITECTURE.md|34|分组',
    'docs/ARCHITECTURE.md|46|权限点',
    'docs/ARCHITECTURE.md|4|operation',
    'docs/ARCHITECTURE.md|5|权限点',
    'docs/ARCHITECTURE.md|62|路径',
    'docs/ARCHITECTURE.md|67|operation',
    'docs/ARCHITECTURE.md|69|operation',
    'docs/ARCHITECTURE.md|69|接口',
    'docs/ARCHITECTURE.md|6|接口',
    'docs/CONVENTIONS.md|13|契约接口',
    'docs/CONVENTIONS.md|23|分组',
    'docs/DATA.md|24|PHP 文件',
    'docs/DATA.md|3|接口',
    'docs/DATA.md|47|分组',
    'docs/STATE.md|24|PHP 文件',
    'docs/THEMES.md|24|PHP 文件',
    'docs/architecture.html|11|接口',
    'docs/architecture.html|17|系统层',
    'docs/architecture.html|1|接口',
    'docs/architecture.html|2|系统层',
];
