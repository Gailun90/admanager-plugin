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

if ($err) {
    echo date('[Y-m-d H:i:s]') . " FastAPI 注册失败(curl): {$err}\n";
    exit(1);
}
echo date('[Y-m-d H:i:s]') . " FastAPI HTTP {$code}: {$raw}\n";
exit($code >= 400 ? 1 : 0);
