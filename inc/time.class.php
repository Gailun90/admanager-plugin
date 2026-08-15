<?php
/**
 * PluginAdmanagerTime — 统一时间格式化
 *
 * 背景：插件各列表页时间显示风格不一（原始 DB 直出 / Twig |date / ISO 手动 slice+replace /
 * JS substring+replace / new Date().toLocaleString），且都没走 GLPI 的 Html::convDateTime，
 * 因此不尊重用户在 GLPI 里配置的日期格式偏好，FastAPI 的 UTC 时间也没转本地时区（差 8 小时）。
 *
 * 这里提供一个统一入口：调用方把各种形态的时间值丢进来，本类负责：
 *   1. 识别形态（MySQL datetime / ISO8601 UTC / unix 时间戳 / AD 原始 YYYYMMDDHHMMSS / 空值）；
 *   2. UTC 来源（ISO/unix/AD 原始）统一转换到 Asia/Shanghai；MySQL 本地时间按展示时区解析，不转换；
 *   3. 交给 GLPI 的 Html::convDateTime / Html::convDate 输出（尊重用户日期格式）。
 *
 * 用法（twig 里用预格式化的 *_fmt 字段，不要在模板里再拼时间）：
 *   $row['date_mod_fmt'] = PluginAdmanagerTime::fmt($row['date_mod']);
 */

class PluginAdmanagerTime {

   /** 插件统一展示时区（FastAPI 后端与 AD 均为 UTC 来源）。 */
   const TZ = 'Asia/Shanghai';

   /**
    * 统一格式化时间。
    *
    * @param mixed $value  时间值（字符串/int），支持多种形态
    * @param bool  $withTime true=带时间(Html::convDateTime)，false=仅日期(Html::convDate)
    * @return string 格式化后字符串；空/非法值返回 '—'
    */
   public static function fmt($value, $withTime = true) {
      if ($value === null || $value === '' || $value === false) {
         return '—';
      }

      $dt = self::toDateTime($value);
      if ($dt === null) {
         // 解析不了时原样回退，避免吞掉信息（仅当本身是字符串）
         return is_string($value) ? $value : '—';
      }

      // 统一到展示时区
      $dt->setTimezone(new \DateTimeZone(self::TZ));
      $mysql = $dt->format('Y-m-d H:i:s');

      return $withTime ? \Html::convDateTime($mysql) : \Html::convDate($mysql);
   }

   /**
    * 把多种形态的时间值转成 DateTime 对象（已带正确时区语义）。
    * 返回 null 表示空值或无法解析。
    *
    * @param mixed $value
    * @return \DateTime|null
    */
   private static function toDateTime($value) {
      // 数值型 unix 时间戳
      if (is_int($value) || is_float($value)) {
         return self::fromUnix((int) $value);
      }
      if (!is_string($value)) {
         return null;
      }

      $v = trim($value);

      // 零值
      if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') {
         return null;
      }

      // AD 原始 YYYYMMDDHHMMSS（14 位数字，UTC 来源）
      if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $v, $m)) {
         try {
            return new \DateTime(
               "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}",
               new \DateTimeZone('UTC')
            );
         } catch (\Throwable $e) {
            return null;
         }
      }

      // 纯数字串 → 当作 unix 时间戳（秒）
      if (ctype_digit($v) && (int) $v > 1_000_000_000) {
         return self::fromUnix((int) $v);
      }

      // ISO8601（含 T）：FastAPI 后端返回，UTC 来源
      if (strpos($v, 'T') !== false) {
         $iso = $v;
         // 尾部 Z → +00:00
         if (substr($iso, -1) === 'Z' || substr($iso, -1) === 'z') {
            $iso = substr($iso, 0, -1) . '+00:00';
         }
         // 无偏移信息 → 视为 UTC（插件约定 FastAPI 给的是 UTC）
         if (!preg_match('/[+\-]\d{2}:?\d{2}$/', $iso)) {
            try {
               return new \DateTime($iso, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
               return null;
            }
         }
         try {
            return new \DateTime($iso);
         } catch (\Throwable $e) {
            return null;
         }
      }

      // MySQL datetime（已是展示时区 Asia/Shanghai 的本地时间，按展示时区解析，不要当 UTC 转换）
      if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
         try {
            return new \DateTime($v, new \DateTimeZone(self::TZ));
         } catch (\Throwable $e) {
            return null;
         }
      }

      // MySQL date（仅日期，同上按展示时区解析）
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
         try {
            return new \DateTime($v, new \DateTimeZone(self::TZ));
         } catch (\Throwable $e) {
            return null;
         }
      }

      // 兜底：按展示时区尽力解析
      try {
         return new \DateTime($v, new \DateTimeZone(self::TZ));
      } catch (\Throwable $e) {
         return null;
      }
   }

   /**
    * @param int $ts unix 秒
    * @return \DateTime|null
    */
   private static function fromUnix($ts) {
      if ($ts <= 0) {
         return null;
      }
      try {
         return new \DateTime('@' . $ts);
      } catch (\Throwable $e) {
         return null;
      }
   }
}
