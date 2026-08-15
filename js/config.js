/* admanager 配置页专属脚本 — 统一承载“测试连接”逻辑，模板内联不再重复实现 */
(function () {
  'use strict';

  // CSRF token 统一走 adm-utils.js 的 AdManager.getCsrfToken() / renewCsrf()
  function resultEl(spanId) {
    return spanId ? document.getElementById(spanId) : null;
  }
  function paint(spanId, ok, msg) {
    var el = resultEl(spanId);
    if (!el) return;
    el.innerHTML = (ok
      ? '<span class="text-success"><i class="ti ti-check me-1"></i>'
      : '<span class="text-danger"><i class="ti ti-x me-1"></i>') + msg + '</span>';
  }

  // AD / FastAPI / AI 网关：test_connection.php?type=
  window.testConn = function (type, spanId) {
    var el = resultEl(spanId);
    if (el) el.innerHTML = '<span class="text-muted">测试中...</span>';
    fetch('/plugins/admanager/ajax/test_connection.php?type=' + type)
      .then(function (r) { return r.json(); })
      .then(function (d) { paint(spanId, d.ok, d.message); })
      .catch(function () { paint(spanId, false, '请求失败'); });
  };

  // 通讯平台 / 邮件：im.php?action=test_connect（带 CSRF 续签，与全站一致）
  function imFetch(body) {
    return fetch('/plugins/admanager/ajax/im.php?action=test_connect', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': AdManager.getCsrfToken()
      },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().then(function (d) {
        // 续签一次性 CSRF token（GLPI 合规做法；统一走 AdManager.renewCsrf）
        AdManager.renewCsrf(d);
        return d;
      });
    });
  }
  window.testIMConn = function (platform, spanId) {
    var el = resultEl(spanId);
    if (el) el.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>测试中...</span>';
    imFetch({ platform: platform })
      .then(function (d) { paint(spanId, d.ok, d.message); })
      .catch(function () { paint(spanId, false, '请求失败，请检查网络'); });
  };
  window.testMailConn = function (spanId) {
    var el = resultEl(spanId);
    if (el) el.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>发送测试邮件...</span>';
    imFetch({ platform: 'mail' })
      .then(function (d) { paint(spanId, d.ok, d.message); })
      .catch(function () { paint(spanId, false, '请求失败'); });
  };
})();
