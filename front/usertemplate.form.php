<?php
/**
 * front/usertemplate.form.php — AJAX 接口（list/get/save/delete/ous）
 * 每个 action 回包都返回新的 csrf_token，确保单次消耗后前端能续签。
 */
include '../../../inc/includes.php';
if (!Session::getLoginUserID()) {
    echo json_encode(['ok' => false, 'message' => 'Session expired']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            PluginAdmanagerProfile::checkRight('admin', READ);
            $templates = PluginAdmanagerUserTemplate::getAll();
            echo json_encode([
                'ok'         => true,
                'data'       => $templates,
                'csrf_token' => Session::getNewCSRFToken(),
            ]);
            break;

        case 'get':
            PluginAdmanagerProfile::checkRight('admin', READ);
            $id  = (int)($_POST['template_id'] ?? 0);
            $tpl = PluginAdmanagerUserTemplate::getTemplateById($id);
            if (!$tpl) throw new RuntimeException('Template not found');
            echo json_encode([
                'ok'         => true,
                'data'       => $tpl,
                'csrf_token' => Session::getNewCSRFToken(),
            ]);
            break;

        case 'save':
            PluginAdmanagerProfile::checkRight('admin', CREATE);
            $id   = (int)($_POST['template_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) throw new RuntimeException('模板名称不能为空');
            // GLPI includes.php 会对 $_POST 自动 addslashes，需先还原再 json_decode
            $fields = json_decode(stripslashes($_POST['fields'] ?? '[]'), true) ?: [];
            $result = PluginAdmanagerUserTemplate::saveTemplate($id, $name, $fields);
            $result['csrf_token'] = Session::getNewCSRFToken();
            echo json_encode($result);
            break;

        case 'delete':
            PluginAdmanagerProfile::checkRight('admin', PURGE);
            $id = (int)($_POST['template_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid ID');
            $result = PluginAdmanagerUserTemplate::deleteById($id);
            $result['csrf_token'] = Session::getNewCSRFToken();
            echo json_encode($result);
            break;

        case 'ous':
            PluginAdmanagerProfile::checkRight('admin', READ);
            try {
                $ldap = PluginAdmanagerAdLdap::getInstance();
                $ous  = $ldap->listOUs();
            } catch (Throwable $e) {
                $ous = [];
            }
            echo json_encode([
                'ok'         => true,
                'data'       => $ous,
                'csrf_token' => Session::getNewCSRFToken(),
            ]);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => "Unknown: $action"]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
