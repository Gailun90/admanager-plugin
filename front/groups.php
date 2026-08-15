<?php
/**
 * front/groups.php — 终端分组管理 v2（双面板设计支持 save_all）
 */
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

// ── 内部 API helper（统一走 PluginAdmanagerFastApiClient，去掉重复的 curl + token 处理）──
function groups_api(string $method, string $path, array $params = []): array {
    try {
        $api = PluginAdmanagerFastApiClient::getInstance();
        return match ($method) {
            'GET'    => $api->get($path, $params),
            'POST'   => $api->postQuery($path, $params),
            'PATCH'  => $api->patch($path, $params),
            'DELETE' => $api->delete($path, $params),
            default  => ['ok' => false, 'message' => '不支持的请求方法：' . $method],
        };
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => '请求失败：' . $e->getMessage()];
    }
}

// ── POST 处理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    $act = $_POST['_action'] ?? '';

    if ($act === 'create_group') {
        $name = trim($_POST['group_name'] ?? '');
        $desc = trim($_POST['group_desc'] ?? '');
        if ($name === '') {
            $r = ['ok' => false, 'message' => '分组名称不能为空'];
        } else {
            $r = groups_api('POST', '/api/groups', ['name' => $name, 'description' => $desc]);
        }
    } elseif ($act === 'update_group' && !empty($_POST['group_id'])) {
        $r = groups_api('PATCH', '/api/groups/' . (int)$_POST['group_id'], [
            'name'        => trim($_POST['group_name'] ?? ''),
            'description' => trim($_POST['group_desc'] ?? ''),
        ]);
    } elseif ($act === 'delete_group' && !empty($_POST['group_id'])) {
        $r = groups_api('DELETE', '/api/groups/' . (int)$_POST['group_id']);
    } elseif ($act === 'set_members' && !empty($_POST['group_id'])) {
        $r = groups_api('POST', '/api/groups/' . (int)$_POST['group_id'] . '/members', [
            'client_ids' => trim($_POST['client_ids'] ?? ''),
        ]);
    } elseif ($act === 'save_all') {
        // ★ 组合保存：先创建/更新分组名，再保存成员（一次 POST 完成）
        $gid   = (int)($_POST['group_id'] ?? 0);
        $name  = trim($_POST['group_name'] ?? '');
        $desc  = trim($_POST['group_desc'] ?? '');
        $cids  = trim($_POST['client_ids'] ?? '');

        if ($name === '') {
            $r = ['ok' => false, 'message' => '分组名称不能为空'];
        } else {
            if ($gid > 0) {
                // 更新分组
                $r = groups_api('PATCH', '/api/groups/' . $gid, [
                    'name' => $name, 'description' => $desc,
                ]);
            } else {
                // 新建分组
                $r = groups_api('POST', '/api/groups', ['name' => $name, 'description' => $desc]);
                // 新建后不知道怎么拿 ID，靠 JS 发第二次请求？
                // 简单方案：前端新建后用 reload 重新选
            }

            if (($r['ok'] ?? false) && $gid > 0) {
                // 更新成员
                $r2 = groups_api('POST', '/api/groups/' . $gid . '/members', ['client_ids' => $cids]);
                if (!$r2['ok']) $r = $r2;
            }
        }
    } else {
        $r = ['ok' => false, 'message' => '未知操作'];
    }

    Session::addMessageAfterRedirect(
        $r['message'] ?? '操作完成', true,
        ($r['ok'] ?? false) ? INFO : ERROR
    );
    Html::redirect($_SERVER['PHP_SELF']);
    exit;
}

// ── 页面渲染 ──
Html::header('终端分组', $_SERVER['PHP_SELF'], 'plugins', 'admanager', 'admanager_groups');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@admanager/groups.html.twig', [
    'can_write'  => $can_write,
    'csrf_token' => Session::getNewCSRFToken(),
]);

Html::footer();
