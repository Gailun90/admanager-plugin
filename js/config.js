/* admanager 配置页专属脚本 */
document.addEventListener('DOMContentLoaded', function() {
    var testAdBtn = document.getElementById('test-ad-btn');
    var testApiBtn = document.getElementById('test-api-btn');
    var testConnection = function(type, btn) {
        if (!btn) return;
        var origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>测试中...';
        fetch('/plugins/admanager/ajax/test_connection.php?type=' + type)
            .then(function(r) { return r.json(); })
            .then(function(data) { window.showToast(data.message, data.ok ? 'success' : 'danger'); })
            .catch(function(err) { window.showToast('请求失败: ' + err.message, 'danger'); })
            .finally(function() { btn.disabled = false; btn.innerHTML = origText; });
    };
    if (testAdBtn) testAdBtn.addEventListener('click', function() { testConnection('ad', testAdBtn); });
    if (testApiBtn) testApiBtn.addEventListener('click', function() { testConnection('fastapi', testApiBtn); });
});
