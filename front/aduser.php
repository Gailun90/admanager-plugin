<?php
/**
 * front/aduser.php — AD用户管理 v4.3
 * 新增：用户创建模板 / 全部可选字段 / 模板选择器
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$keyword  = $_GET['keyword']  ?? '';
$ou       = $_GET['ou']       ?? '';
$detail   = $_GET['detail']   ?? '';
$tpl_id   = (int)($_GET['tpl_id'] ?? 0);

$users    = [];
$detail_user = null;
$ous      = [];
$error    = null;
$result   = null;
$template = null;

// 所有支持的字段名（从 createUser 到 twig 表单）
$all_field_keys = [
    'sn','givenname','mail','department','company','division','title','manager',
    'telephonenumber','mobile','facsimiletelephonenumber','wWWHomePage',
    'streetaddress','l','st','postalcode','co','physicaldeliveryofficename',
    'employeenumber','employeetype','description','info'
];

try {
    $ldap = PluginAdmanagerAdLdap::getInstance();

    // 加载模板数据
    if ($tpl_id > 0) {
        $template = PluginAdmanagerUserTemplate::getTemplateById($tpl_id);
    }

    // 处理新建用户
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        PluginAdmanagerProfile::checkRight('admin', CREATE);
        switch ($_POST['action']) {
            case 'create_user':
                // 基础必填
                $userData = [
                    'samaccountname' => $_POST['samaccountname'] ?? '',
                    'displayname'    => $_POST['displayname'] ?? '',
                    'password'       => $_POST['password'] ?? '',
                    'givenname'      => $_POST['givenname'] ?? '',
                    'sn'             => $_POST['sn'] ?? '',
                    'ou'             => $_POST['ou'] ?? '',
                ];
                // 全部可选字段透传
                foreach ($all_field_keys as $k) {
                    $userData[$k] = $_POST[$k] ?? '';
                }
                $ldap->createUser($userData);

                // ── IM 平台联动：异步创建平台账号 + 发通知邮件 ──
                try {
                    $imPayload = [
                        'sam'      => $userData['samaccountname'],
                        'display'  => $userData['displayname'],
                        'mail'     => $userData['mail']        ?? $userData['email'] ?? '',
                        'mobile'   => $userData['mobile']      ?? $userData['telephonenumber'] ?? '',
                        'ou_dn'    => $userData['ou'],
                        'password' => $userData['password'],
                        'position' => $userData['title']       ?? $userData['position'] ?? '',
                    ];
                    $imResults = PluginAdmanagerIMService::onUserCreated($imPayload);
                    $imOk  = array_filter($imResults, fn($r) => $r['ok'] ?? false);
                    $imMsg = empty($imResults) ? '' : ('，已同步到 ' . count($imOk) . '/' . count($imResults) . ' 个平台');
                } catch (\Throwable $ime) {
                    $imMsg = '（IM同步异常：' . $ime->getMessage() . '）';
                }

                $result = ['ok'=>true, 'message'=>'用户创建成功' . ($imMsg ?? '')];
                break;

            case 'batch_import':
                if (empty($_FILES['csv_file']['tmp_name'])) {
                    throw new \RuntimeException('请上传CSV文件');
                }
                $csv = array_map('str_getcsv', file($_FILES['csv_file']['tmp_name']));
                $header = array_map('strtolower', $csv[0]);
                $created = 0; $errors = [];
                for ($i = 1; $i < count($csv); $i++) {
                    if (count($csv[$i]) < count($header)) continue;
                    $row = array_combine($header, $csv[$i]);
                    try {
                        $userData = [
                            'samaccountname' => $row['samaccountname'] ?? '',
                            'displayname'    => $row['displayname'] ?? '',
                            'password'       => $row['password'] ?? '',
                            'givenname'      => $row['givenname'] ?? '',
                            'sn'             => $row['sn'] ?? '',
                            'ou'             => $row['ou'] ?? ($_POST['default_ou'] ?? ''),
                        ];
                        foreach ($all_field_keys as $k) {
                            $userData[$k] = $row[$k] ?? '';
                        }
                        $ldap->createUser($userData);
                        $created++;
                    } catch (\Throwable $e) {
                        $errors[] = "行{$i}：" . $e->getMessage();
                    }
                }
                $msg = "成功创建 {$created} 个用户";
                if ($errors) $msg .= '，' . count($errors) . ' 条失败：' . implode('; ', array_slice($errors, 0, 5));
                $result = ['ok' => $created > 0, 'message' => $msg];
                break;
        }
    }

    // ── 预加载 IM 绑定 ──
    $im_bindings_all = [];
    try {
        $all_bindings = PluginAdmanagerIMService::getBindings();
        foreach ($all_bindings as $b) {
            $sam_lower = strtolower($b['sam']);
            if (!isset($im_bindings_all[$sam_lower])) $im_bindings_all[$sam_lower] = [];
            $platform_label = ['wecom'=>'企微','dingtalk'=>'钉钉','feishu'=>'飞书'][$b['platform']] ?? $b['platform'];
            $im_bindings_all[$sam_lower][] = $platform_label . ':' . ($b['platform_name'] ?: $b['platform_uid']);
        }
    } catch (\Throwable $e) {}

    // 搜索用户：keyword 或 ou 任一非空都触发（OU单独过滤也能生效）
    if ($keyword || $ou) {
        $users = PluginAdmanagerAdCache::searchUsers($keyword, $ou);
        PluginAdmanagerAuditLog::write('search_ad_users', 'ADUser',
            $keyword ?: "(ou:{$ou})", $keyword ?: "(ou:{$ou})",
            ['result_count' => count($users), 'ou' => $ou, 'keyword' => $keyword]);
        // 附加 IM 绑定信息
        foreach ($users as $k => $u) {
            $sam = strtolower($u['samaccountname'] ?? '');
            $users[$k]['im_bindings'] = $sam ? ($im_bindings_all[$sam] ?? []) : [];
        }
    }

    // 用户详情
    if ($detail) {
        $detail_user = $ldap->getUserDetail($detail);
        if (!$detail_user) $error = "用户 {$detail} 未找到";
        else {
            $sam = strtolower($detail_user['samaccountname'] ?? '');
            $detail_user['im_bindings'] = $sam ? ($im_bindings_all[$sam] ?? []) : [];
        }
    }

    // OU 列表
    $ous = $ldap->listOUs();
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$can_write  = PluginAdmanagerProfile::canDo('write_ad', UPDATE);
$can_reset  = PluginAdmanagerProfile::canDo('reset_pwd', UPDATE);
$can_create = PluginAdmanagerProfile::canDo('admin', CREATE);

// 获取模板列表
$templates = PluginAdmanagerUserTemplate::getAll();
$fields_meta = PluginAdmanagerUserTemplate::allFields();

Html::header('AD用户管理', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'aduser');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/aduser_list.html.twig', [
    'users'          => $users,
    'keyword'        => $keyword,
    'ou'             => $ou,
    'ous'            => $ous,
    'error'          => $error,
    'result'         => $result,
    'detail_user'    => $detail_user,
    'can_write'      => $can_write,
    'can_reset'      => $can_reset,
    'can_import'     => $can_create,
    'can_create'     => $can_create,
    'csrf_token'     => Session::getNewCSRFToken(),
    'templates'      => $templates,
    'current_tpl'    => $template,
    'fields_meta'    => $fields_meta,
    'tpl_id'         => $tpl_id,
]);

Html::footer();
