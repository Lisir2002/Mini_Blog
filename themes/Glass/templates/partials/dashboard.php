<?php
/**
 * 后台仪表盘骨架 —— 静态结构归主题，数据归各系统层。
 * 变量：$title / $themeName / $state / $esc。
 * 指标、系统层卡片、探针按钮全部由 assets/admin.js 通过既有接口填充；
 * 主题不内置系统清单，系统层增减时后台自动跟随。
 */
?>
<div class="dash">
  <aside class="dash__side glass-panel">
    <div class="dash__brand">
      <span class="dash__logo" aria-hidden="true"></span>
      <div class="dash__brand-text">
        <strong><?= $esc($title) ?></strong>
        <small><?= $esc($themeName) ?> · 后台主题</small>
      </div>
    </div>
    <nav class="dash__nav" aria-label="后台分区">
      <a class="is-active" href="#overview" data-nav="overview">概览</a>
      <a href="#layers" data-nav="layers">系统层</a>
      <a href="#probes" data-nav="probes">接口探针</a>
      <a href="#runtime" data-nav="runtime">响应明细</a>
    </nav>
    <div class="dash__side-foot">
      <span id="glass-version">版本读取中…</span>
      <span id="glass-db">数据源 —</span>
    </div>
  </aside>
  <div class="dash__main">
    <header class="dash__top glass-panel">
      <div class="dash__heading">
        <h1 id="dash-heading">概览</h1>
        <p class="glass-muted">系统层清单与指标均来自既有公开接口</p>
      </div>
      <div class="dash__token">
        <label class="sr-only" for="glass-token">Bearer 令牌</label>
        <input id="glass-token" type="password" placeholder="Bearer 令牌（仅存于本页内存）" autocomplete="off">
        <button id="glass-refresh" type="button">刷新</button>
      </div>
    </header>
<?php include __DIR__ . '/state.php'; ?>
    <section id="overview" class="dash__section">
      <div class="dash__section-head">
        <h2>概览</h2><span class="glass-muted" id="glass-summary">读取中…</span>
      </div>
      <div class="glass-metrics" id="glass-metrics"></div>
    </section>
    <section id="layers" class="dash__section">
      <div class="dash__section-head">
        <h2>系统层</h2><span class="glass-muted" id="glass-layer-count">读取中…</span>
      </div>
      <p class="glass-muted">卡片由 <code>/api/v1/system/layers</code> 的装配顺序生成；新增系统层时后台自动出现，无需修改主题。</p>
      <div class="glass-grid" id="glass-layers"></div>
    </section>
    <section id="probes" class="dash__section">
      <div class="dash__section-head">
        <h2>接口探针</h2><span class="glass-muted" id="glass-probe-count">—</span>
      </div>
      <div id="glass-probes"></div>
    </section>
    <section id="runtime" class="dash__section">
      <div class="dash__section-head">
        <h2>响应明细</h2><button id="glass-clear" type="button" class="ghost">清空</button>
      </div>
      <pre class="glass-pre" id="glass-output" aria-live="polite">点击任意系统层卡片或探针按钮，响应显示在这里。</pre>
    </section>
    <footer class="dash__foot glass-muted">呈现归主题 · 数据归各系统层 · 核心只做装配与调度</footer>
  </div>
</div>
