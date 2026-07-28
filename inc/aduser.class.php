<?php
/**
 * inc/aduser.class.php — AD 用户实体类
 * 封装 AD 用户在 GLPI 中的建模、搜索和操作（禁用/解锁/重置密码等）
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerAdUser extends CommonDBTM
{
    static $rightname  = 'plugin_admanager_admin';

    public static function getTypeName($nb = 0): string {
        return 'AD 用户';
    }

    /**
     * 从 AD 查到的用户数组中找一条记录
     */
    public static function getFromLdapEntry(string $sam): ?array {
        try {
            return PluginAdmanagerAdLdap::getInstance()->findUser($sam);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 根据 DN 禁用 / 启用用户
     */
    public static function setEnabled(string $dn, bool $enabled): bool {
        try {
            $ok = PluginAdmanagerAdLdap::getInstance()->setUserEnabled($dn, $enabled);
            PluginAdmanagerAuditLog::write(
                $enabled ? 'enable_user' : 'disable_user',
                'ADUser', $dn, $dn, [], $ok
            );
            return $ok;
        } catch (\Throwable $e) {
            PluginAdmanagerAuditLog::write(
                $enabled ? 'enable_user' : 'disable_user',
                'ADUser', $dn, $dn, [], false, $e->getMessage()
            );
            return false;
        }
    }

    /**
     * 根据 DN 解锁用户
     */
    public static function unlock(string $dn): bool {
        try {
            $ok = PluginAdmanagerAdLdap::getInstance()->unlockUser($dn);
            PluginAdmanagerAuditLog::write('unlock_user', 'ADUser', $dn, $dn, [], $ok);
            return $ok;
        } catch (\Throwable $e) {
            PluginAdmanagerAuditLog::write('unlock_user', 'ADUser', $dn, $dn, [], false, $e->getMessage());
            return false;
        }
    }

    /**
     * 重置密码（需要 LDAPS）
     */
    public static function resetPassword(string $dn, string $password): bool {
        try {
            $ok = PluginAdmanagerAdLdap::getInstance()->resetPassword($dn, $password);
            PluginAdmanagerAuditLog::write('reset_pwd', 'ADUser', $dn, $dn,
                ['pwd_length' => mb_strlen($password)], $ok);
            return $ok;
        } catch (\Throwable $e) {
            PluginAdmanagerAuditLog::write('reset_pwd', 'ADUser', $dn, $dn,
                [], false, $e->getMessage());
            return false;
        }
    }

    /**
     * 移动用户到目标 OU
     */
    public static function moveOU(string $dn, string $target_ou): bool {
        try {
            $ok = PluginAdmanagerAdLdap::getInstance()->moveUser($dn, $target_ou);
            PluginAdmanagerAuditLog::write('move_ou', 'ADUser', $dn, $dn,
                ['target_ou' => $target_ou], $ok);
            return $ok;
        } catch (\Throwable $e) {
            PluginAdmanagerAuditLog::write('move_ou', 'ADUser', $dn, $dn,
                ['target_ou' => $target_ou], false, $e->getMessage());
            return false;
        }
    }

    /**
     * 添加 / 移除用户组
     */
    public static function setGroupMembership(string $user_dn, string $group_dn, bool $add): bool {
        try {
            $ok = PluginAdmanagerAdLdap::getInstance()->modifyGroupMembership($user_dn, $group_dn, $add);
            PluginAdmanagerAuditLog::write(
                $add ? 'add_group' : 'remove_group',
                'ADUser', $user_dn, $user_dn,
                ['group_dn' => $group_dn], $ok
            );
            return $ok;
        } catch (\Throwable $e) {
            PluginAdmanagerAuditLog::write(
                $add ? 'add_group' : 'remove_group',
                'ADUser', $user_dn, $user_dn,
                ['group_dn' => $group_dn], false, $e->getMessage()
            );
            return false;
        }
    }
}