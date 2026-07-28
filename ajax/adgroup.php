<?php
/**
 * ajax/adgroup.php — AD 安全组管理 AJAX 接口
 * 走 /ajax/ 路径：GLPI 用 X-Glpi-Csrf-Token header 验证，不消耗 token
 */
include '../../../inc/includes.php';
header('Content-Type: application/json; charset=utf-8');
session_write_close();

if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'message'=>'未登录']); exit;
}
PluginAdmanagerProfile::checkRight('admin', READ);
$can_write = PluginAdmanagerProfile::canDo('admin', CREATE);

$action = $_REQUEST['action'] ?? '';

function jout(array $d): never { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function jerr(string $msg, int $code=400): never {
    http_response_code($code);
    echo json_encode(['ok'=>false,'message'=>$msg], JSON_UNESCAPED_UNICODE); exit;
}

try {
    $ldap = PluginAdmanagerAdLdap::getInstance();

    switch ($action) {

        // ── 搜索安全组 ──────────────────────────────────────────────────────
        case 'search':
            $kw   = trim($_GET['keyword'] ?? '');
            $ou   = trim($_GET['ou']      ?? '');
            $incD = !empty($_GET['include_distribution']);
            $groups = $ldap->searchGroups($kw, $ou, $incD);
            jout(['ok'=>true,'data'=>$groups,'count'=>count($groups)]);

        // ── 获取组详情+成员 ─────────────────────────────────────────────────
        case 'detail':
            $dn = trim($_GET['dn'] ?? '');
            if (!$dn) jerr('缺少 dn 参数');
            $g = $ldap->getGroupDetail($dn);
            if (!$g) jerr('未找到该安全组', 404);
            jout(['ok'=>true,'data'=>$g]);

        // ── 新建安全组 ──────────────────────────────────────────────────────
        case 'create':
            if (!$can_write) jerr('无权限', 403);
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $name  = trim($data['name']  ?? '');
            $ou    = trim($data['ou']    ?? '');
            $desc  = trim($data['description'] ?? '');
            $scope = trim($data['scope'] ?? 'global');
            $mail  = trim($data['mail']  ?? '');
            if (!$name || !$ou) jerr('组名和 OU 不能为空');
            $result = $ldap->createGroup($name, $ou, $desc, $scope, $mail);
            jout(['ok'=>true,'message'=>"安全组 {$name} 创建成功",'data'=>$result]);

        // ── 修改安全组属性 ──────────────────────────────────────────────────
        case 'modify':
            if (!$can_write) jerr('无权限', 403);
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $dn   = trim($data['dn'] ?? '');
            if (!$dn) jerr('缺少 dn 参数');
            unset($data['dn']);
            $ldap->modifyGroup($dn, $data);
            jout(['ok'=>true,'message'=>'修改成功']);

        // ── 删除安全组 ──────────────────────────────────────────────────────
        case 'delete':
            if (!$can_write) jerr('无权限', 403);
            $data  = json_decode(file_get_contents('php://input'), true) ?? [];
            $dn    = trim($data['dn']    ?? '');
            $force = !empty($data['force']);
            if (!$dn) jerr('缺少 dn 参数');
            $ldap->deleteGroup($dn, $force);
            jout(['ok'=>true,'message'=>'安全组已删除']);

        // ── 添加成员 ────────────────────────────────────────────────────────
        case 'add_member':
            if (!$can_write) jerr('无权限', 403);
            $data       = json_decode(file_get_contents('php://input'), true) ?? [];
            $group_dn   = trim($data['group_dn']  ?? '');
            $member_dn  = trim($data['member_dn'] ?? '');
            if (!$group_dn || !$member_dn) jerr('缺少 group_dn 或 member_dn');
            $ldap->addGroupMember($member_dn, $group_dn);
            PluginAdmanagerAuditLog::write('add_member', 'ADGroup', $group_dn, basename($member_dn), ['member_dn'=>$member_dn]);
            jout(['ok'=>true,'message'=>'成员已添加']);

        // ── 移除成员 ────────────────────────────────────────────────────────
        case 'remove_member':
            if (!$can_write) jerr('无权限', 403);
            $data       = json_decode(file_get_contents('php://input'), true) ?? [];
            $group_dn   = trim($data['group_dn']  ?? '');
            $member_dn  = trim($data['member_dn'] ?? '');
            if (!$group_dn || !$member_dn) jerr('缺少 group_dn 或 member_dn');
            $ldap->removeGroupMember($member_dn, $group_dn);
            PluginAdmanagerAuditLog::write('remove_member', 'ADGroup', $group_dn, basename($member_dn), ['member_dn'=>$member_dn]);
            jout(['ok'=>true,'message'=>'成员已移除']);

        // ── 搜索用户/组（用于添加成员弹窗） ─────────────────────────────────
        case 'search_members':
            $kw = trim($_GET['keyword'] ?? '');
            if (strlen($kw) < 1) jout(['ok'=>true,'data'=>[]]);
            $users  = $ldap->searchUsers($kw);
            $groups = $ldap->searchGroups($kw);
            $merged = array_merge(
                array_map(fn($u)=>array_merge($u,['_type'=>'user']),  array_slice($users,  0, 20)),
                array_map(fn($g)=>array_merge($g,['_type'=>'group']), array_slice($groups, 0, 10))
            );
            jout(['ok'=>true,'data'=>$merged]);

        // ── 获取 OU 列表（供新建组选择父 OU） ───────────────────────────────
        case 'list_ous':
            $ous = $ldap->listOUs();
            jout(['ok'=>true,'data'=>$ous]);

        default:
            jerr('未知 action: '.$action);
    }
} catch (\Throwable $e) {
    jerr($e->getMessage());
}
