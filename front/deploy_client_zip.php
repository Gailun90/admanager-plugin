<?php
include('../../../inc/includes.php');
/**
 * front/deploy_client_zip.php — 生成一键部署 zip（下载安装脚本+客户端）
 * 从旧 front/deploy_package.php 里搬出来，避免和「安装包库」页面撞名。
 * 目前没有任何按钮调用它，先留着，等你要接到 UI 上告诉我。
 */

PluginAdmanagerProfile::checkRight('admin', CREATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'generate_package') {
    Html::displayErrorAndDie('无效请求');
}

$server_url = trim($_POST['server_url'] ?? '');
if (!$server_url) {
    Html::displayErrorAndDie('请填写服务器地址');
}

// 安装脚本 — 安装 Windows Service (SYSTEM) + Tray 自启
$ps = <<<'PS'
# ITAsset4 客户端一键部署
# 安装为 Windows 服务（SYSTEM 权限）+ 用户托盘自启
# =====================================================

$ErrorActionPreference = "Stop"
$ServerUrl  = "SERVER_URL_PLACEHOLDER"
$InstallDir = "C:\Program Files\ITAsset4"
$ServiceName = "ITAsset4Agent"
$TrayExe    = "ITAsset4.Tray.exe"
$ServiceExe = "ITAsset4.Service.exe"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  ITAsset4 资产管控客户端部署" -ForegroundColor Yellow
Write-Host "  服务器: $ServerUrl" -ForegroundColor White
Write-Host "========================================" -ForegroundColor Cyan

# 1. 权限检查 — 安装服务必须管理员
Write-Host "[1/5] 权限检查..." -ForegroundColor Cyan
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "  需要管理员权限，请在弹出的UAC窗口中确认" -ForegroundColor Yellow
    Start-Process powershell -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`""
    exit
}
Write-Host "  [OK]" -ForegroundColor Green

# 2. 创建目录 + 下载
Write-Host "[2/5] 下载客户端文件..." -ForegroundColor Cyan
New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$wc = New-Object Net.WebClient

$files = @(Get-ChildItem "$PSScriptRoot\client\*" -ErrorAction SilentlyContinue)
if (-not $files) {
    Write-Host "  从服务器下载..." -ForegroundColor Gray
    # 在线下载模式：脚本自带文件则在 PSScriptRoot/client/，否则从服务器拉
}

# 优先用脚本自带文件，没有则从服务器下载
$localClient = Join-Path $PSScriptRoot "client"
if (Test-Path $localClient) {
    Write-Host "  复制本地文件..." -ForegroundColor Gray
    Copy-Item "$localClient\*" $InstallDir -Recurse -Force
} else {
    Write-Host "  从服务器下载..." -ForegroundColor Gray
    $dlFiles = @(
        "ITAsset4.Service.exe","ITAsset4.Service.dll","ITAsset4.Service.deps.json","ITAsset4.Service.runtimeconfig.json",
        "ITAsset4.Tray.exe","ITAsset4.Tray.dll","ITAsset4.Tray.deps.json","ITAsset4.Tray.runtimeconfig.json",
        "ITAsset4.Common.dll","System.Management.dll","Microsoft.Extensions.Hosting.WindowsServices.dll"
    )
    foreach ($f in $dlFiles) {
        $url = "$ServerUrl/plugins/admanager/client/$f"
        $dest = Join-Path $InstallDir $f
        try { $wc.DownloadFile($url, $dest) } catch { Write-Host "  跳过 $f" -ForegroundColor DarkGray }
    }
}
Write-Host "  [OK]" -ForegroundColor Green

# 3. 写入配置
Write-Host "[3/5] 写入服务器配置..." -ForegroundColor Cyan
$cfg = @{ ServerUrl = $ServerUrl; AutoStart = $true } | ConvertTo-Json
$cfgPath = Join-Path $InstallDir "appsettings.json"
Set-Content -Path $cfgPath -Value $cfg -Encoding UTF8
Write-Host "  [OK]" -ForegroundColor Green

# 4. 安装 Windows 服务（SYSTEM 权限）
Write-Host "[4/5] 安装后台服务..." -ForegroundColor Cyan
$svcBin = Join-Path $InstallDir $ServiceExe

# 停止旧服务（如有）
Stop-Service $ServiceName -ErrorAction SilentlyContinue
sc.exe delete $ServiceName 2>$null

# 创建服务 — binPath 必须在 sc.exe 单行内
$binPath = "`"$svcBin`" --contentRoot `"$InstallDir`""
$r = sc.exe create $ServiceName binPath= $binPath start= auto DisplayName= "ITAsset4 Agent"
if ($LASTEXITCODE -ne 0) {
    # sc 失败时用 PowerShell New-Service
    New-Service -Name $ServiceName -BinaryPathName $svcBin -DisplayName "ITAsset4 Agent" -StartupType Automatic -ErrorAction SilentlyContinue
}

# 配置服务失败自动重启
sc.exe failure $ServiceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 2>$null

# 启动服务
Start-Service $ServiceName -ErrorAction SilentlyContinue
Write-Host "  [OK] 已安装为 SYSTEM 服务" -ForegroundColor Green

# 5. 托盘自启
Write-Host "[5/5] 配置用户托盘自启..." -ForegroundColor Cyan
$shortcut = "$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup\ITAsset4.lnk"
$trayPath = Join-Path $InstallDir $TrayExe
$WshShell = New-Object -ComObject WScript.Shell
$Shortcut = $WshShell.CreateShortcut($shortcut)
$Shortcut.TargetPath = $trayPath
$Shortcut.WorkingDirectory = $InstallDir
$Shortcut.Save()
Write-Host "  [OK] 已添加开机自启" -ForegroundColor Green

# 立即启动托盘
Start-Process -FilePath $trayPath -WorkingDirectory $InstallDir

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  部署完成！" -ForegroundColor Green
Write-Host "  后台服务: $ServiceName (SYSTEM)" -ForegroundColor White
Write-Host "  托盘程序: 已启动 + 开机自启" -ForegroundColor White
Write-Host "========================================" -ForegroundColor Cyan
PS;

$ps = str_replace('SERVER_URL_PLACEHOLDER', $server_url, $ps);

// README
$readme = <<<README
ITAsset4 资产管理客户端 — 部署说明
====================================

## 部署方式
解压后右键「install.cmd」→「以管理员身份运行」
首次运行需在 UAC 弹窗中确认。

## 安装内容
- ITAsset4 Agent 服务 → 以 SYSTEM 权限在后台运行，负责采集硬件信息、执行软件部署任务
- ITAsset4 Tray 托盘 → 用户登录后自动启动，在系统托盘显示状态

## 系统要求
- Windows 10/11 x64 或 Windows Server 2016+
- .NET 8.0 Desktop Runtime (如未安装: https://dotnet.microsoft.com/download/dotnet/8.0)
- 安装时需要管理员权限（仅安装时，后续服务以 SYSTEM 运行）

## 防火墙
首次运行如被防火墙拦截，请允许 ITAsset4.Service.exe 和 ITAsset4.Tray.exe 访问网络。

## 服务器信息
- 地址: {$server_url}
- 生成: {date('Y-m-d H:i:s')}
README;

// install.cmd
$cmd = <<<CMD
@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo ========================================
echo   ITAsset4 客户端部署
echo   将以管理员权限安装后台服务
echo ========================================
echo.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile -ExecutionPolicy Bypass -File \"%~dp0install.ps1\"' -Wait"
echo.
echo 部署完成！按任意键退出...
pause >nul
CMD;

// 打包 ZIP
$tmp = sys_get_temp_dir() . '/itasset4_pkg_' . uniqid();
mkdir($tmp . '/client', 0777, true);

foreach (glob(GLPI_ROOT . '/plugins/admanager/client/*') as $f) {
    copy($f, $tmp . '/client/' . basename($f));
}
file_put_contents($tmp . '/install.ps1', $ps);
file_put_contents($tmp . '/install.cmd', $cmd);
file_put_contents($tmp . '/README.txt', $readme);

$zip = new ZipArchive();
$zip_file = sys_get_temp_dir() . '/itasset4_deploy_' . date('YmdHis') . '.zip';
$zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp), RecursiveIteratorIterator::LEAVES_ONLY);
foreach ($iter as $f) {
    if ($f->isDir()) continue;
    $zip->addFile($f->getRealPath(), substr($f->getRealPath(), strlen($tmp) + 1));
}
$zip->close();

// 清理
array_map('unlink', glob("$tmp/client/*"));
rmdir("$tmp/client"); rmdir($tmp);

// 下载
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="ITAsset4_部署包_' . date('Ymd_His') . '.zip"');
header('Content-Length: ' . filesize($zip_file));
readfile($zip_file);
unlink($zip_file);
exit;