<?php
/**
 * ajax/vuln_data.php — 漏洞修复前端统一 AJAX 接口
 *
 * 数据查询 + 审核操作；批准可执行类型时服务端会自动下发到客户端代理。
 * GET 用于查询，POST 用于变更（校验 CSRF）。
 *
 * CSRF 合规：每个响应都附带一个新的“一次性” token（字段 _csrf），前端在每次
 * AJAX 后据此刷新页面级 #vuln-csrf，确保多次写操作各自使用独立的一次性 token，
 * 不启用 GLPI_KEEP_CSRF_TOKEN（保持 GLPI 默认的一次性 token 行为）。
 */
include('../../../inc/includes.php');
header('Content-Type: application/json; charset=utf-8');

/**
 * 统一 JSON 输出，并在每次响应中附带一个新的 CSRF token（符合 GLPI 一次性 token 规范）。
 */
/**
 * 判断是否为「列表型」数组（连续 0..n-1 整数键），用于区分列表响应与对象响应。
 */
function _admanager_is_list(array $a): bool
{
    if ($a === []) {
        return true;
    }
    $i = 0;
    foreach ($a as $k => $v) {
        if ($k !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

/**
 * 统一 JSON 输出。
 * - 列表型响应（findings/imports/tasks/...）保持为 JSON 数组原样输出，
 *   避免注入 _csrf 把数组变成对象，导致前端 Array.isArray 误判为「加载失败」。
 * - 对象型响应（ok/message 类）附带新的「一次性」CSRF token，供前端刷新 #vuln-csrf。
 *   （AJAX 请求由 inc/includes.php 定义 GLPI_KEEP_CSRF_TOKEN，token 不被消费，
 *    故列表响应省略 _csrf 不影响后续写操作的 token 有效性。）
 */
function admanager_send($data): void
{
    if (!is_array($data)) {
        $data = ['ok' => false, 'message' => '响应格式异常'];
    }
    if (_admanager_is_list($data)) {
        echo json_encode($data);
    } else {
        $data['_csrf'] = Session::getNewCSRFToken();
        echo json_encode($data);
    }
}

if (!Session::getLoginUserID()) {
    admanager_send(['ok' => false, 'message' => '未登录']);
    exit;
}
if (!Session::haveRight('plugin_admanager_read', READ)) {
    admanager_send(['ok' => false, 'message' => '无权限']);
    exit;
}

$act = $_GET['action'] ?? ($_POST['action'] ?? '');

// 变更类操作清单（审核/规则维护，与“查看”权限分离）
$WRITE_ACTIONS = ['approve', 'reject', 'mark_manual', 'dispatch', 'batch_approve',
                  'resolve_match', 'create_rule', 'update_rule', 'delete_rule', 'reparse_import',
                  'rematch', 'rematch_package', 'manual_match', 'delete_task', 'kill_switch_toggle'];

if (in_array($act, $WRITE_ACTIONS, true)) {
    // 权限对齐 deploy 模块：变更需 plugin_admanager_deploy 或 plugin_admanager_admin，
    // 仅有 read 权限的用户只能查看（与 front 页面的 $canWrite 判定保持一致）
    if (!Session::haveRight('plugin_admanager_deploy', CREATE)
        && !Session::haveRight('plugin_admanager_admin', CREATE)) {
        admanager_send(['ok' => false, 'message' => '无权限：需要部署管理或插件管理权限才能执行此操作']);
        exit;
    }
    // POST 类操作需校验 CSRF（GLPI 标准做法；token 一次性，校验后由响应中的 _csrf 刷新）
    if (!Session::validateCSRF($_POST)) {
        admanager_send(['ok' => false, 'message' => 'CSRF 校验失败']);
        exit;
    }
}

switch ($act) {
    // ── 查询 ──
    case 'packages':
        admanager_send(PluginAdmanagerFastApiClient::getInstance()->get('/api/packages'));
        break;

    case 'imports':
        admanager_send(PluginAdmanagerVuln::getImports());
        break;

    case 'import_stats':
        $id = (int)($_GET['id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::getImportStats($id));
        break;

    case 'findings':
        $id = (int)($_GET['id'] ?? 0);
        $m  = $_GET['match'] ?? '';
        admanager_send(PluginAdmanagerVuln::getFindings($id, $m));
        break;

    case 'tasks':
        $status = $_GET['status'] ?? '';
        $importId = (int)($_GET['import_id'] ?? 0);
        $risk   = $_GET['risk'] ?? '';
        admanager_send(PluginAdmanagerVuln::getTasks($status, $importId, $risk));
        break;

    case 'task_detail':
        $id = (int)($_GET['id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::getTaskDetail($id));
        break;

    case 'clients':
        admanager_send(PluginAdmanagerVuln::getClients());
        break;

    case 'rules':
        admanager_send(PluginAdmanagerVuln::getRules($_GET['status'] ?? ''));
        break;

    // ── 变更（审核 / 规则维护；approve/dispatch 可触发下发）──
    case 'approve':
    case 'reject':
    case 'mark_manual':
    case 'dispatch':
        $id = (int)($_POST['task_id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::taskAction($id, $act));
        break;

    case 'batch_approve':
        $ids = isset($_POST['task_ids']) ? (array)$_POST['task_ids'] : [];
        admanager_send(PluginAdmanagerVuln::batchApprove($ids));
        break;

    case 'resolve_match':
        $fid = (int)($_POST['finding_id'] ?? 0);
        $aid = (isset($_POST['asset_id']) && $_POST['asset_id'] !== '')
            ? (int)$_POST['asset_id'] : null;
        admanager_send(PluginAdmanagerVuln::resolveMatch($fid, $aid));
        break;

    case 'create_rule':
        admanager_send(PluginAdmanagerVuln::createRule($_POST));
        break;

    case 'update_rule':
        $rid = (int)($_POST['rule_id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::updateRule($rid, $_POST));
        break;

    case 'delete_rule':
        $rid = (int)($_POST['rule_id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::deleteRule($rid));
        break;

    case 'reparse_import':
        $id = (int)($_POST['import_id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::reparseImport($id));
        break;

    case 'rematch':
    case 'rematch_package':
        $id = (int)($_POST['task_id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::rematchPackage($id));
        break;

    case 'manual_match':
        $id = (int)($_POST['task_id'] ?? 0);
        $pkgId = (int)($_POST['package_id'] ?? 0);
        $assetId = (isset($_POST['asset_id']) && $_POST['asset_id'] !== '') ? (int)$_POST['asset_id'] : null;
        admanager_send(PluginAdmanagerVuln::manualMatchPackage($id, $pkgId, $assetId));
        break;

    case 'delete_task':
        $id = (int)($_POST['task_id'] ?? 0);
        admanager_send(PluginAdmanagerVuln::deleteTask($id));
        break;

    case 'kill_switch_status':
        admanager_send(PluginAdmanagerVuln::getKillSwitch());
        break;

    case 'kill_switch_toggle':
        admanager_send(PluginAdmanagerVuln::toggleKillSwitch());
        break;

    default:
        admanager_send(['ok' => false, 'message' => '未知操作']);
}
