/**
 * ad_sync.js — AD 数据同步共享逻辑
 * 被 config.html.twig 和 aduser_list.html.twig 引入，提供 triggerSync() 和同步状态轮询
 */
(function () {
  'use strict';

  var _syncPollTimer = null;
  var _csrf = '';

  /** 获取 CSRF token */
  function getCsrf() {
    if (_csrf) return _csrf;
    var el = document.querySelector('[name="_glpi_csrf_token"]');
    _csrf = el ? el.value : '';
    return _csrf;
  }

  /* ────────────────────────── 同步状态轮询 ────────────────────────── */

  function pollStatus() {
    fetch('/plugins/admanager/ajax/ad_sync.php?action=status')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        /* 统计数字 */
        var su = document.getElementById('syncStatUsers');
        var sc = document.getElementById('syncStatComputers');
        var sd = document.getElementById('syncStatDuration');
        if (su) su.textContent = d.user_count ?? '—';
        if (sc) sc.textContent = d.computer_count ?? '—';
        if (sd) sd.textContent = d.duration_sec ?? '—';

        /* 状态徽章 */
        var badge = document.getElementById('syncStatusBadge');
        if (badge) {
          if (d.syncing) {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = '同步中…';
            /* 同步中每 3 秒轮询 */
            startPolling();
          } else if (!d.last_sync) {
            badge.className = 'badge bg-danger';
            badge.textContent = '从未同步';
            stopPolling();
          } else if (d.needs_sync) {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = '需要同步';
            stopPolling();
          } else {
            badge.className = 'badge bg-success';
            badge.textContent = '缓存正常';
            stopPolling();
          }
        }

        /* 元信息 */
        var meta = document.getElementById('syncMeta');
        if (meta) {
          meta.textContent = d.last_sync_fmt
            ? '上次同步：' + d.last_sync_fmt + ' 触发：' + (d.triggered_by || '—')
            : '尚未执行过同步';
        }
      })
      .catch(function () {
        /* 静默失败，不影响页面其他功能 */
      });
  }

  function startPolling() {
    stopPolling();
    _syncPollTimer = setInterval(pollStatus, 3000);
  }

  function stopPolling() {
    if (_syncPollTimer) {
      clearInterval(_syncPollTimer);
      _syncPollTimer = null;
    }
  }

  /* ────────────────────────── 手动同步 ────────────────────────── */

  window.triggerSync = function () {
    var csrf = getCsrf();
    var btn = document.getElementById('syncBtn');
    if (!btn) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i>同步中…';

    var fd = new FormData();
    fd.append('_glpi_csrf_token', csrf);
    fd.append('action', 'sync');

    fetch('/plugins/admanager/ajax/ad_sync.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-Glpi-Csrf-Token': csrf }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        /* 结果消息 */
        var msg = document.getElementById('syncResultMsg');
        if (msg) {
          msg.className = 'py-1 px-2 small ' + (d.ok ? 'alert-success' : 'alert-danger');
          msg.textContent = d.message || '';
          msg.parentElement.style.display = '';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-refresh me-1"></i>立即同步';
        pollStatus();
      })
      .catch(function () {
        var msg = document.getElementById('syncResultMsg');
        if (msg) {
          msg.className = 'py-1 px-2 small alert-danger';
          msg.textContent = '请求失败';
          msg.parentElement.style.display = '';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-refresh me-1"></i>立即同步';
      });
  };

  /* ────────────────────────── 初始化 ────────────────────────── */

  /* 当页面存在同步容器时，自动开始轮询 */
  function initSyncIfAvailable() {
    if (document.getElementById('syncPanelContainer')) {
      pollStatus();
    }
  }

  /* DOMContentLoaded 后检测 */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSyncIfAvailable);
  } else {
    initSyncIfAvailable();
  }

  /* 对外暴露 stopPolling */
  window.stopSyncPolling = stopPolling;

})();
