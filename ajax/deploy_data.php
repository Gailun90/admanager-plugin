<?php
ob_start();
include('../../../inc/includes.php');
error_reporting(0);
@ini_set('display_errors', '0');
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
if (!Session::getLoginUserID()) { http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit; }
PluginAdmanagerProfile::checkRight('admin', READ);
$action = $_GET['action'] ?? '';
if ($action === 'script' && isset($_GET['task_id'])) {
    try {
        $data = PluginAdmanagerFastApiClient::getInstance()->get('/api/tasks/admin/' . (int)$_GET['task_id'] . '/script');
        if (is_array($data)) {
            $data['created_at_fmt'] = PluginAdmanagerTime::fmt($data['created_at'] ?? null);
        }
        echo json_encode($data);
    } catch (Exception $e) { http_response_code(502); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
http_response_code(404); echo json_encode(['error' => 'unknown']); exit;
