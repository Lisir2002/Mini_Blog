/* ==========================================================================
   GlassFront 门户交互 —— 只消费既有「公开」接口，渐进增强服务端模板。
   - 服务端 page.php 已用结构化数据渲染初始列表，无脚本也能阅读；
   - 本脚本仅把首页文章流与站点概览换成接口返回的真实数据；
   - 令牌无（全部 public）、无写操作、无持久化存储，输出一律 textContent。
   ========================================================================== */
(function () {
  'use strict';

  var api = document.body.getAttribute('data-api') || '/api/v1';
  var layout = document.body.getAttribute('data-layout') || '';

  // 门户探针：逐条对应 api/registry.json 已登记的公开接口，不臆造端点。
  var FEEDS = [
    { key: 'posts', path: '/content/posts', label: '文章流' },
    { key: 'stats', path: '/content/stats', label: '内容统计' },
    { key: 'info',  path: '/system/info',  label: '系统信息' },
    { key: 'emoji', path: '/emojis',       label: '表情目录' }
  ];

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function getJSON(path) {
    return fetch(api + path, { headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (env) {
        if (!env || env.code !== 0) throw new Error('bad envelope');
        return env.data || {};
      });
  }

  function byKey(key) {
    for (var i = 0; i < FEEDS.length; i++) if (FEEDS[i].key === key) return FEEDS[i];
    return { path: '' };
  }

  // 文章卡片流：标题 / 摘要 / 作者 / 日期 / 链接均来自接口字段
  function renderPosts(data) {
    var box = document.getElementById('front-posts');
    if (!box) return;
    var items = Array.isArray(data.items) ? data.items : [];
    if (!items.length) {
      box.textContent = '';
      box.appendChild(el('p', 'front-empty', '还没有已发布的文章。'));
      return;
    }
    box.textContent = '';
    items.forEach(function (p) {
      var card = el('article', 'glass-card front-post');
      var slug = String(p.slug || '');
      var h = el('h3', 'front-post__title');
      if (slug) {
        var a = el('a', '', String(p.title || '未命名文章'));
        a.setAttribute('href', '/post?slug=' + encodeURIComponent(slug));
        h.appendChild(a);
      } else {
        h.textContent = String(p.title || '未命名文章');
      }
      card.appendChild(h);
      if (p.excerpt) card.appendChild(el('p', 'front-post__excerpt', p.excerpt));
      var meta = el('div', 'front-post__meta');
      meta.appendChild(el('span', '', String(p.author || '匿名')));
      if (p.created_at) meta.appendChild(el('time', '', String(p.created_at)));
      card.appendChild(meta);
      box.appendChild(card);
    });
    var hint = document.getElementById('front-posts-count');
    if (hint) hint.textContent = items.length + ' 篇 · 来自 /content/posts';
  }

  // 站点概览：数字来自 stats / info / emoji 的既有字段，主题不计算、不臆造
  function renderOverview(stats, info, emoji) {
    var box = document.getElementById('front-stats');
    if (!box) return;
    var cells = [
      ['已发布文章', stats.published != null ? stats.published : '—', 'content.stats'],
      ['系统层', info.systems != null ? info.systems : '—', 'system.info'],
      ['规范化接口', info.api_operations != null ? info.api_operations : '—', 'system.info'],
      ['可用表情', emoji.total != null ? emoji.total : '—', 'emojis']
    ];
    box.textContent = '';
    cells.forEach(function (c) {
      var card = el('div', 'glass-card front-stat');
      card.appendChild(el('p', 'front-stat__label', c[0]));
      card.appendChild(el('p', 'front-stat__value', c[1]));
      card.appendChild(el('p', 'front-stat__note', c[2]));
      box.appendChild(card);
    });
    var foot = document.getElementById('front-version');
    if (foot && info.core_version) foot.textContent = 'v' + info.core_version;
  }

  function fail(err) {
    var box = document.getElementById('front-posts');
    if (box) {
      var note = el('p', 'front-error', '接口暂不可用（' + err.message + '）；下方为服务端直出的初始内容。');
      box.appendChild(note);
    }
  }

  // 仅在存在门户容器的首页增强；友链页等复用 site 布局但无容器，不发请求。
  if (layout !== 'site') return;
  if (typeof fetch !== 'function') return;
  if (!document.getElementById('front-posts')) return;

  // 文章流与概览各自独立：一个失败不影响另一个，服务端直出的内容始终兜底。
  getJSON(byKey('posts').path).then(renderPosts).catch(fail);
  Promise.all([
    getJSON(byKey('stats').path),
    getJSON(byKey('info').path),
    getJSON(byKey('emoji').path)
  ]).then(function (res) {
    renderOverview(res[0], res[1], res[2]);
  }).catch(function () { /* 概览失败时保留服务端占位 */ });
})();
