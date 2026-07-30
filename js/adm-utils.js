/**
 * admanager 全局工具库 v2.0.0
 * 新增: Sidebar 自动转换、TableSort/Filter、Progress 进度组件
 */
window.AdManager = window.AdManager || {};

/* ============================================
   Toast 消息
   ============================================ */
AdManager.Toast = (function() {
    var container = null;
    function ensureContainer() {
        if (!container) {
            container = document.createElement('div');
            container.className = 'adm-toast-container';
            document.body.appendChild(container);
        }
    }
    function show(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        ensureContainer();
        var icons = {success: 'ti-check', error: 'ti-x', warning: 'ti-alert-triangle', info: 'ti-info-circle'};
        var toast = document.createElement('div');
        toast.className = 'adm-toast adm-toast-' + type;
        toast.innerHTML = '<i class="ti ' + (icons[type] || icons.info) + ' adm-toast-icon"></i>' +
            '<div class="adm-toast-content"><div class="adm-toast-message">' + message + '</div></div>' +
            '<button class="adm-toast-close" onclick="this.parentElement.remove()"><i class="ti ti-x"></i></button>';
        container.appendChild(toast);
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() { toast.remove(); }, 300);
        }, duration);
    }
    return {
        success: function(msg, d) { show(msg, 'success', d); },
        error: function(msg, d) { show(msg, 'error', d); },
        warning: function(msg, d) { show(msg, 'warning', d); },
        info: function(msg, d) { show(msg, 'info', d); }
    };
})();

/* ============================================
   Confirm 确认对话框
   ============================================ */
AdManager.Confirm = function(message, onConfirm, onCancel) {
    var modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.innerHTML = '<div class="modal-dialog modal-sm"><div class="modal-content">' +
        '<div class="modal-header"><h5 class="modal-title">确认操作</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
        '<div class="modal-body"><p>' + message + '</p></div>' +
        '<div class="modal-footer">' +
        '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">取消</button>' +
        '<button type="button" class="btn btn-danger btn-sm" id="confirmBtn">确认</button></div></div></div>';
    document.body.appendChild(modal);
    var bsModal = new bootstrap.Modal(modal);
    modal.querySelector('#confirmBtn').addEventListener('click', function() { bsModal.hide(); onConfirm && onConfirm(); });
    modal.addEventListener('hidden.bs.modal', function() { modal.remove(); onCancel && onCancel(); });
    bsModal.show();
};

/* ============================================
   Sidebar 自动转换 (v2.0 新增)
   自动将 .adm-page 内的 nav.nav-tabs 转换为左侧边栏
   ============================================ */
AdManager.Sidebar = (function() {
    function init() {
        var pages = document.querySelectorAll('.adm-page');
        pages.forEach(function(page) {
            // 查找直接子级 nav-tabs
            var nav = null;
            for (var i = 0; i < page.children.length; i++) {
                if (page.children[i].classList.contains('nav') && page.children[i].classList.contains('nav-tabs')) {
                    nav = page.children[i];
                    break;
                }
            }
            if (!nav) return;
            // 跳过内容切换型 tab (data-bs-toggle)
            if (nav.querySelector('[data-bs-toggle="tab"]')) return;
            // 跳过已转换
            if (nav.dataset.admConverted === '1') return;
            convertToSidebar(page, nav);
        });
    }

    function convertToSidebar(page, nav) {
        nav.dataset.admConverted = '1';

        // 创建布局容器
        var layout = document.createElement('div');
        layout.className = 'adm-sidebar-layout';

        // 创建侧边栏
        var sidebar = document.createElement('aside');
        sidebar.className = 'adm-sidebar';

        // 转换 nav 样式
        nav.classList.remove('nav', 'nav-tabs', 'mb-3');
        nav.classList.add('adm-sidebar-nav');

        // 转换每个链接
        var links = nav.querySelectorAll('a.nav-link');
        links.forEach(function(link) {
            link.classList.remove('nav-link');
            link.classList.add('adm-sidebar-link');
            if (link.classList.contains('active')) {
                link.classList.add('adm-sidebar-active');
            }
        });

        // 移除 nav-item 包装（保留 a 标签）
        var items = nav.querySelectorAll('.nav-item');
        items.forEach(function(item) {
            while (item.firstChild) {
                nav.insertBefore(item.firstChild, item);
            }
            item.remove();
        });

        sidebar.appendChild(nav);

        // 创建内容区
        var content = document.createElement('div');
        content.className = 'adm-sidebar-content';

        // 将 nav 之后的所有兄弟元素移入内容区
        var nextSibling = nav.nextSibling;
        while (nextSibling) {
            var toMove = nextSibling;
            nextSibling = nextSibling.nextSibling;
            if (toMove.nodeType === 1 || (toMove.nodeType === 3 && toMove.textContent.trim())) {
                content.appendChild(toMove);
            }
        }

        layout.appendChild(sidebar);
        layout.appendChild(content);
        page.appendChild(layout);
    }

    return { init: init };
})();

/* ============================================
   TableSort 表格排序 (v2.0 新增)
   ============================================ */
AdManager.TableSort = (function() {
    function init(selector) {
        var tables = document.querySelectorAll(selector || 'table.table');
        tables.forEach(function(table) {
            if (table.dataset.admSort === '1') return;
            var thead = table.querySelector('thead');
            if (!thead) return;
            var ths = thead.querySelectorAll('th');
            ths.forEach(function(th, idx) {
                // 跳过操作列（通常最后一列含按钮）
                if (th.classList.contains('adm-no-sort')) return;
                th.classList.add('adm-sortable');
                th.dataset.col = idx;
                th.addEventListener('click', function() { sort(table, idx, th); });
            });
            table.dataset.admSort = '1';
        });
    }

    function sort(table, colIdx, th) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr'));

        // 判断排序方向
        var isAsc = th.classList.contains('adm-sort-asc');
        // 清除其他列的排序状态
        table.querySelectorAll('th').forEach(function(t) {
            t.classList.remove('adm-sort-asc', 'adm-sort-desc');
        });
        if (isAsc) {
            th.classList.add('adm-sort-desc');
        } else {
            th.classList.add('adm-sort-asc');
        }

        rows.sort(function(a, b) {
            var aVal = getCellValue(a, colIdx);
            var bVal = getCellValue(b, colIdx);
            // 数值比较
            var aNum = parseFloat(aVal);
            var bNum = parseFloat(bVal);
            if (!isNaN(aNum) && !isNaN(bNum) && aVal.match(/^[\d.,]+$/)) {
                return isAsc ? bNum - aNum : aNum - bNum;
            }
            // 字符串比较
            return isAsc ? bVal.localeCompare(aVal, 'zh') : aVal.localeCompare(bVal, 'zh');
        });

        rows.forEach(function(row) { tbody.appendChild(row); });
    }

    function getCellValue(row, colIdx) {
        var cell = row.cells[colIdx];
        if (!cell) return '';
        return cell.textContent.trim();
    }

    return { init: init };
})();

/* ============================================
   TableFilter 表格筛选 (v2.0 新增)
   ============================================ */
AdManager.TableFilter = (function() {
    function init(selector) {
        var tables = document.querySelectorAll(selector || 'table.table');
        tables.forEach(function(table) {
            if (table.dataset.admFilter === '1') return;
            if (table.classList.contains('adm-no-filter')) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            if (tbody.querySelectorAll('tr').length < 3) return; // 少于3行不显示筛选

            // 创建筛选栏
            var wrapper = document.createElement('div');
            wrapper.className = 'adm-table-filter';

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'adm-table-filter-input form-control-sm';
            input.placeholder = '输入关键词筛选...';

            var badge = document.createElement('span');
            badge.className = 'adm-table-filter-badge';

            wrapper.appendChild(input);
            wrapper.appendChild(badge);

            // 插入到表格前面
            table.parentNode.insertBefore(wrapper, table);

            var allRows = Array.from(tbody.querySelectorAll('tr'));
            var total = allRows.length;

            input.addEventListener('input', function() {
                var keyword = input.value.trim().toLowerCase();
                var visible = 0;
                allRows.forEach(function(row) {
                    var text = row.textContent.toLowerCase();
                    if (!keyword || text.indexOf(keyword) !== -1) {
                        row.classList.remove('adm-row-hidden');
                        visible++;
                    } else {
                        row.classList.add('adm-row-hidden');
                    }
                });
                badge.textContent = visible + ' / ' + total;
            });

            // 初始显示总数
            badge.textContent = total + ' / ' + total;
            table.dataset.admFilter = '1';
        });
    }

    return { init: init };
})();

/* ============================================
   Progress 进度反馈组件 (v2.0 新增)
   ============================================ */
AdManager.Progress = (function() {
    var overlay = null;
    var barEl = null;
    var titleEl = null;
    var msgEl = null;
    var logEl = null;
    var percentEl = null;

    function ensureOverlay() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'adm-progress-overlay';
        overlay.innerHTML =
            '<div class="adm-progress-dialog">' +
                '<div class="adm-progress-title"><i class="ti ti-loader ti-spin"></i><span>处理中</span></div>' +
                '<div class="adm-progress-bar-wrapper"><div class="adm-progress-bar"></div></div>' +
                '<div class="adm-progress-info"><span class="adm-progress-percent">0%</span><span class="adm-progress-status">请稍候...</span></div>' +
                '<div class="adm-progress-message"></div>' +
                '<div class="adm-progress-log"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        barEl = overlay.querySelector('.adm-progress-bar');
        titleEl = overlay.querySelector('.adm-progress-title span');
        msgEl = overlay.querySelector('.adm-progress-message');
        logEl = overlay.querySelector('.adm-progress-log');
        percentEl = overlay.querySelector('.adm-progress-percent');
    }

    function show(title, options) {
        ensureOverlay();
        options = options || {};
        titleEl.textContent = title || '处理中';
        msgEl.textContent = '';
        logEl.innerHTML = '';
        logEl.classList.remove('show');
        percentEl.textContent = '0%';
        barEl.style.width = '0%';
        barEl.classList.remove('adm-progress-indeterminate');

        if (options.indeterminate) {
            barEl.classList.add('adm-progress-indeterminate');
            percentEl.textContent = '';
        }
        if (options.log) {
            logEl.classList.add('show');
        }
        overlay.classList.add('show');
    }

    function update(percent, message) {
        ensureOverlay();
        if (percent !== undefined && percent !== null) {
            barEl.classList.remove('adm-progress-indeterminate');
            barEl.style.width = Math.min(100, Math.max(0, percent)) + '%';
            percentEl.textContent = Math.round(percent) + '%';
        }
        if (message) {
            msgEl.textContent = message;
        }
    }

    function log(message) {
        ensureOverlay();
        logEl.classList.add('show');
        var line = document.createElement('div');
        line.textContent = '> ' + message;
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function success(message) {
        ensureOverlay();
        barEl.classList.remove('adm-progress-indeterminate');
        barEl.style.width = '100%';
        percentEl.textContent = '100%';
        titleEl.textContent = '完成';
        if (message) msgEl.textContent = message;
        setTimeout(function() { hide(); }, 1500);
    }

    function error(message) {
        ensureOverlay();
        titleEl.textContent = '失败';
        if (message) msgEl.textContent = message;
        barEl.style.background = 'var(--adm-danger)';
        setTimeout(function() {
            hide();
            barEl.style.background = '';
        }, 3000);
    }

    function hide() {
        if (overlay) {
            overlay.classList.remove('show');
            barEl.style.width = '0%';
            barEl.style.background = '';
        }
    }

    return {
        show: show,
        update: update,
        log: log,
        success: success,
        error: error,
        hide: hide
    };
})();

/* ============================================
   初始化
   ============================================ */
document.addEventListener('DOMContentLoaded', function() {
    // 侧边栏已禁用 — 恢复标签页导航
    // AdManager.Sidebar.init();
    // 自动初始化表格排序
    AdManager.TableSort.init();
    // 自动初始化表格筛选
    AdManager.TableFilter.init();
});

window.toast = AdManager.Toast;
window.admConfirm = AdManager.Confirm;
window.admProgress = AdManager.Progress;
