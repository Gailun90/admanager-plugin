<?php
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerProfile extends CommonDBTM
{
    const RIGHTS = [
        'plugin_admanager_read',
        'plugin_admanager_write_ad',
        'plugin_admanager_reset_pwd',
        'plugin_admanager_deploy',
        'plugin_admanager_admin',
    ];

    public static function canView(): bool    { return Session::haveRight('plugin_admanager_read', READ); }
    public static function canCreate(): bool   { return Session::haveRight('plugin_admanager_admin', CREATE); }
    public static function canUpdate(): bool   { return Session::haveRight('plugin_admanager_admin', UPDATE); }

    public static function installProfiles(): void {
        ProfileRight::addProfileRights(self::RIGHTS);
        // super-admin (id=4) 自动获得全部权限
        $all = READ | UPDATE | CREATE | DELETE | PURGE | ADMANAGER_RESET_PWD | ADMANAGER_DEPLOY;
        ProfileRight::updateProfileRights(4, array_fill_keys(self::RIGHTS, $all));
    }

    public static function checkRight(string $right, int $access = READ): void {
        if (!Session::haveRight("plugin_admanager_{$right}", $access)) {
            Html::displayRightError(); exit;
        }
    }

    public static function canDo(string $right, int $access = READ): bool {
        return Session::haveRight("plugin_admanager_{$right}", $access);
    }

    public static function getTypeName($nb = 0): string { return 'AD管控权限'; }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array {
        return ($item instanceof Profile) ? self::getTypeName() : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
        if ($item instanceof Profile) self::showProfileForm($item);
        return true;
    }

    public static function showProfileForm(Profile $profile): void {
        $profile->displayRightsChoiceMatrix([
            ['itemtype' => 'PluginAdmanagerProfile', 'label' => '查看（资产/差异报告/AD信息）',   'field' => 'plugin_admanager_read'],
            ['itemtype' => 'PluginAdmanagerProfile', 'label' => 'AD写操作（禁用/创建/组/OU）',    'field' => 'plugin_admanager_write_ad'],
            ['itemtype' => 'PluginAdmanagerProfile', 'label' => '重置AD用户密码',                 'field' => 'plugin_admanager_reset_pwd'],
            ['itemtype' => 'PluginAdmanagerProfile', 'label' => '部署管理（任务/策略）',          'field' => 'plugin_admanager_deploy'],
            ['itemtype' => 'PluginAdmanagerProfile', 'label' => '插件完整管理（含连接配置）',     'field' => 'plugin_admanager_admin'],
        ], ['title' => 'IT资产管控插件权限', 'canedit' => $profile->canEdit($profile->getID())]);
    }
}
