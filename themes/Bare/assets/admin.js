/* admin 布局交互脚本 —— 归属主题包，独立页不持有任何 JS */
(function () {
  var api = document.body.dataset.api || '/api/v1';
  function set(id, text) { var el = document.getElementById(id); if (el) el.textContent = text; }
  function get(path, token) {
    var headers = token ? { Authorization: 'Bearer ' + token } : {};
    return fetch(api + path, { headers: headers }).then(function (r) { return r.json(); });
  }
  get('/system/info')
    .then(function (d) { set('health', JSON.stringify(d.data || d, null, 2)); })
    .catch(function (e) { set('health', '读取失败：' + e.message); });
  get('/content/posts')
    .then(function (d) {
      var el = document.getElementById('posts');
      var items = (d.data && d.data.items) || [];
      if (!el) return;
      el.innerHTML = '';
      items.forEach(function (p) {
        var li = document.createElement('li');
        li.textContent = p.title;
        el.appendChild(li);
      });
    })
    .catch(function () {});
  var refresh = document.getElementById('refresh');
  if (refresh) refresh.addEventListener('click', function () {
    var field = document.getElementById('token');
    var token = field ? field.value.trim() : '';
    if (!token) { set('protected', '请先填写令牌'); return; }
    get('/lifecycle/units', token)
      .then(function (d) { set('protected', JSON.stringify(d, null, 2)); })
      .catch(function (e) { set('protected', '读取失败：' + e.message); });
  });
})();
