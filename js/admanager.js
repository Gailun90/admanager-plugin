/* ============================================================
   admanager 插件 — 全局脚本 v5.0
   统一 fetch 拦截 + 异步加载 + Toast + 响应处理
   ============================================================ */

/* ─────────────────────────────────────────────────────────
   0. 全局 fetch 拦截：自动 credentials + session 过期检测
   所有 fetch 调用自动带上 credentials: 'same-origin'
   无需每个页面单独配置
   ───────────────────────────────────────────────────────── */
(function() {
    const _fetch = window.fetch;
    window.fetch = function(url, opts) {
        opts = opts || {};
        if (!opts.credentials) opts.credentials = 'same-origin';
        return _fetch(url, opts).then(function(r) {
            // GLPI 框架在 include 阶段就已发送 HTML 头，header() 无法覆盖 Content-Type。
            // 不能靠 Content-Type 判断，改为读取 body text，看是否以 { 开头。
            // 只对 POST 请求或 ajax 端点做检查（GET 页面导航不拦截）。
            var isAjax = (typeof url === 'string') &&
                (url.indexOf('ajax') !== -1 || url.indexOf('.form.php') !== -1);
            var method = (opts.method || 'GET').toUpperCase();
            if (isAjax || method === 'POST') {
                return r.clone().text().then(function(text) {
                    // 尝试 JSON.parse：能解析成功就是合法 JSON
                    // 比首字符判断更稳（兼容 JSON 前有 PHP warning 输出的情况）
                    try {
                        JSON.parse(text.trim());
                        return r; // 是合法 JSON，返回原始 response 供 .json() 解析
                    } catch(e) {
                        /* 不是 JSON → session 过期 */;
                    }
                    // 不是 JSON（HTML 登录页或错误页）→ session 过期
                    return {
                        ok: false,
                        status: r.status,
                        json: function() {
                            return Promise.resolve({ ok: false, message: 'Session 已过期，请刷新页面重新登录' });
                        }
                    };
                });
            }
            return r;
        });
    };
})();

/* ─────────────────────────────────────────────────────────
   1. Toast 通知系统
   ───────────────────────────────────────────────────────── */
(function() {
    var container = null;
    function getContainer() {
        if (container) return container;
        container = document.createElement('div');
        container.id = 'ad_toast_container';
        container.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:1080;min-width:280px;max-width:400px';
        document.body.appendChild(container);
        return container;
    }

    window.showToast = function(msg, type) {
        type = type || 'success';
        var bg = type === 'danger' ? 'bg-danger' : 'bg-success';
        var icon = type === 'danger' ? 'ti-alert-circle' : 'ti-circle-check';
        var wrap = getContainer();
        var toast = document.createElement('div');
        toast.className = 'toast show ' + bg + ' text-white';
        toast.innerHTML = '<div class="toast-body"><i class="ti ' + icon + ' me-2"></i>' + escHtml(msg) + '</div>';
        wrap.appendChild(toast);
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 300);
        }, 4000);
    };

    function escHtml(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
})();

/* ─────────────────────────────────────────────────────────
   2. 异步加载工具
   用法：asyncLoad({url, container, render, onError, skeleton})
   ───────────────────────────────────────────────────────── */
window.asyncLoad = function(opts) {
    var container = typeof opts.container === 'string'
        ? document.getElementById(opts.container)
        : opts.container;
    if (!container) return;

    // 显示骨架屏
    if (opts.skeleton) {
        container.innerHTML = opts.skeleton;
    } else {
        container.innerHTML = '<div class="ad-loading text-center py-4"><div class="spinner-border spinner-border-sm text-primary me-2"></div><span class="text-muted">加载中...</span></div>';
    }

    fetch(opts.url, { headers: opts.headers || {} })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (opts.render) {
                opts.render(data, container);
            }
        })
        .catch(function(err) {
            if (opts.onError) {
                opts.onError(err, container);
            } else {
                container.innerHTML = '<div class="alert alert-warning py-2 small text-center">加载失败: ' + escHtml(err.message) + '</div>';
            }
        });
};

/* ─────────────────────────────────────────────────────────
   3. HTML 转义（防 XSS）
   ───────────────────────────────────────────────────────── */
window.escHtml = function(s) {
    if (s == null || s === '') return '—';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
};

/* ─────────────────────────────────────────────────────────
   4. JS 属性转义
   ───────────────────────────────────────────────────────── */
window.escJs = function(s) {
    return String(s || '').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
};

/* ─────────────────────────────────────────────────────────
   5. 格式化工具
   ───────────────────────────────────────────────────────── */
window.formatBytes = function(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
};

window.formatDate = function(iso) {
    if (!iso) return '—';
    return iso.substring(0, 16).replace('T', ' ');
};

/* ============================================================
   以下是页面特定功能 — 仅在对应 DOM 存在时激活
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {
    /* Auto-close Bootstrap toasts */
    document.querySelectorAll('.toast.show').forEach(function(el) {
        setTimeout(function() { el.classList.remove('show'); }, 4000);
    });
    /* Initialize tooltips */
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
});
