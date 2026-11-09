/* ==========================================================================
   admin 布局交互脚本 —— 归属主题包。
   数据全部来自 api/registry.json 已登记的既有接口；主题不新增接口、不内置业务数据。
   系统层清单取自 /system/layers，因此系统层增减时后台自动跟随。
   ========================================================================== */
(function () {
  'use strict';
  var api = document.body.dataset.api || '/api/v1';
  var token = ''; // 仅存于本页内存，不写任何持久化存储
  var out = document.getElementById('glass-output');

  // 呈现元数据：只给中文名 / 分组 / 代表接口；清单本身不在这里。
  var LAYER_META = {
    log:        { label: '日志',   group: '基础设施', probe: '/system/info' },
    data:       { label: '数据',   group: '基础设施', probe: '/system/health' },
    security:   { label: '安全',   group: '基础设施', probe: '/system/contracts' },
    lifecycle:  { label: '装卸',   group: '元层',     probe: '/lifecycle/units', auth: true },
    user:       { label: '用户',   group: '身份',     probe: '/users/me', auth: true },
    permission: { label: '权限',   group: '身份',     probe: '/permission/catalog' },
    api:        { label: '接口',   group: '能力',     probe: '/system/info' },
    state:      { label: '状态',   group: '能力',     probe: '/state/task', auth: true, query: '?id=state.pending' },
    plugin:     { label: '插件',   group: '承载',     probe: '/system/layers' },
    member:     { label: '成员',   group: '身份',     probe: '/member/leaderboard' },
    content:    { label: '内容',   group: '业务',     probe: '/content/stats' },
    release:    { label: '版本',   group: '元层',     probe: '/release/status', auth: true },
    theme:      { label: '主题',   group: '承载',     probe: '/system/info' },
    comment:    { label: '评论',   group: '业务',     probe: '/comments', query: '?post_slug=first-post' },
    page:       { label: '页面',   group: '承载',     probe: '/system/layers' },
    attachment: { label: '附件',   group: '业务',     probe: '/attachments', query: '?post_slug=first-post' },
    emoji:      { label: '表情',   group: '业务',     probe: '/emojis' }
  };
  var FALLBACK = { label: '系统层', group: '其它', probe: '/system/info' };

  // 主题管理分区：路径逐条对应 api/registry.json 既有登记；主题包只消费、不臆造接口。
  var THEME_PATHS = {
    list:     { path: '/themes' },
    activate: { path: '/themes/active' }
  };

  // 分区探针：path 逐条对应 registry 的既有登记；auth=true 的接口需 Bearer 令牌。
  var PROBES = [
    { group: '系统', items: [
      { label: '系统信息',   path: '/system/info' },
      { label: '健康检查',   path: '/system/health' },
      { label: '系统层清单', path: '/system/layers' },
      { label: '契约绑定',   path: '/system/contracts' }
    ]},
    { group: '内容', items: [
      { label: '文章列表',   path: '/content/posts' },
      { label: '文章统计',   path: '/content/stats' },
      { label: '评论树',     path: '/comments', query: '?post_slug=first-post' },
      { label: '附件清单',   path: '/attachments', query: '?post_slug=first-post' },
      { label: '表情目录',   path: '/emojis' }
    ]},
    { group: '身份与权限', items: [
      { label: '权限目录',   path: '/permission/catalog' },
      { label: '角色清单',   path: '/permission/roles' },
      { label: '角色树',     path: '/permission/role/tree' },
      { label: '用户组',     path: '/permission/groups' },
      { label: '当前用户',   path: '/users/me', auth: true }
    ]},
    { group: '成员', items: [
      { label: '排行榜',     path: '/member/leaderboard' },
      { label: '头衔',       path: '/member/titles' },
      { label: '等级',       path: '/member/levels' },
      { label: '经验规则',   path: '/member/xp/rules' }
    ]},
    { group: '运行（需令牌）', items: [
      { label: '装卸单元',   path: '/lifecycle/units', auth: true },
      { label: '日志查询',   path: '/logs/query', auth: true },
      { label: '版本状态',   path: '/release/status', auth: true },
      { label: '版本历史',   path: '/release/history', auth: true },
      { label: '快照清单',   path: '/lifecycle/releases', auth: true },
      { label: '长任务状态', path: '/state/task', auth: true, query: '?id=state.pending' }
    ]}
  ];

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function call(path, query, method, body) {
    var headers = {};
    if (token) headers.Authorization = 'Bearer ' + token;
    var opts = { method: method || 'GET', headers: headers };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(api + path + (query || ''), opts)
      .then(function (r) {
        return r.json().catch(function () { return { _non_json: true, http_status: r.status }; })
          .then(function (body) { return { http_status: r.status, data: body }; });
      })
      .catch(function (e) { return { http_status: 0, data: { error: String(e) } }; });
  }

  function show(label, result) {
    if (!out) return;
    out.textContent = label + '  ·  HTTP ' + result.http_status + '\n' + JSON.stringify(result.data, null, 2);
  }

  function field(result, key, fallback) {
    var body = (result && result.data) || {};
    if (body.data && typeof body.data === 'object') body = body.data;
    return (body[key] !== undefined && body[key] !== null) ? body[key] : fallback;
  }

  function metrics(r) {
    var host = document.getElementById('glass-metrics');
    if (!host) return;
    var tables = field(r[1], 'tables', []);
    var cards = [
      { label: '系统层', value: field(r[0], 'systems', '—'), note: '按契约装配' },
      { label: '接口',   value: field(r[0], 'api_operations', '—'), note: '清单与文档 1:1' },
      { label: '契约',   value: field(r[0], 'contracts', '—'), note: '容器运行时绑定' },
      { label: '库内表', value: Array.isArray(tables) ? tables.length : '—', note: 'SHOW TABLES 返回原值' },
      { label: '权限点', value: field(r[2], 'total', '—'), note: '各层自报' },
      { label: '已发布', value: field(r[3], 'published', '—'), note: '内容系统层' }
    ];
    host.textContent = '';
    cards.forEach(function (c) {
      var box = el('div', 'glass-card metric');
      box.appendChild(el('p', 'metric__label', c.label));
      box.appendChild(el('p', 'metric__value', String(c.value)));
      box.appendChild(el('p', 'metric__note', c.note));
      host.appendChild(box);
    });
    var version = document.getElementById('glass-version');
    if (version) version.textContent = 'core v' + field(r[0], 'core_version', '—') + ' · PHP ' + field(r[0], 'php', '—');
    var db = document.getElementById('glass-db');
    if (db) db.textContent = '数据源 ' + field(r[0], 'database', '—');
    var summary = document.getElementById('glass-summary');
    if (summary) summary.textContent = '主题 ' + String(field(r[0], 'theme_active', 'null'));
  }

  function layers(result) {
    var host = document.getElementById('glass-layers');
    var count = document.getElementById('glass-layer-count');
    if (!host) return;
    var body = (result && result.data) || {};
    if (body.data && typeof body.data === 'object') body = body.data;
    var order = Array.isArray(body.order) ? body.order : (Array.isArray(body.manifests) ? body.manifests : []);
    host.textContent = '';
    if (order.length === 0) {
      host.appendChild(el('p', 'glass-muted', '未取得系统层清单，请检查 /system/layers。'));
      if (count) count.textContent = '0 个';
      return;
    }
    if (count) count.textContent = order.length + ' 个已装配';
    order.forEach(function (slug, i) {
      var known = Object.prototype.hasOwnProperty.call(LAYER_META, slug);
      var meta = known ? LAYER_META[slug] : FALLBACK;
      var name = known ? meta.label : String(slug);
      var card = el('article', 'glass-card layer-card');
      var top = el('div', 'layer-card__top');
      top.appendChild(el('span', 'layer-card__idx', ('0' + (i + 1)).slice(-2)));
      top.appendChild(el('span', 'layer-card__group', meta.group));
      card.appendChild(top);
      card.appendChild(el('h3', 'layer-card__name', name));
      card.appendChild(el('p', 'layer-card__probe', api + meta.probe));
      var btn = el('button', 'layer-card__btn', '查看数据');
      btn.type = 'button';
      btn.addEventListener('click', function () {
        btn.classList.add('is-busy');
        call(meta.probe, meta.query).then(function (res) {
          show('系统层 · ' + name + '  ' + api + meta.probe, res);
          btn.classList.remove('is-busy');
        });
      });
      card.appendChild(btn);
      host.appendChild(card);
    });
  }

  function probes() {
    var host = document.getElementById('glass-probes');
    var counter = document.getElementById('glass-probe-count');
    if (!host) return;
    var total = 0;
    host.textContent = '';
    PROBES.forEach(function (group) {
      var box = el('div', 'probe-group');
      box.appendChild(el('h3', '', group.group));
      var items = el('div', 'probe-group__items');
      group.items.forEach(function (p) {
        total++;
        var btn = el('button', 'probe-btn', p.label);
        btn.type = 'button';
        if (p.auth) btn.setAttribute('data-auth', 'true');
        btn.addEventListener('click', function () {
          btn.classList.add('is-busy');
          call(p.path, p.query).then(function (res) {
            show(p.label + '  ' + api + p.path + (p.query || ''), res);
            btn.classList.remove('is-busy');
          });
        });
        items.appendChild(btn);
      });
      box.appendChild(items);
      host.appendChild(box);
    });
    if (counter) counter.textContent = total + ' 个既有接口';
  }

  function refresh() {
    Promise.all([
      call('/system/info'),
      call('/system/health'),
      call('/permission/catalog'),
      call('/content/stats'),
      call('/system/layers')
    ]).then(function (r) {
      metrics(r);
      layers(r[4]);
    });
  }

  function themeOutput(text) {
    var box = document.getElementById('glass-theme-output');
    if (box) box.textContent = text;
  }

  function themeWriteResult(label, res) {
    themeOutput('HTTP ' + res.http_status + '\n' + JSON.stringify(res.data, null, 2));
    show(label, res);
    return res.http_status === 200 && res.data && res.data.code === 0;
  }

  function themes(result) {
    var host = document.getElementById('glass-themes');
    var badge = document.getElementById('glass-theme-active');
    if (!host) return;
    var body = (result && result.data) || {};
    if (body.data && typeof body.data === 'object') body = body.data;
    var items = Array.isArray(body.items) ? body.items : [];
    if (badge) badge.textContent = '当前 ' + (body.active || 'Headless（无主题）')
      + ' · ' + (body.total != null ? body.total : 0) + ' 款';
    host.textContent = '';
    items.forEach(function (t) {
      var card = el('article', 'glass-card theme-card');
      if (t.active) card.classList.add('is-active');
      var top = el('div', 'theme-card__top');
      top.appendChild(el('span', 'theme-card__slug', String(t.slug || '')));
      top.appendChild(el('span', 'theme-card__badge',
        t.active ? '启用中' : (t.meta ? '元主题' : '示例主题')));
      card.appendChild(top);
      card.appendChild(el('p', 'theme-card__meta',
        (t.surface || '—') + ' · ' + (t.family || '—') + ' · v' + String(t.version || '—')));
      card.appendChild(el('p', 'theme-card__layouts',
        '布局 ' + (Array.isArray(t.layouts) ? t.layouts.join(' / ') : '—')));
      var btn = el('button', 'theme-card__btn', t.active ? '已启用' : '启用');
      btn.type = 'button';
      btn.disabled = !!t.active;
      btn.setAttribute('data-auth', 'true');
      btn.addEventListener('click', function () {
        if (!token) { themeOutput('请先在右上角填写 Bearer 令牌（admin）。'); return; }
        btn.classList.add('is-busy');
        call(THEME_PATHS.activate.path, '', 'POST', { slug: t.slug }).then(function (res) {
          var ok = themeWriteResult('启用主题 · ' + String(t.slug), res);
          btn.classList.remove('is-busy');
          if (ok) refreshThemes();
        });
      });
      card.appendChild(btn);
      host.appendChild(card);
    });
  }

  function refreshThemes() {
    call(THEME_PATHS.list.path).then(themes);
  }

  var headlessBtn = document.getElementById('glass-theme-headless');
  if (headlessBtn) headlessBtn.addEventListener('click', function () {
    if (!token) { themeOutput('请先在右上角填写 Bearer 令牌（admin）。'); return; }
    headlessBtn.classList.add('is-busy');
    call(THEME_PATHS.activate.path, '', 'POST', { slug: '' }).then(function (res) {
      var ok = themeWriteResult('停用主题 · 回到 Headless', res);
      headlessBtn.classList.remove('is-busy');
      if (ok) refreshThemes();
    });
  });

  var tokenInput = document.getElementById('glass-token');
  if (tokenInput) tokenInput.addEventListener('input', function () { token = tokenInput.value.trim(); });
  var refreshBtn = document.getElementById('glass-refresh');
  if (refreshBtn) refreshBtn.addEventListener('click', refresh);
  var clearBtn = document.getElementById('glass-clear');
  if (clearBtn) clearBtn.addEventListener('click', function () { if (out) out.textContent = '已清空。'; });
  var nav = document.querySelectorAll('[data-nav]');
  Array.prototype.forEach.call(nav, function (a) {
    a.addEventListener('click', function () {
      Array.prototype.forEach.call(nav, function (x) { x.classList.remove('is-active'); });
      a.classList.add('is-active');
      var heading = document.getElementById('dash-heading');
      if (heading) heading.textContent = a.textContent;
    });
  });
  probes();
  refreshThemes();
  refresh();
})();
