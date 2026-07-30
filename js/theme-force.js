/* ============================================================
   admanager — 强制亮色主题 (theme-force.js)
   ------------------------------------------------------------
   双保险之一（另一为 css/theme-force.css）。
   作用：无论 GLPI / Tabler 何时把页面切到暗色，都立即纠正为亮色：
     1. 强制 <html> data-bs-theme="light"
     2. 移除 <html>/<body> 上的 .dark-mode 类
     3. 清除本地存储里的暗色主题键，避免刷新后复发
     4. MutationObserver 监听 <html> 属性变化，任何回弹都被即时纠正
   无插件 DOM 依赖，可全局安全加载。
   ============================================================ */
(function () {
    'use strict';

    function forceLight() {
        var de = document.documentElement;
        if (!de) return;

        // 1. 强制亮色主题属性
        if (de.getAttribute('data-bs-theme') === 'dark') {
            de.setAttribute('data-bs-theme', 'light');
        }
        // 2. 移除暗色类
        de.classList.remove('dark-mode');
        if (document.body) document.body.classList.remove('dark-mode');

        // 3. 清除本地存储中的暗色主题键
        try {
            var keys = ['tablerTheme', 'theme', 'color-theme', 'glpi-theme', 'palette'];
            for (var i = 0; i < keys.length; i++) {
                var v = localStorage.getItem(keys[i]);
                if (v === 'dark' || /dark/i.test(v || '')) {
                    localStorage.setItem(keys[i], 'light');
                }
            }
        } catch (e) { /* localStorage 不可用时忽略 */ }
    }

    // 立即执行（head 中加载时 DOM 可能未就绪，但 <html> 已存在）
    forceLight();

    // DOM 就绪后再执行一次，覆盖依赖 DOM 的初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', forceLight);
    } else {
        forceLight();
    }

    // 4. 监听 <html> 属性变化，防回弹
    try {
        if ('MutationObserver' in window) {
            var obs = new MutationObserver(function () { forceLight(); });
            obs.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-bs-theme', 'class']
            });
        }
    } catch (e) { /* 极老浏览器不支持时退化为只执行前两步 */ }
})();
