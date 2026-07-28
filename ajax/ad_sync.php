<?php
/**
 * ajax/ad_sync.php — AD 缓存手动同步触发端点
 */
include("../../../inc/includes.php");
header("Content-Type: application/json; charset=utf-8");

// 未登录返回 JSON 而不是重定向 HTML
if (!Session::getLoginUserID()) {
    echo json_encode(["ok" => false, "message" => "未登录或 Session 已过期，请刷新页面"]);
    exit;
}

// 权限检查
if (!PluginAdmanagerProfile::canDo("admin", READ)) {
    echo json_encode(["ok" => false, "message" => "权限不足"]);
    exit;
}

$action = $_POST["action"] ?? $_GET["action"] ?? "";

if ($action === "sync") {
    $result = PluginAdmanagerAdCache::syncAll("manual:" . Session::getLoginUserID());
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === "status") {
    // 检查是否需要自动同步（间隔 > 0 且距上次同步超时，且当前未在同步）
    $interval = (int)PluginAdmanagerConfig::get('ad_sync_interval');
    if ($interval > 0 && PluginAdmanagerAdCache::needsAutoSync() && !PluginAdmanagerAdCache::isSyncing()) {
        PluginAdmanagerAdCache::syncAll('auto');
    }

    $info = PluginAdmanagerAdCache::getLastSyncInfo();
    echo json_encode([
        "ok"            => true,
        "syncing"       => PluginAdmanagerAdCache::isSyncing(),
        "last_sync"     => $info["synced_at"],
        "user_count"    => PluginAdmanagerAdCache::getUserCount(),
        "computer_count"=> PluginAdmanagerAdCache::getComputerCount(),
        "triggered_by"  => $info["triggered_by"] ?? "",
        "duration_sec"  => $info["duration_sec"]  ?? 0,
        "needs_sync"    => PluginAdmanagerAdCache::needsAutoSync(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(["ok" => false, "message" => "未知操作"], JSON_UNESCAPED_UNICODE);
