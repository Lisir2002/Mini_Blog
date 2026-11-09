/* tool 布局交互脚本 —— 归属主题包 */
(function () {
  var input = document.getElementById('tool-input');
  var out = document.getElementById('tool-out');
  function apply(fn) { if (input && out) out.textContent = fn(input.value); }
  var upper = document.getElementById('tool-upper');
  var lower = document.getElementById('tool-lower');
  var clear = document.getElementById('tool-clear');
  if (upper) upper.addEventListener('click', function () { apply(function (s) { return s.toUpperCase(); }); });
  if (lower) lower.addEventListener('click', function () { apply(function (s) { return s.toLowerCase(); }); });
  if (clear) clear.addEventListener('click', function () { if (input) input.value = ''; if (out) out.textContent = ''; });
})();
