<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 访客统计插件-美化版，基于<a href="https://blog.ybyq.wang" target="_blank">璇</a>的插件进行二次开发<a href="https://github.com/Chocola-X/VisitorLoggerPro-Enhanced" target="_blank">项目地址&使用帮助</a>
 * 
 * @package VisitorLoggerPro
 * @author GTX690战术核显卡导弹
 * @version 2.3.0
 * @link https://www.nekopara.uk
 */

// 加载兼容适配器
require_once dirname(__FILE__) . '/adapter.php';
require_once dirname(__FILE__) . '/Database.php';
require_once dirname(__FILE__) . '/Statistics.php';
require_once dirname(__FILE__) . '/Location.php';

require_once dirname(__FILE__) . '/ipdata/src/IpLocation.php';
require_once dirname(__FILE__) . '/ipdata/src/ipdbv6.func.php';

use vlp\Ip\IpLocation;

require_once dirname(__FILE__) . '/ip2region/src/XdbSearcher.php';

use vlp\ip2region\XdbSearcher;

class VisitorLoggerPro_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        try {
            VisitorLoggerPro_Database::install();
        } catch (Exception $e) {
            throw new Typecho_Plugin_Exception('创建或升级访客日志表失败: ' . $e->getMessage());
        }

        // 注册访客统计API
        Helper::addAction('visitor-stats-api', 'VisitorLoggerPro_Action');

        // 注册统计模板和钩子
        Typecho_Plugin::factory('Widget_Archive')->handle = array('VisitorLoggerPro_Plugin', 'handleTemplate');
        Typecho_Plugin::factory('Widget_Archive')->header = array('VisitorLoggerPro_Plugin', 'logVisitorInfo');

        Helper::addPanel(1, 'VisitorLoggerPro/panel.php', '访客日志', '查看访客日志', 'administrator');
        Helper::addPanel(1, 'VisitorLoggerPro/trend.php', '趋势分析', '访客趋势分析', 'administrator');

        return '插件已激活，访客日志功能已启用。';
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removePanel(1, 'VisitorLoggerPro/panel.php');
        Helper::removePanel(1, 'VisitorLoggerPro/trend.php');
        // 清理由旧版本注册到“撰写”菜单下的面板。
        Helper::removePanel(2, 'VisitorLoggerPro/trend.php');
        Helper::removeAction('visitor-stats-api');
        return '插件已禁用，访客日志功能已停用。';
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /* botlist设置 */
        $bots = array(
            'baidu=>百度',
            'google=>谷歌',
            'sogou=>搜狗',
            'youdao=>有道',
            'soso=>搜搜',
            'bing=>必应',
            'yahoo=>雅虎',
            '360=>360搜索'
        );

        $botList = new Typecho_Widget_Helper_Form_Element_Textarea('botList', null, implode("\n", $bots), _t('蜘蛛记录设置'), _t('请按照格式填入蜘蛛信息，英文关键字不能超过16个字符'));

        $form->addInput($botList);

        /* 忽略IP设置 */
        $ignoreIPs = new Typecho_Widget_Helper_Form_Element_Textarea(
            'ignoreIPs',
            null,
            '',
            _t('忽略的IP地址'),
            _t('请输入不需要记录的IP地址，每行一个IP地址。支持以下格式：<br>' .
                '1. 精确匹配：192.168.1.1<br>' .
                '2. 通配符匹配：192.168.*.*（使用星号作为通配符）<br>' .
                '3. CIDR格式：192.168.1.0/24（指定网段）<br>' .
                '支持IPv4和IPv6格式。')
        );
        $form->addInput($ignoreIPs);

        /* IPV4数据库选择 */
        $ipv4db = new Typecho_Widget_Helper_Form_Element_Radio(
            'ipv4db',
            array('ip2region' => _t('ip2region数据库'), 'cz88' => _t('纯真数据库')),
            'ip2region',
            'IPV4数据库选项',
            _t('<strong>纯真数据库(cz88):</strong> 更新勤快，数据详尽，但可能包含一些非标准信息（如"网吧"），插件已做过滤处理。<br><strong>ip2region数据库:</strong> 查询速度快，格式标准统一，准确性高。推荐使用。')
        );
        $form->addInput($ipv4db);

        /* 启用访客统计 */
        $enableStats = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableStats',
            array(
                '1' => _t('启用'),
                '0' => _t('禁用')
            ),
            '1',
            _t('启用访客统计'),
            _t('是否启用访客统计功能')
        );
        $form->addInput($enableStats);

        $trustProxy = new Typecho_Widget_Helper_Form_Element_Radio(
            'trustProxy',
            array('1' => _t('信任'), '0' => _t('不信任')),
            '0',
            _t('信任反向代理 IP 请求头'),
            _t('仅当站点位于可信反向代理或 CDN 后方时启用。启用后读取 X-Forwarded-For 的首个有效地址。')
        );
        $form->addInput($trustProxy);

        $autoCleanup = new Typecho_Widget_Helper_Form_Element_Radio(
            'autoCleanup',
            array('1' => _t('启用'), '0' => _t('关闭')),
            '1',
            _t('自动清理历史访问数据'),
            _t('每天最多执行一次，删除超过保留天数的数据。')
        );
        $form->addInput($autoCleanup);

        $retentionDays = new Typecho_Widget_Helper_Form_Element_Text(
            'retentionDays',
            null,
            '90',
            _t('数据保留天数'),
            _t('默认 90 天，可设置 1 到 3650 天。关闭自动清理后此项不生效。')
        );
        $form->addInput($retentionDays);

        /* 插件背景设置 */
        $backgroundUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'backgroundUrl',
            null,
            'https://pic.nekopara.uk/?format=webp', // 默认值
            _t('插件背景设置'),
            _t('可填写图片API的URL（如随机图片API）或具体图片的URL直链。默认使用猫娘乐园图片API')
        );
        $form->addInput($backgroundUrl);

        /* 插件卡片颜色设置 */
        $backgroundColour = new Typecho_Widget_Helper_Form_Element_Text(
            'backgroundColour',
            null,
            '#ffffffc4', // 默认值
            _t('插件展示内容卡片颜色设置，采用HTML颜色代码')
        );
        $form->addInput($backgroundColour);
    }


    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}


    /**
     * 获取蜘蛛列表
     *
     * @return array
     */
    public static function getBotsList()
    {
        $bots = array();
        $_bots = explode("|", str_replace(array("\r\n", "\r", "\n"), "|", Helper::options()->plugin('VisitorLoggerPro')->botList));
        foreach ($_bots as $_bot) {
            $_bot = array_map('trim', explode("=>", $_bot, 2));
            if (count($_bot) === 2 && $_bot[0] !== '') {
                $bots[strval($_bot[0])] = $_bot[1];
            }
        }
        return $bots;
    }


    /**
     * 蜘蛛记录函数
     *
     * @param mixed $rule
     * @return boolean
     */
    public static function isBot()
    {
        $botList = self::getBotsList();
        $bot = NULL;
        if (count($botList) > 0) {
            $request = Typecho_Request::getInstance();
            $useragent = strtolower($request->getAgent());
            foreach ($botList as $key => $value) {
                if (strpos($useragent, strval($key)) !== false) {
                    $bot = $key;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 检查IP是否在忽略列表中
     *
     * @param string $ip 要检查的IP地址
     * @return boolean 如果在忽略列表中返回true，否则返回false
     */
    public static function isIgnoredIP($ip)
    {
        $filterFile = __DIR__ . '/ip_filters.json';
        if (is_file($filterFile)) {
            $filtered = json_decode((string) file_get_contents($filterFile), true);
            if (is_array($filtered) && in_array($ip, $filtered, true)) {
                return true;
            }
        }
        $options = Helper::options();
        if (!isset($options->plugin('VisitorLoggerPro')->ignoreIPs)) {
            return false;
        }

        $ignoreIPs = explode("\n", str_replace(array("\r\n", "\r"), "\n", $options->plugin('VisitorLoggerPro')->ignoreIPs));
        foreach ($ignoreIPs as $ignoreIP) {
            $ignoreIP = trim($ignoreIP);
            if (empty($ignoreIP)) {
                continue;
            }

            // 精确匹配
            if ($ignoreIP === $ip) {
                return true;
            }

            // 支持通配符 * 匹配，例如 192.168.*.*
            if (strpos($ignoreIP, '*') !== false) {
                $pattern = '/^' . str_replace(['*', '.'], ['[0-9]+', '\.'], $ignoreIP) . '$/';
                if (preg_match($pattern, $ip)) {
                    return true;
                }
            }

            // 支持CIDR格式，例如 192.168.1.0/24
            if (strpos($ignoreIP, '/') !== false) {
                list($subnet, $mask) = explode('/', $ignoreIP);
                if (self::ipInCIDR($ip, $subnet, $mask)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 检查IP是否在CIDR范围内
     *
     * @param string $ip 要检查的IP地址
     * @param string $subnet 子网地址
     * @param int $mask 子网掩码
     * @return boolean 如果在范围内返回true，否则返回false
     */
    private static function ipInCIDR($ip, $subnet, $mask)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 将IP地址转换为长整型
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if ($ip_long === false || $subnet_long === false) {
            return false;
        }

        // 计算网络掩码
        $mask_bits = pow(2, 32) - pow(2, (32 - intval($mask)));
        $mask_long = $mask_bits & 0xFFFFFFFF;

        // 判断是否在网络范围内
        return (($ip_long & $mask_long) == ($subnet_long & $mask_long));
    }

    public static function logVisitorInfo()
    {
        $pluginOptions = Helper::options()->plugin('VisitorLoggerPro');
        if (isset($pluginOptions->enableStats) && (string) $pluginOptions->enableStats !== '1') {
            return;
        }
        if (self::isBot()) {
            return;
        }
        if (isset($_COOKIE['visitorStats_selfExcluded']) && $_COOKIE['visitorStats_selfExcluded'] === 'true') {
            return;
        }
        $route = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $adminDir = defined('__TYPECHO_ADMIN_DIR__') ? trim(__TYPECHO_ADMIN_DIR__, '/') : 'admin';
        if (preg_match('#/(?:' . preg_quote($adminDir, '#') . ')(?:/|$)#', $route)) {
            return;
        }
        $db = Typecho_Db::get();
        $ip = self::getIpAddress();
        if ($ip === null) {
            return;
        }

        // 检查IP是否在忽略列表中
        if (self::isIgnoredIP($ip)) {
            return;
        }

        $location = self::getIpLocation($ip);
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

        $db->query($db->insert('table.visitor_log')->rows(array(
            'ip' => $ip,
            'route' => $route,
            'country' => $location['country'] ?? 'Unknown',
            'region' => $location['region'] ?? 'Unknown',
            'city' => $location['city'] ?? 'Unknown',
            'visitor_hash' => VisitorLoggerPro_Database::visitorHash($ip, $userAgent),
            'user_agent' => $userAgent,
            'time' => VisitorLoggerPro_Database::siteDate()
        )));

        try {
            VisitorLoggerPro_Database::maybeCleanup();
        } catch (Exception $e) {
            error_log('VisitorLoggerPro cleanup error: ' . $e->getMessage());
        }
    }

    public static function getVisitorLogs($page = 1, $pageSize = 10)
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $offset = ($page - 1) * $pageSize;

        $select = $db->select()->from($prefix . 'visitor_log')
            ->order('time', Typecho_Db::SORT_DESC)
            ->offset($offset)
            ->limit($pageSize);

        return $db->fetchAll($select);
    }

    public static function getSearchVisitorLogs($page = 1, $pageSize = 10, $ip = '')
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $offset = ($page - 1) * $pageSize;

        $select = $db->select()->from($prefix . 'visitor_log')
            ->order('time', Typecho_Db::SORT_DESC)
            ->offset($offset)
            ->limit($pageSize);

        if (!empty($ip)) {
            $select->where('ip LIKE ?', '%' . $ip . '%');
        }


        return $db->fetchAll($select);
    }


    public static function getIpAddress()
    {
        $pluginOptions = Helper::options()->plugin('VisitorLoggerPro');
        $trustProxy = isset($pluginOptions->trustProxy) && (string) $pluginOptions->trustProxy === '1';
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
    }

    public static function getIpLocation($ip)
    {
        static $requestCache = array();
        if (isset($requestCache[$ip])) {
            return $requestCache[$ip];
        }
        $cacheKey = 'vlp_location_' . md5(__TYPECHO_ROOT_DIR__ . '|' . $ip);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                return $requestCache[$ip] = $cached;
            }
        }

        $location = array('country' => 'Unknown', 'region' => 'Unknown', 'city' => 'Unknown');
        try {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (Helper::options()->plugin('VisitorLoggerPro')->ipv4db === 'cz88' && function_exists('iconv')) {
                    $location = VisitorLoggerPro_Location::fromCz88(IpLocation::getLocation($ip));
                } else {
                    static $searcher = null;
                    if ($searcher === null) {
                        $xdb = __DIR__ . DIRECTORY_SEPARATOR . 'ip2region/src/ip2region.xdb';
                        $vectorIndex = XdbSearcher::loadVectorIndexFromFile($xdb);
                        $searcher = XdbSearcher::newWithVectorIndex($xdb, $vectorIndex);
                    }
                    $location = VisitorLoggerPro_Location::fromIp2Region($searcher->search($ip));
                }
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $location = VisitorLoggerPro_Location::parse(self::ipquery($ip));
            }
        } catch (Throwable $e) {
            error_log('VisitorLoggerPro IP lookup error: ' . $e->getMessage());
        }

        $requestCache[$ip] = $location;
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $location, 86400);
        }
        return $location;
    }

    private static function ipquery($ip)
    {
        try {
            $db6 = new vlp\Ip\ipdbv6(__DIR__ . DIRECTORY_SEPARATOR . 'ipdata/src/zxipv6wry.db');
            $result = $db6->query($ip);
            $address = isset($result['addr']) && is_array($result['addr']) ? $result['addr'] : array();
            $raw = implode('', array_slice($address, 0, 2));
            return str_replace(
                array('无线基站网络', '公众宽带', '3GNET网络', 'CMNET网络', 'CTNET网络', "\t"),
                '',
                $raw
            );
        } catch (Throwable $e) {
            error_log('VisitorLoggerPro IPv6 lookup error: ' . $e->getMessage());
            return '';
        }
    }



    public static function cleanUpOldRecords($records)
    {
        $db = Typecho_Db::get();

        try {
            // 先获取总记录数，用于显示
            $totalRecords = $db->fetchObject($db->select(array('COUNT(*)' => 'num'))->from('table.visitor_log'))->num;

            if ($records <= 0) {
                // 如果输入为0或负数，则不执行删除操作
                return "请输入有效的清理条数（大于0）";
            }

            // 如果要删除的记录数大于等于总记录数，则清空表
            if ($records >= $totalRecords) {
                $db->query($db->delete('table.visitor_log'));
                return "已清空所有访问记录（原有 {$totalRecords} 条）";
            } else {
                // 只保留最新的 (总记录数-要删除的记录数) 条记录
                $keepRecords = $totalRecords - $records;

                // 获取要保留的记录的最早ID
                $minIdToKeep = $db->fetchObject(
                    $db->select('id')->from('table.visitor_log')
                        ->order('time', Typecho_Db::SORT_DESC)
                        ->offset($keepRecords - 1)
                        ->limit(1)
                )->id;

                // 删除ID小于最早ID的记录
                $deleteResult = $db->query($db->delete('table.visitor_log')->where('id < ?', $minIdToKeep));
                $deletedCount = $totalRecords - $db->fetchObject($db->select(array('COUNT(*)' => 'num'))->from('table.visitor_log'))->num;

                return "已清理 {$deletedCount} 条最早的访问记录（原有 {$totalRecords} 条，现有 " . ($totalRecords - $deletedCount) . " 条）";
            }
        } catch (Exception $e) {
            error_log("Error deleting records from visitor_log: " . $e->getMessage());
            return "清理记录失败: " . $e->getMessage();
        }
    }

    /**
     * 根据天数清理历史记录
     * 
     * @param int $days 要清理的天数，从最早的记录开始删除指定天数的记录
     * @return string 清理结果描述
     */
    public static function cleanUpRecordsByDays($days)
    {
        if ($days <= 0) {
            return "请输入有效的天数（大于0）";
        }

        $db = Typecho_Db::get();

        try {
            // 先获取总记录数，用于显示
            $totalRecords = $db->fetchObject($db->select(array('COUNT(*)' => 'num'))->from('table.visitor_log'))->num;

            if ($totalRecords == 0) {
                return "数据库中没有记录可清理";
            }

            // 获取最早的记录日期
            $earliestRecord = $db->fetchRow(
                $db->select('time')
                    ->from('table.visitor_log')
                    ->order('time', Typecho_Db::SORT_ASC)
                    ->limit(1)
            );

            $earliestDate = strtotime($earliestRecord['time']);
            $endDeleteDate = strtotime("+{$days} days", $earliestDate);
            $endDateFormatted = date('Y-m-d H:i:s', $endDeleteDate);

            // 删除从最早记录到指定天数内的记录
            $deleteResult = $db->query($db->delete('table.visitor_log')->where('time < ?', $endDateFormatted));
            $currentRecords = $db->fetchObject($db->select(array('COUNT(*)' => 'num'))->from('table.visitor_log'))->num;
            $deletedCount = $totalRecords - $currentRecords;

            if ($deletedCount > 0) {
                return "已删除最早的 {$days} 天数据（从 " . $earliestRecord['time'] . " 到 " . $endDateFormatted . "），共 {$deletedCount} 条记录（原有 {$totalRecords} 条，现有 {$currentRecords} 条）";
            } else {
                return "没有找到从 " . $earliestRecord['time'] . " 开始的 {$days} 天内的记录需要清理";
            }
        } catch (Exception $e) {
            error_log("Error deleting old records from visitor_log: " . $e->getMessage());
            return "清理记录失败: " . $e->getMessage();
        }
    }

    /**
     * 处理自定义模板
     * 
     * @access public
     * @param Widget_Archive $archive
     * @return void
     */
    public static function handleTemplate($archive)
    {
        if ($archive->is('page')) {
            $template = $archive->template;
            if ($template == 'visitor-stats.php' || $template == 'page-visitor-stats.php') {
                $archive->setThemeFile('visitor-stats.php');
            }
        }
    }
}
