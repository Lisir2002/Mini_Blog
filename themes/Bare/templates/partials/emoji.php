<?php
/**
 * 表情片段 —— 唯一把 token 变成标签的地方。
 * 可用变量：$tokens（list<array>）、$esc。
 * 文本与字形一律转义后输出；图片地址只可能是站内 /attach/download 路径。
 */
foreach ((array) ($tokens ?? []) as $token) {
    if ((string) ($token['type'] ?? 'text') !== 'emoji') {
        echo $esc((string) ($token['value'] ?? ''));
        continue;
    }
    $kind = (string) ($token['kind'] ?? 'unicode');
    $value = (string) ($token['value'] ?? '');
    $alt = (string) ($token['alt'] ?? '');
    if ($kind === 'image' && $value !== '') {
        echo '<img class="wx-emoji wx-emoji--img" src="' . $esc($value) . '" alt="' . $esc($alt) . '"'
            . ' loading="lazy" decoding="async" width="20" height="20">';
        continue;
    }
    echo '<span class="wx-emoji" title="' . $esc($alt) . '">' . $esc($value) . '</span>';
}
