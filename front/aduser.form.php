<?php
/**
 * front/aduser.form.php — AD 用户操作 AJAX 端点
 */
include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '仅接受 POST 请求']); exit;
}

$action  = $_POST['action']   ?? '';
$dn      = $_POST['dn']       ?? '';
$respond = fn(bool $ok, string $msg, array $extra = []) => json_encode(['ok' => $ok, 'message' => $msg] + $extra);

try {
    $ldap = PluginAdmanagerAdLdap::getInstance();

    switch ($action) {

        case 'disable':
            PluginAdmanagerProfile::checkRight('write_ad', UPDATE);
            $ldap->setUserEnabled($dn, false);
            PluginAdmanagerAuditLog::write('disable_user','ADUser',$dn,$_POST['name']??'');
            PluginAdmanagerAdCache::refreshUserByDn($dn);
            // ── IM 联动：同步禁用平台账号 ──
            $sam = $_POST['sam'] ?? basename(str_replace(['CN=','cn='],'',$dn));
            try { PluginAdmanagerIMService::onUserDisabled($sam); } catch (\Throwable $e) {}
            echo $respond(true, '账户已禁用', ['new_state' => 'disabled']);
            break;

        case 'enable':
            PluginAdmanagerProfile::checkRight('write_ad', UPDATE);
            $ldap->setUserEnabled($dn, true);
            PluginAdmanagerAuditLog::write('enable_user','ADUser',$dn,$_POST['name']??'');
            PluginAdmanagerAdCache::refreshUserByDn($dn);
            // ── IM 联动：同步启用平台账号 ──
            $sam = $_POST['sam'] ?? basename(str_replace(['CN=','cn='],'',$dn));
            try { PluginAdmanagerIMService::onUserEnabled($sam); } catch (\Throwable $e) {}
            echo $respond(true, '账户已启用', ['new_state' => 'enabled']);
            break;

        case 'unlock':
            PluginAdmanagerProfile::checkRight('write_ad', UPDATE);
            $ldap->unlockUser($dn);
            PluginAdmanagerAuditLog::write('unlock_user','ADUser',$dn,$_POST['name']??'');
            PluginAdmanagerAdCache::refreshUserByDn($dn);
            echo $respond(true, '账户已解锁');
            break;

        case 'reset_pwd':
            PluginAdmanagerProfile::checkRight('reset_pwd', UPDATE);
            $pwd = $_POST['password'] ?? '';
            if (strlen($pwd) < 8) throw new \RuntimeException('密码至少 8 位');
            $ldap->resetPassword($dn, $pwd);
            PluginAdmanagerAuditLog::write('reset_pwd','ADUser',$dn,$_POST['name']??'',
                ['pwd_length' => strlen($pwd)]);
            PluginAdmanagerAdCache::refreshUserByDn($dn);
            echo $respond(true, '密码已重置');
            break;

        case 'move_ou':
            PluginAdmanagerProfile::checkRight('write_ad', UPDATE);
            $target_ou = $_POST['target_ou'] ?? '';
            if (!$target_ou) throw new \RuntimeException('目标 OU 不能为空');
            $ldap->moveUser($dn, $target_ou);
            PluginAdmanagerAuditLog::write('move_ou','ADUser',$dn,$_POST['name']??'',
                ['target_ou'=>$target_ou]);
            PluginAdmanagerAdCache::refreshUserByDn($dn);
            echo $respond(true, '已移动到 ' . $target_ou);
            break;

        case 'import_to_glpi':
            PluginAdmanagerProfile::checkRight('admin', CREATE);
            $sam  = $_POST['samaccountname'] ?? '';
            $user = $ldap->findUser($sam);
            if (!$user) throw new \RuntimeException("用户 {$sam} 不存在");
            $res  = PluginAdmanagerImportBridge::importAdUser($user);
            echo $respond($res['status'] !== 'error',
                $res['status'] === 'error' ? $res['message'] : "已导入（GLPI ID: {$res['glpi_id']}）");
            break;

        case 'create_ou':
            PluginAdmanagerProfile::checkRight('admin', CREATE);
            $ouName = trim($_POST['ou_name'] ?? '');
            if (empty($ouName)) throw new \RuntimeException('OU名称不能为空');
            $newOu = $ldap->createOU($ouName);
            echo json_encode(['ok' => true, 'data' => $newOu, 'message' => "OU '{$ouName}' 创建成功"]);
            break;

        default:
            echo $respond(false, "未知操作：{$action}");
    }

} catch (\Throwable $e) {
    http_response_code(400);
    echo $respond(false, $e->getMessage());
}
