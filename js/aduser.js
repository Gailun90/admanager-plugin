/* admanager AD用户页专属脚本 */
document.addEventListener('DOMContentLoaded', function() {
    window.adUserAction = function(action, dn, name, extra) {
        var msg = '确认' + action + '用户 ' + name + '？';
        if (!confirm(msg)) return;
        var form = new FormData();
        form.append('action', action);
        form.append('dn', dn);
        form.append('name', name);
        if (extra) {
            Object.keys(extra).forEach(function(k) { form.append(k, extra[k]); });
        }
        form.append('_glpi_csrf_token', document.querySelector('[name="_glpi_csrf_token"]') ? document.querySelector('[name="_glpi_csrf_token"]').value : '');
        fetch('/plugins/admanager/front/aduser.form.php', { method: 'POST', body: form })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                window.showToast(data.message, data.ok ? 'success' : 'danger');
                if (data.ok) setTimeout(function() { location.reload(); }, 1500);
            })
            .catch(function(err) { window.showToast('请求失败: ' + err.message, 'danger'); });
    };

    window.adImportUser = function(sam, dn) {
        if (!confirm('确认将 AD 用户 "' + sam + '" 导入为 GLPI 用户？')) return;
        var form = new FormData();
        form.append('sam', sam);
        form.append('dn', dn);
        var csrf = (document.querySelector('[name="_glpi_csrf_token"]') || {}).value || '';
        form.append('_glpi_csrf_token', csrf);
        fetch('/plugins/admanager/ajax/import_aduser.php', {
          method: 'POST',
          body: form,
          headers: { 'X-Glpi-Csrf-Token': csrf }
        })
            .then(function(r) { return r.json(); })
            .then(function(data) { window.showToast(data.message, data.ok ? 'success' : 'danger'); })
            .catch(function(err) { window.showToast('导入失败: ' + err.message, 'danger'); });
    };

    var moveOuBtn = document.getElementById('move-ou-btn');
    if (moveOuBtn) {
        moveOuBtn.addEventListener('click', function() {
            var dn = prompt('输入目标 OU 的 DN:');
            var name = moveOuBtn.dataset.name || '';
            if (dn) window.adUserAction('move_ou', moveOuBtn.dataset.dn || '', name);
        });
    }
});
