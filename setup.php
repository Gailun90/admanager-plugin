<?php
/**
 * setup.php — admanager 插件注册入口 v1.0.0
 */
define('PLUGIN_ADMANAGER_VERSION',  '1.0.0');
define('PLUGIN_ADMANAGER_MIN_GLPI', '10.0.0');
define('PLUGIN_ADMANAGER_MAX_GLPI', '10.2.99');
define('PLUGIN_ADMANAGER_DIR',     Plugin::getPhpDir('admanager'));
define('PLUGIN_ADMANAGER_WEB_DIR', Plugin::getWebDir('admanager'));
// 自定义权限位
define('ADMANAGER_RESET_PWD', 1024);
define('ADMANAGER_DEPLOY',    2048);

function plugin_version_admanager(): array {
    return [
        'name'         => 'B站谈起哥运维插件',
        'version'      => PLUGIN_ADMANAGER_VERSION,
        'author'       => '九哥和大力',
        'license'      => 'GPL v3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_ADMANAGER_MIN_GLPI, 'max' => PLUGIN_ADMANAGER_MAX_GLPI],
            'php'  => ['min' => '8.1', 'exts' => ['ldap','curl','mbstring','json']],
        ],
    ];
}

function plugin_admanager_check_prerequisites(): bool {
    $missing = array_filter(['ldap','curl','mbstring','json'], fn($e) => !extension_loaded($e));
    if ($missing) { echo '缺少PHP扩展：' . implode(', ', $missing); return false; }
    return true;
}

function plugin_admanager_check_config(bool $verbose = false): bool { return true; }

function plugin_admanager_install(): bool {
    global $DB;
    $cs = DBConnection::getDefaultCharset();
    $co = DBConnection::getDefaultCollation();
    $ks = DBConnection::getDefaultPrimaryKeySignOption();

    // 审计日志表
    if (!$DB->tableExists('glpi_plugin_admanager_auditlogs')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_auditlogs` (
            `id`            int {$ks} NOT NULL AUTO_INCREMENT,
            `date_mod`      datetime NOT NULL,
            `users_id`      int {$ks} NOT NULL DEFAULT 0,
            `action_type`   varchar(64)  NOT NULL DEFAULT '',
            `target_type`   varchar(64)  NOT NULL DEFAULT '',
            `target_dn`     varchar(512) NOT NULL DEFAULT '',
            `target_name`   varchar(255) NOT NULL DEFAULT '',
            `params`        longtext,
            `result`        tinyint NOT NULL DEFAULT 0,
            `error_message` text,
            `ip_address`    varchar(45)  NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `date_mod`   (`date_mod`),
            KEY `users_id`   (`users_id`),
            KEY `action_type`(`action_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }

    // 导入记录表
    if (!$DB->tableExists('glpi_plugin_admanager_importlogs')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_importlogs` (
            `id`            int {$ks} NOT NULL AUTO_INCREMENT,
            `date_mod`      datetime NOT NULL,
            `users_id`      int {$ks} NOT NULL DEFAULT 0,
            `import_type`   varchar(32)  NOT NULL DEFAULT '',
            `source_ref`    varchar(255) NOT NULL DEFAULT '',
            `glpi_itemtype` varchar(64)  NOT NULL DEFAULT '',
            `glpi_items_id` int {$ks} NOT NULL DEFAULT 0,
            `status`        varchar(16)  NOT NULL DEFAULT 'pending',
            `error_message` text,
            PRIMARY KEY (`id`),
            KEY `date_mod`    (`date_mod`),
            KEY `import_type` (`import_type`),
            KEY `status`      (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }

    // 同步状态表
    if (!$DB->tableExists('glpi_plugin_admanager_syncstates')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_syncstates` (
            `id`            int {$ks} NOT NULL AUTO_INCREMENT,
            `serial`        varchar(128) NOT NULL DEFAULT '',
            `hostname`      varchar(255) NOT NULL DEFAULT '',
            `last_seen_api` datetime     DEFAULT NULL,
            `last_imported` datetime     DEFAULT NULL,
            `glpi_items_id` int {$ks} NOT NULL DEFAULT 0,
            `has_diff`      tinyint NOT NULL DEFAULT 0,
            `diff_fields`   text,
            `date_mod`      datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `serial` (`serial`),
            KEY `has_diff` (`has_diff`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }

    // AD 数据缓存表
    if (!$DB->tableExists('glpi_plugin_admanager_ad_cache')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_ad_cache` (
            `id`             int {$ks} NOT NULL AUTO_INCREMENT,
            `cache_type`     varchar(16)  NOT NULL DEFAULT '',
            `sam`            varchar(255) NOT NULL DEFAULT '',
            `dn`             varchar(512) NOT NULL DEFAULT '',
            `display_name`   varchar(255) NOT NULL DEFAULT '',
            `department`     varchar(255) NOT NULL DEFAULT '',
            `mail`           varchar(255) NOT NULL DEFAULT '',
            `title`          varchar(255) NOT NULL DEFAULT '',
            `is_disabled`    tinyint NOT NULL DEFAULT 0,
            `is_locked`      tinyint NOT NULL DEFAULT 0,
            `last_logon_unix` int NOT NULL DEFAULT 0,
            `os`             varchar(255) NOT NULL DEFAULT '',
            `dns_hostname`   varchar(255) NOT NULL DEFAULT '',
            `raw_json`       longtext,
            `synced_at`      datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `dn_type` (`dn`(255), `cache_type`),
            KEY `cache_type`  (`cache_type`),
            KEY `sam`         (`sam`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }

    // 同步日志表
    if (!$DB->tableExists('glpi_plugin_admanager_ad_sync_log')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_ad_sync_log` (
            `id`             int {$ks} NOT NULL AUTO_INCREMENT,
            `sync_type`      varchar(16)  NOT NULL DEFAULT '',
            `total_count`     int NOT NULL DEFAULT 0,
            `duration_sec`   decimal(8,2) NOT NULL DEFAULT 0,
            `triggered_by`    varchar(64)  NOT NULL DEFAULT '',
            `synced_at`       datetime NOT NULL,
            `status`          varchar(16)  NOT NULL DEFAULT '',
            `error_msg`       text,
            PRIMARY KEY (`id`),
            KEY `synced_at`    (`synced_at`),
            KEY `status`       (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }

    // === v4.3 新增：用户创建模板表 ===
    if (!$DB->tableExists('glpi_plugin_admanager_usertemplates')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_usertemplates` (
            `id`              int {$ks} NOT NULL AUTO_INCREMENT,
            `name`            varchar(255) NOT NULL DEFAULT '',
            `preset_fields`   text,
            `selected_fields` text,
            `date_creation`   datetime NOT NULL,
            `date_mod`        datetime NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }

    // === v5.0 新增：IM 平台绑定表 ===
    if (!$DB->tableExists('glpi_plugin_admanager_im_bindings')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_im_bindings` (
            `id`            int {$ks} NOT NULL AUTO_INCREMENT,
            `sam`           varchar(255) NOT NULL DEFAULT '',
            `platform`      varchar(16)  NOT NULL DEFAULT '',
            `platform_uid`  varchar(128) NOT NULL DEFAULT '',
            `platform_name` varchar(255) NOT NULL DEFAULT '',
            `dept_id`       varchar(128) NOT NULL DEFAULT '',
            `status`        varchar(16)  NOT NULL DEFAULT 'active',
            `bound_at`      datetime NOT NULL,
            `date_mod`      datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_sam_platform` (`sam`(191), `platform`),
            KEY `idx_platform` (`platform`),
            KEY `idx_status`   (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }
    if (!$DB->tableExists('glpi_plugin_admanager_im_depts')) {
        $DB->queryOrDie("CREATE TABLE `glpi_plugin_admanager_im_depts` (
            `id`         int {$ks} NOT NULL AUTO_INCREMENT,
            `ou_dn`      varchar(512) NOT NULL DEFAULT '',
            `platform`   varchar(16)  NOT NULL DEFAULT '',
            `dept_id`    varchar(128) NOT NULL DEFAULT '',
            `dept_name`  varchar(255) NOT NULL DEFAULT '',
            `date_mod`   datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ou_platform` (`ou_dn`(255), `platform`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$co}");
    }
    PluginAdmanagerProfile::installProfiles();
    return true;
}

function plugin_admanager_uninstall(): bool {
    global $DB;
    foreach (['glpi_plugin_admanager_auditlogs','glpi_plugin_admanager_importlogs',
              'glpi_plugin_admanager_syncstates',
              'glpi_plugin_admanager_ad_cache',
              'glpi_plugin_admanager_ad_sync_log',
              'glpi_plugin_admanager_usertemplates',
              'glpi_plugin_admanager_im_bindings',
              'glpi_plugin_admanager_im_depts'] as $t) {
        $DB->queryOrDie("DROP TABLE IF EXISTS `{$t}`");
    }
    Config::deleteConfigurationValues('plugin:admanager');
    ProfileRight::deleteProfileRights(['plugin_admanager_read','plugin_admanager_write_ad',
        'plugin_admanager_reset_pwd','plugin_admanager_deploy','plugin_admanager_admin']);
    return true;
}

function plugin_init_admanager(): void {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::CSRF_COMPLIANT]['admanager'] = true;

    // 注册类自动加载
    Plugin::registerClass(PluginAdmanagerUserTemplate::class);

    // config_page 钩子无条件注册（插件列表页需要显示⚙️按钮，与登录状态无关）
    $PLUGIN_HOOKS['config_page']['admanager'] = 'front/config.form.php';

    // 以下需要登录后才能注册
    if (!Session::getLoginUserID()) return;

    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::REDEFINE_MENUS]['admanager']   = 'plugin_admanager_redefine_menus';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::SECURED_CONFIGS]['admanager']  = ['ad_password','fastapi_token','wecom_corpsecret','dingtalk_appsecret','feishu_appsecret','im_notify_smtp_pass','openclaw_token'];
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::DASHBOARD_CARDS]['admanager']  = 'plugin_admanager_get_dashboard_cards';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_ADD]['admanager']['Computer']    = 'plugin_admanager_item_add_computer';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ITEM_UPDATE]['admanager']['Computer'] = 'plugin_admanager_item_update_computer';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_CSS]['admanager'][]        = 'css/admanager-layout.css';
$PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_CSS]['admanager'][]        = 'css/admanager.css';
    $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::ADD_JAVASCRIPT]['admanager'][] = 'js/admanager.js';

    // 注册 Profile tab（官方标准做法）
    Plugin::registerClass(PluginAdmanagerProfile::class, ['addtabon' => ['Profile']]);
}

function plugin_admanager_redefine_menus(array $menus): array {
    if (!Session::getLoginUserID()) return $menus;
    if (!Session::haveRight('plugin_admanager_read', READ)) return $menus;

    // 左侧菜单：独立页 + 分组入口（分组入口点进首页后通过页内 tab 切换，参照漏洞修复模式）
    $items = [
        'admanager_diff'    => ['title'=>'总览',       'icon'=>'ti ti-dashboard',       'page'=>'/plugins/admanager/front/dashboard.php'],
        'admanager_vuln'    => ['title'=>'漏洞修复',   'icon'=>'ti ti-shield-check',    'page'=>'/plugins/admanager/front/vuln_import.php'],

        // 终端（页内 tab: 手动导入 / 终端分组）
        'admanager_terminal'=> ['title'=>'终端',       'icon'=>'ti ti-devices',          'page'=>'/plugins/admanager/front/import.php'],

        // AD管理（页内 tab: AD用户管理 / 计算机查询 / BitLocker密钥 / AD安全组 / 用户模板）
        'admanager_admgr'   => ['title'=>'AD管理',     'icon'=>'ti ti-users-group',      'page'=>'/plugins/admanager/front/aduser.php'],

        // 软件计划（页内 tab: 软件部署 / 部署配置）
        'admanager_software'=> ['title'=>'软件计划',   'icon'=>'ti ti-apps',             'page'=>'/plugins/admanager/front/deploy.php'],

        'admanager_im'      => ['title'=>'通讯平台',   'icon'=>'ti ti-brand-wechat',     'page'=>'/plugins/admanager/front/im.php'],
        'admanager_audit'   => ['title'=>'审计日志',   'icon'=>'ti ti-clipboard-list',   'page'=>'/plugins/admanager/front/auditlog.php'],

        // 设置（页内 tab: 连接配置 / 关于）
        'admanager_settings'=> ['title'=>'设置',       'icon'=>'ti ti-settings',         'page'=>'/plugins/admanager/front/config.form.php'],
    ];

    $menus['plugins']['content'] = [];
    foreach ($items as $key => $item) {
        $menus['plugins']['content'][$key] = $item;
    }

    return $menus;
}

function plugin_admanager_get_dashboard_cards(): array {
    return PluginAdmanagerDashboard::getDashboardCards();
}
function plugin_admanager_item_add_computer(Computer $c): void {
    PluginAdmanagerSyncState::onComputerChange($c, 'add');
}
function plugin_admanager_item_update_computer(Computer $c): void {
    PluginAdmanagerSyncState::onComputerChange($c, 'update');
}
