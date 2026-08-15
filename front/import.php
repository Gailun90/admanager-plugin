<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

// AJAX：软件清单
if (isset($_GET['action']) && $_GET['action'] === 'software' && isset($_GET['client_id'])) {
    header('Content-Type: application/json');
    try {
        $data = PluginAdmanagerFastApiClient::getInstance()->getClientSoftware((int)$_GET['client_id']);
        if (is_array($data)) {
            $data['collected_at_fmt'] = PluginAdmanagerTime::fmt($data['collected_at'] ?? null);
        }
        echo json_encode($data);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX：创建卸载任务
if (isset($_POST['action']) && $_POST['action'] === 'uninstall'
    && isset($_POST['software_name']) && isset($_POST['client_id'])) {
    $result = PluginAdmanagerDeploy::createUninstallTask(
        trim($_POST['software_name']),
        (int)$_POST['client_id']
    );
    Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
    Html::redirect($_SERVER['PHP_SELF']);
}


// POST：删除客户端（需求4）
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'delete_client'
    && isset($_POST['del_client_id'])) {
    PluginAdmanagerProfile::checkRight('admin', DELETE);
    $client_id = (int)$_POST['del_client_id'];
    $hostname  = trim($_POST['del_hostname'] ?? '');
    $serial    = trim($_POST['del_serial']   ?? '');
    $bios      = trim($_POST['del_bios']     ?? '');
    $ok = false;
    $msg = '';

    if ($client_id <= 0) {
        // FastAPI 中无对应记录（孤儿 syncstate）→ 只清本地同步状态
        global $DB;
        $serials = array_values(array_filter([$serial, $bios]));
        if ($serials) {
            $DB->delete(PluginAdmanagerSyncState::$table, ['serial' => $serials]);
        }
        $ok  = true;
        $msg = '已清除本地同步记录（FastAPI 中无对应客户端）';
    } else {
        try {
            $api = PluginAdmanagerFastApiClient::getInstance();
            $resp = $api->deleteClient($client_id);
            $ok  = $resp['ok'] ?? false;
            $msg = $resp['message'] ?? '已删除';
        } catch (Exception $e) {
            $msg = 'FastAPI 错误：' . $e->getMessage();
        }
        // 同步删除 syncstate 记录（同时按 hash serial 与 bios serial 清理，避免孤儿记录）
        if ($ok) {
            global $DB;
            $serials = array_values(array_filter([$serial, $bios]));
            if ($serials) {
                $DB->delete(PluginAdmanagerSyncState::$table, ['serial' => $serials]);
            }
        }
    }
    // 写入审计日志
    PluginAdmanagerAuditLog::write(
        'delete_client', 'Computer', $serial,
        $hostname,
        ['client_id' => $client_id, 'serial' => $serial],
        $ok,
        $ok ? '' : $msg
    );
    $level = $ok ? INFO : ERROR;
    $notice = $ok
        ? "已删除客户端 {$hostname}（ID: {$client_id}）"
        : "删除失败：{$msg}";
    Session::addMessageAfterRedirect($notice, true, $level);
    Html::redirect($_SERVER['PHP_SELF']);
}

// POST：批量删除客户端 / 清空全部终端（修复“删除终端非常慢”）
//  - bulk_delete：前端勾选若干终端，一次性并发删除（curl_multi，约 N/16 次网络往返）
//  - delete_all ：枚举全部终端并并发清空，同时 truncate 本地 syncstates 镜像表
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && in_array($_POST['action'], ['bulk_delete', 'delete_all'])) {

    if (!PluginAdmanagerProfile::canDo('admin', DELETE)) {
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => '无删除权限']);
            exit;
        }
        Session::addMessageAfterRedirect('无删除权限', true, ERROR);
        Html::redirect($_SERVER['PHP_SELF']);
    }

    $api = PluginAdmanagerFastApiClient::getInstance();
    $clientIds = [];
    $serials   = [];   // hash serial
    $biosList  = [];   // bios serial

    if ($_POST['action'] === 'delete_all') {
        try {
            $all = $api->getClientsAll(200);
            foreach ($all['items'] as $c) {
                if (!empty($c['client_id'])) $clientIds[] = (int)$c['client_id'];
                if (!empty($c['serial']))    $serials[]   = $c['serial'];
                $b = $c['real_serial'] ?? ($c['bios_serial'] ?? '');
                if (!empty($b))              $biosList[]  = $b;
            }
        } catch (Exception $e) {
            Session::addMessageAfterRedirect('无法获取终端列表：' . $e->getMessage(), true, ERROR);
            Html::redirect($_SERVER['PHP_SELF']);
        }
    } else {
        $clientIds = array_map('intval', (array)($_POST['del_ids']   ?? []));
        $serials   = array_map('trim',  (array)($_POST['del_serials'] ?? []));
        $biosList  = array_map('trim',  (array)($_POST['del_bios']   ?? []));
    }

    $clientIds = array_values(array_filter($clientIds, fn($id) => $id > 0));
    $serials   = array_values(array_filter($serials));
    $biosList  = array_values(array_filter($biosList));

    $result = ['ok' => 0, 'fail' => 0, 'errors' => []];
    if (!empty($clientIds)) {
        try {
            $result = $api->deleteClientsParallel($clientIds, 16);
        } catch (Exception $e) {
            $result = ['ok' => 0, 'fail' => count($clientIds), 'errors' => [$e->getMessage()]];
        }
    }

    // 同步清理本地同步状态（同时按 hash serial 与 bios serial 清理，避免孤儿记录）
    global $DB;
    $allSerials = array_values(array_unique(array_merge($serials, $biosList)));
    if (!empty($allSerials)) {
        $DB->delete(PluginAdmanagerSyncState::$table, ['serial' => $allSerials]);
    }

    $isAll = ($_POST['action'] === 'delete_all');
    PluginAdmanagerAuditLog::write(
        $isAll ? 'delete_all_clients' : 'bulk_delete_clients',
        'Computer', (string)count($clientIds),
        $isAll ? '清空全部终端' : '批量删除终端',
        ['deleted' => $result['ok'], 'failed' => $result['fail']],
        $result['fail'] === 0,
        $result['fail'] ? implode('; ', $result['errors']) : ''
    );

    // 清空全部：兜底直接 truncate 本地镜像表（含孤儿行）
    if ($isAll) {
        $DB->queryOrDie("TRUNCATE TABLE " . PluginAdmanagerSyncState::$table);
    }

    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'      => $result['fail'] === 0,
            'message' => "已删除 {$result['ok']} 台" . ($result['fail'] ? "，失败 {$result['fail']} 台" : ''),
            'deleted' => $result['ok'],
            'failed'  => $result['fail'],
        ]);
        exit;
    }
    $msg = "已删除 {$result['ok']} 台终端" . ($result['fail'] ? "，失败 {$result['fail']} 台" : '');
    Session::addMessageAfterRedirect($msg, true, $result['fail'] === 0 ? INFO : ERROR);
    Html::redirect($_SERVER['PHP_SELF']);
}

// POST：手动导入 — 只传 serial，服务端查全量数据
$import_result = null;
$error         = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_serial'])) {
    PluginAdmanagerProfile::checkRight('admin', CREATE);
    $serial = trim($_POST['import_serial'] ?? '');
    if ($serial) {
        $client_data = null;
        try {
            $api = PluginAdmanagerFastApiClient::getInstance();
            $result = $api->getClientsAll(200);
            foreach (($result['items'] ?? []) as $c) {
                if (($c['serial'] ?? '') === $serial) {
                    $client_data = $c;
                    break;
                }
            }
        } catch (Exception $e) {
            $error = 'FastAPI连接失败';
        }
        if ($client_data) {
            $import_result = PluginAdmanagerImportBridge::importComputer($client_data);
        } else {
            $error = '无效的终端数据：未找到序列号 ' . htmlspecialchars($serial);
        }
    } else {
        $error = '无效的终端数据：未获取到终端序列号';
    }
}

// 数据准备
$diff_only = isset($_GET['diff']) && $_GET['diff'] == '1';

$clients = [];
try {
    $api     = PluginAdmanagerFastApiClient::getInstance();
    $result  = $api->getClientsAll(200);
    $clients = $result['items'] ?? [];
} catch (Exception $e) {
    $error = $error ?: '无法连接 FastAPI：' . $e->getMessage();
}

// 从数据库获取差异终端列表
$db_diff_list = PluginAdmanagerSyncState::getDiffList(200);


// ── 预加载 IM 绑定映射（sam → 平台用户名列表）──
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

// 按 serial 索引 FastAPI 客户端
$client_index = [];
foreach ($clients as $c) {
    if (!empty($c['serial'])) {
        $client_index[$c['serial']] = $c;
    }
}

// 从 syncstates 获取所有终端的 glpi_items_id（不限 has_diff）
global $DB;
$ss_index = [];
$all_ss = $DB->request(['SELECT' => ['serial','glpi_items_id'], 'FROM' => PluginAdmanagerSyncState::$table]);
foreach ($all_ss as $d) {
    if (!empty($d['serial'])) {
        $ss_index[$d['serial']] = $d['glpi_items_id'];
    }
}
// 合并到 clients（全部终端视图需要显示 GLPI 状态 + 当前用户 + IM绑定）
foreach ($clients as $k => $c) {
    if (isset($ss_index[$c['serial']])) {
        $clients[$k]['glpi_items_id'] = $ss_index[$c['serial']];
    }
    // 解析当前用户 → 查 IM 绑定
    $raw_user = $c['current_user'] ?? '';
    $clients[$k]['current_user_raw'] = $raw_user;
    if ($raw_user) {
        // Windows 格式：DOMAIN\username → username, username@domain → username
        $sam = strtolower(preg_replace('/^.+\\\|@.+$/', '', $raw_user));
        $sam = trim($sam);
        $clients[$k]['current_user_sam'] = $sam;
        $clients[$k]['im_bindings'] = $im_bindings_all[$sam] ?? [];
    }
}
// 统一时间格式化（GLPI 日期格式 + 本地时区）
foreach ($clients as $k => $c) {
    $clients[$k]['last_seen_fmt'] = PluginAdmanagerTime::fmt($c['last_seen'] ?? null);
}

// 合并：diff_list 记录用 FastAPI 数据补全
$enriched_diff = [];
foreach ($db_diff_list as $row) {
    $api = $client_index[$row['serial']] ?? [];
    $raw_user = $api['current_user'] ?? '';
    $sam = '';
    $im_bindings = [];
    if ($raw_user) {
        $sam = strtolower(preg_replace('/^.+\\\|@.+$/', '', $raw_user));
        $sam = trim($sam);
        $im_bindings = $im_bindings_all[$sam] ?? [];
    }
    $enriched_diff[] = array_merge($row, $api, [
        'has_diff' => $row['has_diff'] ?? ($api['has_diff'] ?? false),
        'client_id' => $api['client_id'] ?? 0,
        'os_name'   => $api['os_name']   ?? ($row['os_name'] ?? ''),
        'cpu'       => $api['cpu']       ?? '',
        'memory_gb' => $api['memory_gb'] ?? 0,
        'last_seen' => $api['last_seen'] ?? ($row['last_seen_api'] ?? ''),
        'glpi_items_id' => $row['glpi_items_id'],
        'current_user_raw' => $raw_user,
        'current_user_sam' => $sam,
        'im_bindings'      => $im_bindings,
    ]);
}

$can_import = PluginAdmanagerProfile::canDo('admin', CREATE);


// 获取 FastAPI 对外 SERVER_URL（前端 WebSocket 用，非内部 fastapi_url）
$fastapi_server_url = $fastapi_url;
try {
    $info = PluginAdmanagerFastApiClient::getInstance()->get('/api/server/info');
    $fastapi_server_url = $info['server_url'] ?? $fastapi_url;
} catch (Exception $e) {
    // 降级
}

// 获取在线终端 serial 列表
$online_serials = [];
try {
    $dash = PluginAdmanagerFastApiClient::getInstance()->getDashboard();
    $online_serials = array_flip($dash['online_serials'] ?? []);
} catch (Exception $e) {}

Html::header('终端列表', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'import');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/import.html.twig', [
    'clients'      => $clients,
    'diff_list'    => $enriched_diff,
    'diff_only'    => $diff_only,
    'can_import'   => $can_import,
    'error'        => $error,
    'import_result'=> $import_result,
    'csrf_token'    => Session::getNewCSRFToken(),
    'fastapi_token' => PluginAdmanagerConfig::getFastApiConfig()['token'] ?? '',
    'fastapi_url'   => PluginAdmanagerConfig::getFastApiConfig()['url']   ?? '',
    'fastapi_server_url' => $fastapi_server_url,
    'online_serials'    => $online_serials,
]);

Html::footer();