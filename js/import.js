/* admanager 终端导入页脚本 — 含批量并发删除 / 清空全部（修复“删除终端非常慢”） */
document.addEventListener('DOMContentLoaded', function() {
    var selectAllCb  = document.getElementById('select-all-clients');
    var bulkBtn      = document.getElementById('bulkDeleteBtn');
    var deleteAllBtn = document.getElementById('deleteAllBtn');
    var csrfInput    = document.querySelector('input[name=_glpi_csrf_token]');

    function getChecked() {
        return Array.prototype.slice.call(document.querySelectorAll('.client-checkbox:checked'));
    }
    function refreshBulkState() {
        if (bulkBtn) bulkBtn.disabled = getChecked().length === 0;
        if (selectAllCb) {
            var all = document.querySelectorAll('.client-checkbox');
            selectAllCb.checked = all.length > 0 && getChecked().length === all.length;
        }
    }

    if (selectAllCb) {
        selectAllCb.addEventListener('change', function() {
            document.querySelectorAll('.client-checkbox').forEach(function(cb) {
                cb.checked = selectAllCb.checked;
            });
            refreshBulkState();
        });
    }
    document.querySelectorAll('.client-checkbox').forEach(function(cb) {
        cb.addEventListener('change', refreshBulkState);
    });
    refreshBulkState();

    // 以 AJAX 提交批量操作（服务端返回 JSON，避免整页刷新）
    function postBulk(action, payload) {
        if (!csrfInput) { window.showToast('页面缺少 CSRF Token', 'danger'); return; }
        var fd = new URLSearchParams();
        fd.append('action', action);
        fd.append('_glpi_csrf_token', csrfInput.value);
        Object.keys(payload).forEach(function(k) {
            var v = payload[k];
            if (Array.isArray(v)) {
                v.forEach(function(item) { if (item !== '' && item !== null) fd.append(k + '[]', item); });
            } else if (v !== '' && v !== null) {
                fd.append(k, v);
            }
        });
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: fd.toString()
        })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function(data) {
            window.showToast(data.message || (data.ok ? '操作成功' : '操作失败'), data.ok ? 'success' : 'danger');
            setTimeout(function() { location.reload(); }, 1200);
        })
        .catch(function(err) {
            window.showToast('操作失败：' + err.message, 'danger');
        });
    }

    if (bulkBtn) {
        bulkBtn.addEventListener('click', function() {
            var checked = getChecked();
            if (!checked.length) return;
            if (!confirm('确定要删除选中的 ' + checked.length + ' 台终端吗？\n将从 FastAPI 与本地同步状态中删除，不可恢复！')) return;
            var ids = [], serials = [], bios = [];
            checked.forEach(function(cb) {
                if (cb.dataset.clientId) ids.push(cb.dataset.clientId);
                if (cb.dataset.serial)    serials.push(cb.dataset.serial);
                if (cb.dataset.bios)      bios.push(cb.dataset.bios);
            });
            postBulk('bulk_delete', { del_ids: ids, del_serials: serials, del_bios: bios });
        });
    }

    if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', function() {
            if (!confirm('⚠ 确定要清空【全部】已注册终端吗？\n将删除 FastAPI 中所有客户端及本地同步记录，此操作不可恢复！')) return;
            postBulk('delete_all', {});
        });
    }
});
