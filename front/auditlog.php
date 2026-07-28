<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$filters = [
    'action_type' => $_GET['action_type'] ?? '',
    'date_from'   => $_GET['date_from']   ?? '',
    'date_to'     => $_GET['date_to']     ?? '',
];
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;

// ── CSV 导出 ──
if (($_GET['export'] ?? '') === 'csv') {
    $all_logs  = PluginAdmanagerAuditLog::getLogsWithNames($filters, 0, 10000); // 最多导出1万条
    $act_labels = [
        'disable_user'      => '禁用账户',
        'enable_user'       => '启用账户',
        'unlock_user'       => '解锁账户',
        'reset_pwd'         => '重置密码',
        'move_ou'           => '移动OU',
        'add_group'         => '加入组',
        'remove_group'      => '移出组',
        'import_computer'   => '导入终端',
        'import_user'       => '导入用户',
        'deploy_task'       => '创建部署',
        'search_ad_users'   => '搜索用户',
        'create_user'       => '新建用户',
        'create_group'      => '新建安全组',
        'delete_group'      => '删除安全组',
        'upload_package'    => '上传安装包',
        'delete_package'    => '删除安装包',
        'update_package'    => '更新安装包',
        'create_template'   => '创建模板',
        'update_template'   => '更新模板',
        'delete_template'   => '删除模板',
        'im_sync_disable'   => 'IM同步禁用',
        'im_notify'         => 'IM通知',
    ];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    // 写入 BOM 让 Excel 识别 UTF-8
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['时间', '操作人', '操作类型', '目标类型', '目标名称', '目标DN', '结果', '详情/参数']);
    foreach ($all_logs as $log) {
        fputcsv($out, [
            $log['date_mod'],
            ($log['users_id'] ?? 0) > 0 ? ($log['user_name'] ?? '') : '系统',
            $act_labels[$log['action_type']] ?? $log['action_type'],
            $log['target_type'],
            $log['target_name'],
            $log['target_dn'] ?? '',
            $log['result'] ? '成功' : '失败',
            $log['params'] ?? ($log['error_message'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$offset = ($page - 1) * $limit;
$logs   = PluginAdmanagerAuditLog::getLogsWithNames($filters, $limit, $offset);
$total  = PluginAdmanagerAuditLog::countLogs($filters);
$pages  = max(1, (int)ceil($total / $limit));

$action_labels = [
    'disable_user'      => '禁用账户',
    'enable_user'       => '启用账户',
    'unlock_user'       => '解锁账户',
    'reset_pwd'         => '重置密码',
    'move_ou'           => '移动OU',
    'add_group'         => '加入组',
    'remove_group'      => '移出组',
    'import_computer'   => '导入终端',
    'import_user'       => '导入用户',
    'deploy_task'       => '创建部署',
    'search_ad_users'   => '搜索用户',
    'create_user'       => '新建用户',
    'create_group'      => '新建安全组',
    'delete_group'      => '删除安全组',
    'upload_package'    => '上传安装包',
    'delete_package'    => '删除安装包',
    'update_package'    => '更新安装包',
    'create_template'   => '创建模板',
    'update_template'   => '更新模板',
    'delete_template'   => '删除模板',
    'im_sync_disable'   => 'IM同步禁用',
    'im_notify'         => 'IM通知',
    'remote_session'    => '远程桌面',
    'add_member'        => '添加成员',
    'remove_member'     => '移除成员',
    'reset_failed'      => '重置失败任务',
    'update_config'     => '修改配置',
];

// 构建导出链接参数（保留当前筛选条件）
$export_params = http_build_query(array_filter([
    'export'      => 'csv',
    'action_type' => $filters['action_type'],
    'date_from'   => $filters['date_from'],
    'date_to'     => $filters['date_to'],
]));

Html::header('审计日志', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'auditlog');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/auditlog.html.twig', [
    'logs'           => $logs,
    'total'          => $total,
    'page'           => $page,
    'pages'          => $pages,
    'limit'          => $limit,
    'filters'        => $filters,
    'action_labels'  => $action_labels,
    'csrf_token'     => Session::getNewCSRFToken(),
    'export_params'  => $export_params,
]);

Html::footer();
