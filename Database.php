<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Database installation and migration helpers shared by MySQL and SQLite.
 */
class VisitorLoggerPro_Database
{
    public static function install()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = self::quoteIdentifier($prefix . 'visitor_log');

        if (self::isSQLite($db)) {
            $sql = "CREATE TABLE IF NOT EXISTS {$table} ("
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'ip VARCHAR(45) NOT NULL,'
                . 'route VARCHAR(255) NOT NULL,'
                . 'country VARCHAR(100) DEFAULT NULL,'
                . 'region VARCHAR(100) DEFAULT NULL,'
                . 'city VARCHAR(100) DEFAULT NULL,'
                . "visitor_hash CHAR(32) NOT NULL DEFAULT '',"
                . "user_agent TEXT DEFAULT '',"
                . 'time DATETIME NOT NULL'
                . ')';
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$table} ("
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
                . '`ip` VARCHAR(45) NOT NULL,'
                . '`route` VARCHAR(255) NOT NULL,'
                . '`country` VARCHAR(100) DEFAULT NULL,'
                . '`region` VARCHAR(100) DEFAULT NULL,'
                . '`city` VARCHAR(100) DEFAULT NULL,'
                . "`visitor_hash` CHAR(32) NOT NULL DEFAULT '',"
                . "`user_agent` TEXT NULL,"
                . '`time` DATETIME NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        $db->query($sql, Typecho_Db::WRITE);
        self::addMissingColumns($db, $prefix);
        self::createIndexes($db, $prefix);
        self::backfillVisitorHashes($db, $prefix);
        self::migrateLocations($db, $prefix);
    }

    public static function isSQLite($db = null)
    {
        $db = $db ?: Typecho_Db::get();
        return stripos($db->getAdapterName(), 'sqlite') !== false;
    }

    public static function visitorHash($ip, $userAgent)
    {
        return md5((string) $ip . "\x1f" . (string) $userAgent);
    }

    public static function siteDate($format = 'Y-m-d H:i:s', $timestamp = null)
    {
        $timestamp = $timestamp === null ? time() : (int) $timestamp;
        $timezone = Helper::options()->timezone;
        if (is_numeric($timezone)) {
            return gmdate($format, $timestamp + (int) $timezone);
        }
        try {
            $date = new DateTime('@' . $timestamp);
            $date->setTimezone(new DateTimeZone((string) $timezone));
            return $date->format($format);
        } catch (Exception $e) {
            return date($format, $timestamp);
        }
    }

    /**
     * Run retention cleanup at most once per calendar day.
     */
    public static function maybeCleanup($force = false)
    {
        $options = Helper::options();
        $pluginOptions = $options->plugin('VisitorLoggerPro');
        $enabled = !isset($pluginOptions->autoCleanup) || (string) $pluginOptions->autoCleanup === '1';
        if (!$enabled) {
            return 0;
        }

        $days = isset($pluginOptions->retentionDays) ? (int) $pluginOptions->retentionDays : 90;
        $days = max(1, min(3650, $days));
        $today = self::siteDate('Y-m-d');
        $lastRun = isset($options->visitorLoggerProLastCleanup) ? (string) $options->visitorLoggerProLastCleanup : '';
        if (!$force && $lastRun === $today) {
            return 0;
        }

        $db = Typecho_Db::get();
        $deleted = $db->query(
            $db->delete('table.visitor_log')->where('time < ?', self::siteDate('Y-m-d H:i:s', time() - $days * 86400))
        );
        self::setRuntimeOption($db, 'visitorLoggerProLastCleanup', $today);
        return (int) $deleted;
    }

    private static function addMissingColumns($db, $prefix)
    {
        $columns = self::getColumns($db, $prefix);
        $definitions = array(
            'region' => 'VARCHAR(100) DEFAULT NULL',
            'city' => 'VARCHAR(100) DEFAULT NULL',
            'user_agent' => self::isSQLite($db) ? "TEXT DEFAULT ''" : 'TEXT NULL',
            'visitor_hash' => "CHAR(32) NOT NULL DEFAULT ''"
        );
        $table = self::quoteIdentifier($prefix . 'visitor_log');
        foreach ($definitions as $name => $definition) {
            if (!isset($columns[$name])) {
                $db->query(
                    "ALTER TABLE {$table} ADD COLUMN " . self::quoteIdentifier($name) . " {$definition}",
                    Typecho_Db::WRITE
                );
            }
        }
    }

    private static function getColumns($db, $prefix)
    {
        $tableName = $prefix . 'visitor_log';
        $rows = self::isSQLite($db)
            ? $db->fetchAll('PRAGMA table_info(' . self::quoteIdentifier($tableName) . ')')
            : $db->fetchAll('SHOW COLUMNS FROM ' . self::quoteIdentifier($tableName));
        $columns = array();
        foreach ($rows as $row) {
            $name = self::isSQLite($db) ? $row['name'] : $row['Field'];
            $columns[$name] = true;
        }
        return $columns;
    }

    private static function createIndexes($db, $prefix)
    {
        $tableName = $prefix . 'visitor_log';
        $indexes = array(
            'idx_vlp_time' => array('time'),
            'idx_vlp_ip_time' => array('ip', 'time'),
            'idx_vlp_country' => array('country'),
            'idx_vlp_region' => array('region'),
            'idx_vlp_route' => array('route'),
            'idx_vlp_visitor_hash' => array('visitor_hash')
        );

        if (self::isSQLite($db)) {
            foreach ($indexes as $name => $columns) {
                $quoted = array_map(array(__CLASS__, 'quoteIdentifier'), $columns);
                $db->query(
                    'CREATE INDEX IF NOT EXISTS ' . self::quoteIdentifier($name) . ' ON '
                    . self::quoteIdentifier($tableName) . ' (' . implode(', ', $quoted) . ')',
                    Typecho_Db::WRITE
                );
            }
            return;
        }

        $existing = $db->fetchAll('SHOW INDEX FROM ' . self::quoteIdentifier($tableName));
        $existingNames = array();
        foreach ($existing as $row) {
            $existingNames[$row['Key_name']] = true;
        }
        foreach ($indexes as $name => $columns) {
            if (!isset($existingNames[$name])) {
                // 兼容仍使用 767 字节索引上限的旧版 MySQL。
                $quoted = $name === 'idx_vlp_route'
                    ? array(self::quoteIdentifier('route') . '(100)')
                    : array_map(array(__CLASS__, 'quoteIdentifier'), $columns);
                $db->query(
                    'ALTER TABLE ' . self::quoteIdentifier($tableName) . ' ADD INDEX '
                    . self::quoteIdentifier($name) . ' (' . implode(', ', $quoted) . ')',
                    Typecho_Db::WRITE
                );
            }
        }
    }

    private static function backfillVisitorHashes($db, $prefix)
    {
        do {
            $rows = $db->fetchAll(
                $db->select('id', 'ip', 'user_agent')
                    ->from($prefix . 'visitor_log')
                    ->where('visitor_hash IS NULL OR visitor_hash = ?', '')
                    ->limit(500)
            );
            foreach ($rows as $row) {
                $db->query(
                    $db->update($prefix . 'visitor_log')
                        ->rows(array('visitor_hash' => self::visitorHash($row['ip'], $row['user_agent'])))
                        ->where('id = ?', (int) $row['id'])
                );
            }
        } while (count($rows) === 500);
    }

    private static function migrateLocations($db, $prefix)
    {
        if (!class_exists('VisitorLoggerPro_Location')) {
            require_once dirname(__FILE__) . '/Location.php';
        }
        $lastId = 0;
        do {
            $rows = $db->fetchAll(
                $db->select('id', 'country', 'region', 'city')
                    ->from($prefix . 'visitor_log')
                    ->where('id > ?', $lastId)
                    ->where('(region IS NULL OR region = ? OR city IS NULL OR city = ?)', '', '')
                    ->order('id', Typecho_Db::SORT_ASC)
                    ->limit(500)
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $location = VisitorLoggerPro_Location::parse($row['country'], $row['region'], $row['city']);
                $db->query(
                    $db->update($prefix . 'visitor_log')->rows($location)->where('id = ?', $lastId)
                );
            }
        } while (count($rows) === 500);
    }

    private static function setRuntimeOption($db, $name, $value)
    {
        $exists = $db->fetchRow($db->select('name')->from('table.options')->where('name = ?', $name)->limit(1));
        if ($exists) {
            $db->query($db->update('table.options')->rows(array('value' => $value))->where('name = ?', $name));
        } else {
            $db->query($db->insert('table.options')->rows(array('name' => $name, 'user' => 0, 'value' => $value)));
        }
    }

    public static function quoteIdentifier($identifier)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier');
        }
        return '`' . $identifier . '`';
    }
}
