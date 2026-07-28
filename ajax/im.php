<?php
/**
 * ajax/im.php — 通讯平台管理 AJAX 接口
 */
include '../../../inc/includes.php';
header('Content-Type: application/json; charset=utf-8');
session_write_close();

if (!Session::getLoginUserID()) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'未登录']); exit; }
// action 级别权限控制，test_connect 只需登录
$jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_REQUEST['action'] ?? $jsonInput['action'] ?? '';
if (!in_array($action, ['test_connect','get_platforms'])) {
    PluginAdmanagerProfile::checkRight('admin', READ);
}
$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

function jout(array $d): never { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function jerr(string $m, int $c=400): never { http_response_code($c); echo json_encode(['ok'=>false,'message'=>$m],JSON_UNESCAPED_UNICODE); exit; }

try {
    switch ($action) {

        case 'test_connect':
            $platform = $jsonInput['platform'] ?? '';
            if ($platform === 'mail') {
                // 测试 SMTP 邮件发送
                $cfg  = PluginAdmanagerConfig::getAll();
                $to   = $cfg['im_notify_email'] ?? '';
                if (!$to) jerr('请先填写通知接收邮箱');
                $ok = PluginAdmanagerIMService::sendNotifyEmail(
                    ['sam'=>'test','display'=>'测试用户','mail'=>$to,'ou_dn'=>'','password'=>'TestPass123'],
                    [],
                    $cfg
                );
                if ($ok) jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'message'=>"测试邮件已发送至 {$to}"]);
                jerr('邮件发送失败，请检查 SMTP 配置和日志');
            }
            $conn = PluginAdmanagerIMService::getConnector($platform);
            if (!$conn) jerr('平台未启用或未配置，请先保存配置');
            $conn->connect();
            $label = ['wecom'=>'企业微信','dingtalk'=>'钉钉','feishu'=>'飞书'][$platform] ?? $platform;
            // 用获取用户数量来丰富验证信息
            $userCount = 0;
            try {
                $users = $conn->getAllUsers(1, 10);
                $userCount = count($users);
            } catch (\Throwable $e) {}
            $msg = "{$label} 连接成功" . ($userCount > 0 ? "，已读取到 {$userCount} 个用户" : "（通讯录权限待申请）");
            jout(['ok'=>true,'message'=>$msg,'csrf_token'=>Session::getNewCSRFToken()]);

        case 'get_platforms':
            $cfg = PluginAdmanagerConfig::getAll();
            $platforms = [];
            foreach (['wecom','dingtalk','feishu'] as $p) {
                $platforms[$p] = [
                    'enabled' => ($cfg["{$p}_enabled"] ?? '0') === '1',
                    'label'   => ['wecom'=>'企业微信','dingtalk'=>'钉钉','feishu'=>'飞书'][$p],
                ];
            }
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'data'=>$platforms]);

        case 'get_departments':
            $platform = $_GET['platform'] ?? '';
            $conn = PluginAdmanagerIMService::getConnector($platform);
            if (!$conn) jerr('平台未启用');
            $depts = $conn->getDepartments();
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'data'=>$depts]);

        case 'get_bindings':
            $sam      = trim($_GET['sam']      ?? '');
            $platform = trim($_GET['platform'] ?? '');
            jout(['ok'=>true,'data'=>PluginAdmanagerIMService::getBindings($sam, $platform)]);

        case 'save_binding':
            if (!$can_write) jerr('无权限',403);
                        PluginAdmanagerIMService::saveBinding(
                $jsonInput['sam'] ?? '', $jsonInput['platform'] ?? '', $jsonInput['platform_uid'] ?? '',
                $jsonInput['platform_name'] ?? '', $jsonInput['dept_id'] ?? '', $jsonInput['status'] ?? 'active'
            );
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'message'=>'绑定已保存']);

        case 'remove_binding':
            if (!$can_write) jerr('无权限',403);
                        PluginAdmanagerIMService::removeBinding($jsonInput['sam'] ?? '', $jsonInput['platform'] ?? '');
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'message'=>'绑定已解除']);

        case 'get_dept_mappings':
            $platform = $_GET['platform'] ?? '';
            jout(['ok'=>true,'data'=>PluginAdmanagerIMService::getDeptMappings($platform)]);

        case 'save_dept_mapping':
            if (!$can_write) jerr('无权限',403);
                        PluginAdmanagerIMService::saveDeptMapping(
                $jsonInput['ou_dn'] ?? '', $jsonInput['platform'] ?? '',
                $jsonInput['dept_id'] ?? '', $jsonInput['dept_name'] ?? ''
            );
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'message'=>'部门映射已保存']);

        case 'auto_match':
            $platform = $_GET['platform'] ?? '';
            $matches  = PluginAdmanagerIMService::autoMatch($platform);
            jout(['ok'=>true,'data'=>$matches,'count'=>count($matches)]);

        case 'confirm_match':
            if (!$can_write) jerr('无权限',403);
                        PluginAdmanagerIMService::saveBinding(
                $jsonInput['sam'] ?? '', $jsonInput['platform'] ?? '', $jsonInput['platform_uid'] ?? '',
                $jsonInput['platform_name'] ?? '', $jsonInput['dept_id'] ?? '', 'active'
            );
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'message'=>'匹配确认，绑定已建立']);

        case 'sync_status':
            // 手动触发平台→AD状态同步
            $results = PluginAdmanagerIMService::syncPlatformStatusToAD();
            jout(['ok'=>true,'data'=>$results,'message'=>'同步完成，共处理 '.count($results).' 条']);

        case 'sync_ad_to_platform':
            // AD 禁用/启用 → 同步到平台
            if (!$can_write) jerr('无权限',403);
                        $sam     = $jsonInput['sam']     ?? '';
            $enabled = (bool)($jsonInput['enabled'] ?? false);
            $results = $enabled
                ? PluginAdmanagerIMService::onUserEnabled($sam)
                : PluginAdmanagerIMService::onUserDisabled($sam);
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'data'=>$results]);

        case 'get_platform_users':
            $platform = $_GET['platform'] ?? '';
            $page     = max(1,(int)($_GET['page'] ?? 1));
            $conn = PluginAdmanagerIMService::getConnector($platform);
            if (!$conn) jerr('平台未启用');
            $users = $conn->getAllUsers($page, 50);
            // 标记已绑定的
            $bound = PluginAdmanagerIMService::getBindings('', $platform);
            $boundUids = array_column($bound, 'sam', 'platform_uid');
            foreach ($users as &$u) {
                $u['bound_sam'] = $boundUids[$u['userid']] ?? null;
            }
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'data'=>$users,'page'=>$page]);
        case 'search_ad_users':
            $q      = trim($_GET['q'] ?? '');
            $platform = trim($_GET['platform'] ?? '');
            if (mb_strlen($q) < 1) jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'data'=>[]]);
            $ldap   = PluginAdmanagerAdLdap::getInstance();
            $adUsers = $ldap->searchUsers($q);
            // 查已绑定记录（防止重复绑定同一平台）
            $bound = PluginAdmanagerIMService::getBindings('', $platform);
            $boundSams = array_column($bound, 'sam');
            $data = [];
            foreach ($adUsers as $u) {
                $sam = $u['samaccountname'] ?? $u['cn'] ?? '';
                $data[] = [
                    'sam'     => $sam,
                    'name'    => $u['displayname'] ?? $u['cn'] ?? '',
                    'dn'      => $u['dn'] ?? '',
                    'ou'      => preg_match('/OU=([^,]+)/i', $u['dn'] ?? '', $m) ? $m[1] : '',
                    'already_bound' => in_array($sam, $boundSams),
                ];
            }
            jout(['ok'=>true,'csrf_token'=>Session::getNewCSRFToken(),'data'=>$data]);

        default:
            jerr('未知 action: '.$action);
    }
} catch (\Throwable $e) {
    jerr($e->getMessage());
}
