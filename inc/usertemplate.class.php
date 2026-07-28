<?php
/**
 * inc/usertemplate.class.php — AD用户创建模板 v4.3
 *
 * 数据结构统一为 fields 数组：
 * [
 *   { key: 'mail',       default: 'xxx@co.com', enabled: true  },
 *   { key: 'department', default: '技术部',      enabled: true  },
 *   { key: 'manager',    default: '',           enabled: false },
 * ]
 *
 * - enabled=true  → 右侧"已设置"，创建用户时显示该字段
 * - enabled=false → 左侧"未设置"，不在模板中
 * - default       → 该字段的默认值（创建用户时自动填入，可改）
 *
 * 顶层预设字段（enabled=true 且 always_fixed=true）不提供编辑框，
 * 创建用户时直接以模板值为准，用户完全不可见，例：
 * - ou (目标OU)：用户建在哪里，建户时就定了，不展示给用户改
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerUserTemplate extends CommonDBTM
{
    // ── 字段元数据定义 ────────────────────────────────────────────────────
    // key → [ad_attr, 显示名, input类型, 是否固定预设(创建时自动填，不让用户改)]
    static function allFields(): array {
        return [
            'ou'         => ['ou',      '目标OU',           'text',   true],
            'department' => ['department', '部门',           'text',   false],
            'company'    => ['company', '公司',             'text',   false],
            'division'   => ['division','分部',             'text',   false],
            'manager'    => ['manager', '经理DN',            'text',   false],
            'co'         => ['co',      '国家/地区',         'text',   false],
            'physicaldeliveryofficename' => ['physicaldeliveryofficename', '办公室', 'text', false],
            'sn'         => ['sn',      '姓',               'text',   false],
            'givenname'  => ['givenname','名',              'text',   false],
            'mail'       => ['mail',    '邮箱',             'email',  false],
            'title'      => ['title',   '职位',             'text',   false],
            'telephonenumber' => ['telephonenumber', '办公电话', 'text', false],
            'mobile'     => ['mobile',  '手机号',           'text',   false],
            'facsimiletelephonenumber' => ['facsimiletelephonenumber', '传真', 'text', false],
            'wWWHomePage'=> ['wWWHomePage','主页',         'url',   false],
            'streetaddress' => ['streetaddress', '街道地址', 'text', false],
            'l'          => ['l',       '城市',            'text',   false],
            'st'         => ['st',      '省份',             'text',   false],
            'postalcode' => ['postalcode','邮编',           'text',   false],
            'employeenumber' => ['employeenumber', '员工编号', 'text', false],
            'employeetype'   => ['employeetype',   '员工类型', 'text', false],
            'description'=> ['description','描述',          'textarea', false],
            'info'       => ['info',    '备注',             'textarea', false],
        ];
    }

    /** 固定预设字段（enabled=true 且 always_fixed=true），这些不展示编辑框 */
    static function fixedFieldKeys(): array {
        return ['ou'];
    }

    /** 允许用户编辑默认值的字段（不在 fixedFieldKeys 里） */
    static function editableFieldKeys(): array {
        return array_keys(self::allFields());
    }

    // ── 模板列表 ───────────────────────────────────────────────────────────

    static function getAll(bool $include_empty = true): array {
        global $DB;
        $list = [];
        if ($include_empty) {
            $list[] = ['id' => 0, 'name' => '不使用模板'];
        }
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'id ASC']) as $r) {
            $list[] = $r;
        }
        return $list;
    }

    /**
     * 获取单个模板：解析 fields JSON
     */
    static function getTemplateById(int $id): ?array {
        global $DB;
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$row) return null;
        $row['fields'] = json_decode($row['fields'] ?? '[]', true) ?: [];
        return $row;
    }

    /**
     * 保存模板（新建/更新）
     * @param int    $id     0=新建
     * @param string $name   模板名称
     * @param array  $fields fields[] 数组
     */
    static function saveTemplate(int $id, string $name, array $fields): array {
        global $DB;

        // 过滤：移除空的 default
        $fields = array_values(array_filter($fields, function($f) {
            return isset($f['key']) && !empty($f['key']);
        }));

        $data = [
            'name'   => $name,
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'date_mod' => date('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            $DB->update(self::getTable(), $data, ['id' => $id]);
            PluginAdmanagerAuditLog::write('update_template', 'UserTemplate', (string)$id, $name);
        } else {
            $data['date_creation'] = date('Y-m-d H:i:s');
            $DB->insert(self::getTable(), $data);
            $id = $DB->insertId();
            PluginAdmanagerAuditLog::write('create_template', 'UserTemplate', (string)$id, $name);
        }
        return ['ok' => true, 'id' => $id, 'message' => '模板已保存'];
    }

    /**
     * 删除模板
     */
    static function deleteById(int $id): array {
        global $DB;
        $DB->delete(self::getTable(), ['id' => $id]);
        PluginAdmanagerAuditLog::write('delete_template', 'UserTemplate', (string)$id, '');
        return ['ok' => true, 'message' => '模板已删除'];
    }

    public static function getTypeName($nb = 0): string { return '用户创建模板'; }
    public static function getTable($get_full = true): string {
        return 'glpi_plugin_admanager_usertemplates';
    }
    public function defineTabs($options = []): array { return []; }
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string { return ''; }
}