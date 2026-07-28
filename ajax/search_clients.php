<?php
include('../../../inc/includes.php');
PluginAdmanagerProfile::checkRight('admin', READ);

$q = $_GET['q'] ?? '';
if (strlen($q) < 1) { echo '[]'; exit; }

try {
    $client = PluginAdmanagerFastApiClient::getInstance();
    $data = $client->get('/api/export/clients', ['limit' => 50, 'keyword' => $q]);
} catch (Exception $e) {
    echo '[]';
    exit;
}
$items = $data['items'] ?? [];

$out = array_map(function($c) {
    return [
        'id'       => $c['client_id'],
        'hostname' => $c['hostname'],
        'serial'   => $c['serial'],
        'label'    => ($c['hostname'] ?? '?') . ' | ' . substr($c['serial'] ?? '', 0, 24),
    ];
}, $items);

header('Content-Type: application/json');
echo json_encode($out);
