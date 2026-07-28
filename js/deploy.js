/* admanager 部署页专属脚本 */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(el.dataset.confirm || '确认执行此操作？')) e.preventDefault();
        });
    });
});
