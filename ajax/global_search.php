<?php
/**
 * 全局搜索 AJAX 接口
 * 支持搜索终端、AD用户、AD安全组、GLPI 计算机
 */
// 引导 GLPI 运行环境（定义 GLPI_ROOT、连接 DB、加载 Session/Search 等核心类）。
// 直接 HTTP 访问插件 ajax 文件时 GLPI 不会自动引导，必须显式引入 includes.php，
// 否则 $DB / \Search 不可用、且会因 GLPI_ROOT 未定义而“禁止直接访问”。
// 若该文件已被 GLPI 上下文引导（GLPI_ROOT 已定义），define 会被跳过，require_once 亦幂等，安全。
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', dirname(__DIR__, 3));
}
require_once GLPI_ROOT . '/inc/includes.php';

header('Content-Type: application/json');
require_once __DIR__ . '/../inc/adcache.class.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([
        'success' => true,
        'data' => ['terminals' => [], 'users' => [], 'groups' => []],
        'message' => ''
    ]);
    exit;
}

$results = [
    'terminals' => [],
    'users' => [],
    'groups' => []
];

try {
    global $DB;
    $like = '%' . $DB->escape($q) . '%';
    $qlower = strtolower($q);

    // 1. 搜索终端 (syncstates 表)
    $rows = $DB->request([
        'SELECT' => ['id', 'serial', 'hostname', 'glpi_items_id'],
        'FROM' => 'glpi_plugin_admanager_syncstates',
        'WHERE' => [
            'OR' => [
                ['hostname' => ['LIKE', $like]],
                ['serial'   => ['LIKE', $like]],
            ]
        ],
        'LIMIT' => 5
    ]);
    foreach ($rows as $row) {
        $glpiId = (int)($row['glpi_items_id'] ?? 0);
        $results['terminals'][] = [
            'id'   => $row['id'],
            'name' => $row['hostname'] ?: $row['serial'] ?: '未知',
            'serial' => $row['serial'] ?: '',
            // 已导入 GLPI 资产 → 跳转到该终端的 GLPI 计算机详情；否则退回终端列表
            'url'  => $glpiId > 0
                ? '/front/computer.form.php?id=' . $glpiId
                : '/plugins/admanager/front/import.php'
        ];
    }

    // 1.5 搜索 GLPI 原生 Computer —— 直接复用 GLPI 搜索引擎（Search::getDatas），
    //     自动带实体(entities)与权限(rights)过滤，比手写 LIKE 更细更全。（参照 GLPI 搜索，问题③）
    //     注意：GLPI 对 Computer 不支持 field=all 的“全局搜索”（会报“太多表”而拒绝），
    //     故改为对若干关键字段（名称/序列号/资产编号/使用者）做 OR 组合 contains 搜索。
    try {
        $compSearch = \Search::getDatas('Computer', [
            'criteria' => [
                ['field' => 1, 'searchtype' => 'contains', 'value' => $q],                       // 名称
                ['field' => 5, 'searchtype' => 'contains', 'value' => $q, 'link' => 'OR'],       // 序列号
                ['field' => 6, 'searchtype' => 'contains', 'value' => $q, 'link' => 'OR'],       // 资产编号
                ['field' => 7, 'searchtype' => 'contains', 'value' => $q, 'link' => 'OR'],       // 使用者
            ],
            'list_limit' => 8,
        ]);
        foreach (($compSearch['data']['rows'] ?? []) as $row) {
            $cid = $row['id'] ?? null;
            if (!$cid) {
                continue;
            }
            $label = '';
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['displayname'])) {
                    $label = trim(strip_tags((string)($cell['displayname'])));
                    break;
                }
            }
            $results['computers'][] = [
                'id'   => (int)$cid,
                'name' => $label ?: ('#' . $cid),
                'url'  => \Computer::getFormURLWithID((int)$cid),
            ];
        }
    } catch (\Throwable $e) {
        // 搜索失败不影响其它分类结果
    }

    // 2. 搜索 AD 用户 (缓存表)
    // 旧代码调 $cache->getUsers() —— 该方法在 adcache 中并不存在，会抛
    // "Call to undefined method" 被外层 try/catch 吞掉，导致用户搜索恒为空。
    // AdCache 全部为静态方法，无 getInstance()；直接静态调用 searchUsers($q)（自带实时状态覆盖）。
    $users = PluginAdmanagerAdCache::searchUsers($q);
    foreach ($users as $info) {
        if (count($results['users']) >= 5) break;
        $sam       = strtolower($info['samaccountname'] ?? '');
        $nameLower = strtolower($info['displayname'] ?? '');
        if ($sam !== '' && (strpos($sam, $qlower) !== false ||
            strpos($nameLower, $qlower) !== false)) {
            $results['users'][] = [
                'sam'  => $info['samaccountname'] ?? '',
                'name' => $info['displayname'] ?: ($info['samaccountname'] ?? ''),
                'mail' => $info['mail'] ?? '',
                'dept' => $info['department'] ?? '',
                'url'  => '/plugins/admanager/front/aduser.php?keyword=' . urlencode($info['samaccountname'] ?? '')
            ];
        }
    }

    // 3. 搜索 AD 安全组（复用 adgroup.php 的 LDAP 检索，默认仅安全组）
    //    直接走 LDAP，不再依赖尚未接入的组缓存；检索失败不影响终端/用户结果。
    try {
        $ldapGroups = PluginAdmanagerAdLdap::getInstance()->searchGroups($q);
        foreach ($ldapGroups as $g) {
            if (count($results['groups']) >= 5) break;
            $results['groups'][] = [
                'dn'   => $g['dn'] ?? '',
                'name' => $g['cn'] ?? ($g['dn'] ?? ''),
                'desc' => $g['description'] ?? '',
                'url'  => '/plugins/admanager/front/adgroup.php?dn=' . urlencode($g['dn'] ?? '')
            ];
        }
    } catch (\Throwable $e) {
        // 组检索失败（如 AD 暂不可达）不影响其它分类结果
    }

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'data' => [],
        'message' => '搜索失败: ' . $e->getMessage()
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => $results
]);
