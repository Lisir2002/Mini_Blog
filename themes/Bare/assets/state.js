/* 状态反馈的渐进增强 —— 没有这段脚本时，状态依然可读、动作依然是真实表单。 */
(function () {
  'use strict';

  // 提交中的表单立刻进入进行态：防重复提交，并让读屏器知道正在忙。
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.stateSubmitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.stateSubmitting = '1';
    form.setAttribute('aria-busy', 'true');
    var buttons = form.querySelectorAll('button[type="submit"]');
    for (var i = 0; i < buttons.length; i++) buttons[i].disabled = true;
  });

  // 纯前端动作（无 href）只做一次焦点归位，不伪造后端结果。
  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) return;
    var action = target.closest('[data-state-action]');
    if (!(action instanceof HTMLElement)) return;
    if (action.hasAttribute('data-state-href')) {
      window.location.assign(action.getAttribute('data-state-href'));
      return;
    }
    action.setAttribute('aria-busy', 'true');
  });
})();
