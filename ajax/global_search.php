<?php
/**
 * 全局搜索 AJAX 接口
 * 支持搜索终端、AD用户、AD安全组
 */
if (!defined('GLPI_ROOT')) {
    die('禁止直接访问');
}
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
        'SELECT' => ['id', 'serial', 'hostname'],
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
        $results['terminals'][] = [
            'id'   => $row['id'],
            'name' => $row['hostname'] ?: $row['serial'] ?: '未知',
            'serial' => $row['serial'] ?: '',
            'url'  => '/plugins/admanager/front/import.php'
        ];
    }

    // 2. 搜索 AD 用户 (缓存表)
    $cache = PluginAdmanagerAdCache::getInstance();
    $users = $cache->getUsers() ?: [];
    foreach ($users as $sam => $info) {
        if (count($results['users']) >= 5) break;
        $samLower = strtolower($sam);
        $nameLower = strtolower($info['displayname'] ?? '');
        if (strpos($samLower, $qlower) !== false ||
            strpos($nameLower, $qlower) !== false) {
            $results['users'][] = [
                'sam'  => $sam,
                'name' => $info['displayname'] ?: $sam,
                'mail' => $info['mail'] ?? '',
                'dept' => $info['department'] ?? '',
                'url'  => '/plugins/admanager/front/aduser.php?keyword=' . urlencode($sam)
            ];
        }
    }

    // 3. 搜索 AD 安全组 (缓存)
    $groups = $cache->getGroups() ?: [];
    foreach ($groups as $dn => $info) {
        if (count($results['groups']) >= 5) break;
        $nameLower = strtolower($info['name'] ?? $dn);
        if (strpos($nameLower, $qlower) !== false) {
            $results['groups'][] = [
                'dn'   => $dn,
                'name' => $info['name'] ?? $dn,
                'desc' => $info['description'] ?? '',
                'url'  => '/plugins/admanager/front/adgroup.php'
            ];
        }
    }

} catch (Exception $e) {
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
