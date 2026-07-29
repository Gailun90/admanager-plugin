<?php
/**
 * register_package_async.php
 * 由 uploadPackage() 通过 nohup 异步调用
 * 参数: dest safeName name version silentArgs desc fileSize apiUrl apiToken
 */
array_shift($argv); // 去掉脚本自身
[$dest, $safeName, $name, $version, $silentArgs, $desc, $fileSize, $apiUrl, $apiToken] = $argv + array_fill(0, 9, '');

echo date('[Y-m-d H:i:s]') . " 开始计算哈希: {$dest}\n";
$fileHash = hash_file('sha256', $dest);
echo date('[Y-m-d H:i:s]') . " 哈希: {$fileHash}\n";

$url = rtrim($apiUrl, '/') . '/api/packages/register'
     . '?name='        . urlencode($name)
     . '&version='     . urlencode($version)
     . '&filename='    . urlencode($safeName)
     . '&file_size='   . $fileSize
     . '&file_hash='   . $fileHash
     . '&silent_args=' . urlencode($silentArgs)
     . '&description=' . urlencode($desc);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '',
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiToken],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

// 修复：此前失败/成功都没有任何状态回写，用户上传大文件后异步注册失败，
// 只会安静地变成孤儿文件，包列表里永远不出现，也没有任何提示。
// 这里把结果写一个 .status.json 标记：成功就删掉标记（正常态不需要），
// 失败则保留，供 deploy_package.php 扫描后在页面上提醒管理员。
$statusFile = $dest . '.status.json';

if ($err) {
    echo date('[Y-m-d H:i:s]') . " FastAPI 注册失败(curl): {$err}\n";
    file_put_contents($statusFile, json_encode([
        'ok' => false, 'error' => "curl 错误: {$err}", 'ts' => date('c'),
    ], JSON_UNESCAPED_UNICODE));
    exit(1);
}
echo date('[Y-m-d H:i:s]') . " FastAPI HTTP {$code}: {$raw}\n";
if ($code >= 400) {
    file_put_contents($statusFile, json_encode([
        'ok' => false, 'error' => "FastAPI HTTP {$code}: " . substr($raw, 0, 300), 'ts' => date('c'),
    ], JSON_UNESCAPED_UNICODE));
    exit(1);
}
@unlink($statusFile);
exit(0);
