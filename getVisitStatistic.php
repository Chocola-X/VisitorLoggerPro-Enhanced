<?php
// 设置最大执行时间，避免超时
set_time_limit(30);

// 彻底清理输出缓冲区，防止任何意外的输出。
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// 允许跨域请求
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control, Pragma');
header('Access-Control-Max-Age: 1728000');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    header('HTTP/1.1 200 OK');
    exit;
}

// 添加缓存控制头，确保响应不被缓存
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// API的错误处理：记录错误到日志，但不显示在输出中，避免JSON损坏。
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 确保 Typecho 环境已加载
if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', dirname(__FILE__, 4));
    require_once __TYPECHO_ROOT_DIR__ . '/config.inc.php';

    // 兼容不同版本的Typecho
    if (file_exists(__TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php')) {
        require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';
        \Typecho\Common::init();
    } else if (file_exists(__TYPECHO_ROOT_DIR__ . '/var/Common.php')) {
        require_once __TYPECHO_ROOT_DIR__ . '/var/Common.php';
        Typecho_Common::init();
    }
}

// 检查 Typecho 是否成功加载
if (!class_exists('\\Typecho\\Db') && !class_exists('Typecho_Db')) {
    error_log("Typecho not loaded correctly.");
    ob_end_clean();
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Typecho not loaded correctly'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 处理 API 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // 获取请求数据
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = file_get_contents('php://input');
            if ($input === false) {
                throw new Exception('Failed to read input data');
            }

            $request = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON data: ' . json_last_error_msg());
            }

            $startDate = $request['startDate'] ?? null;
            $endDate = $request['endDate'] ?? null;
        } else {
            // 支持GET请求，方便调试
            $startDate = $_GET['startDate'] ?? null;
            $endDate = $_GET['endDate'] ?? null;
        }

        if (!$startDate || !$endDate) {
            // 如果没有提供日期，使用默认值（最近7天）
            $endDate = date('Y-m-d 23:59:59');
            $startDate = date('Y-m-d 00:00:00', strtotime('-6 days'));
        }

        $provinces = [
            "北京",
            "上海",
            "天津",
            "重庆",
            "河北",
            "山西",
            "内蒙古",
            "辽宁",
            "吉林",
            "黑龙江",
            "江苏",
            "浙江",
            "安徽",
            "福建",
            "江西",
            "山东",
            "河南",
            "湖北",
            "湖南",
            "广东",
            "广西",
            "海南",
            "四川",
            "贵州",
            "云南",
            "西藏",
            "陕西",
            "甘肃",
            "宁夏",
            "青海",
            "新疆",
            "香港",
            "澳门",
            "台湾"
        ];
        $countries = [
            // 长名称国家（必须放前面）
            '波斯尼亚和黑塞哥维那',
            '赤道几内亚',
            '圣文森特和格林纳丁斯',
            '特立尼达和多巴哥',
            '圣多美和普林西比',
            '阿拉伯联合酋长国',
            '沙特阿拉伯',
            '巴布亚新几内亚',
            '所罗门群岛',
            '马绍尔群岛',
            '密克罗尼西亚联邦',
            '帕劳共和国',
            '瓦努阿图共和国',
            '基里巴斯共和国',
            '瑙鲁共和国',
            '图瓦卢',
            '美属萨摩亚',
            '英属维尔京群岛',
            '美属维尔京群岛',
            '北马里亚纳群岛',
            '福克兰群岛（马尔维纳斯）',
            '法属圭亚那',
            '法属波利尼西亚',
            '法属南部领地',
            '皮特凯恩群岛',
            '托克劳',
            '库克群岛',
            '纽埃',
            '新喀里多尼亚',
            '关岛',
            '百慕大',
            '开曼群岛',
            '安圭拉',
            '蒙特塞拉特',
            '荷属圣马丁',
            '法属圣马丁',
            '圣皮埃尔和密克隆',
            '阿森松岛',
            '特里斯坦-达库尼亚',

            // 主权国家（按字母拼音排序）
            '阿富汗', '阿尔巴尼亚', '阿尔及利亚', '安道尔', '安哥拉', '安提瓜和巴布达',
            '阿根廷', '亚美尼亚', '澳大利亚', '奥地利', '阿塞拜疆',
            '巴哈马', '巴林', '孟加拉国', '巴巴多斯', '白俄罗斯', '比利时', '伯利兹', '贝宁',
            '不丹', '玻利维亚', '博茨瓦纳', '巴西', '文莱', '保加利亚', '布基纳法索', '布隆迪',
            '柬埔寨', '喀麦隆', '加拿大', '佛得角', '中非', '乍得', '智利', '哥伦比亚',
            '科摩罗', '刚果（布）', '刚果（金）', '哥斯达黎加', '科特迪瓦', '克罗地亚', '古巴',
            '塞浦路斯', '捷克', '丹麦', '吉布提', '多米尼克', '多米尼加',
            '厄瓜多尔', '埃及', '萨尔瓦多', '赤道几内亚', '厄立特里亚', '爱沙尼亚', '斯威士兰',
            '埃塞俄比亚', '斐济', '芬兰', '法国',
            '加蓬', '冈比亚', '格鲁吉亚', '德国', '加纳', '希腊', '格林纳达', '危地马拉',
            '几内亚', '几内亚比绍', '圭亚那',
            '海地', '洪都拉斯', '匈牙利',
            '冰岛', '印度', '印度尼西亚', '伊朗', '伊拉克', '爱尔兰', '以色列', '意大利',
            '牙买加', '日本', '约旦',
            '哈萨克斯坦', '肯尼亚', '基里巴斯', '朝鲜', '韩国', '科威特', '吉尔吉斯斯坦',
            '老挝', '拉脱维亚', '黎巴嫩', '莱索托', '利比里亚', '利比亚', '列支敦士登', '立陶宛',
            '卢森堡',
            '马达加斯加', '马拉维', '马来西亚', '马尔代夫', '马里', '马耳他', '马绍尔群岛',
            '毛里塔尼亚', '毛里求斯', '墨西哥', '密克罗尼西亚', '摩尔多瓦', '摩纳哥', '蒙古',
            '黑山', '摩洛哥', '莫桑比克', '缅甸',
            '纳米比亚', '瑙鲁', '尼泊尔', '荷兰', '新西兰', '尼加拉瓜', '尼日尔', '尼日利亚',
            '北马其顿', '挪威',
            '阿曼',
            '巴基斯坦', '帕劳', '巴拿马', '巴布亚新几内亚', '巴拉圭', '秘鲁', '菲律宾', '波兰',
            '葡萄牙',
            '卡塔尔',
            '罗马尼亚', '俄罗斯', '卢旺达',
            '圣基茨和尼维斯', '圣卢西亚', '圣马力诺', '圣多美和普林西比', '沙特阿拉伯',
            '塞内加尔', '塞尔维亚', '塞舌尔', '塞拉利昂', '新加坡', '斯洛伐克', '斯洛文尼亚',
            '所罗门群岛', '索马里', '南非', '南苏丹', '西班牙', '斯里兰卡', '苏丹', '苏里南',
            '瑞典', '瑞士', '叙利亚',
            '塔吉克斯坦', '坦桑尼亚', '泰国', '东帝汶', '多哥', '汤加', '特立尼达和多巴哥',
            '突尼斯', '土耳其', '土库曼斯坦', '图瓦卢',
            '乌干达', '乌克兰', '阿联酋', '英国', '美国', '乌拉圭', '乌兹别克斯坦',
            '瓦努阿图', '梵蒂冈', '委内瑞拉', '越南',
            '也门',
            '赞比亚', '津巴布韦'
        ];

        // 根据Typecho版本选择正确的方式获取Db实例
        if (class_exists('\\Typecho\\Db')) {
            $db = \Typecho\Db::get();
        } else {
            $db = Typecho_Db::get();
        }
        $prefix = $db->getPrefix();

        // 确保表存在
        try {
            // 测试表是否存在
            $tableExists = $db->fetchRow($db->select()->from($prefix . 'visitor_log')->limit(1));

            if ($tableExists === false) {
                throw new Exception("访问日志表不存在");
            }
        } catch (Exception $e) {
            error_log('Error checking visitor_log table: ' . $e->getMessage());
            ob_end_clean();
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode([
                'error' => '数据表访问错误',
                'message' => $e->getMessage(),
                'debug_info' => 'Failed to access visitor_log table'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 使用缓存机制提高性能
        $siteId = md5(__TYPECHO_ROOT_DIR__);
        $cacheKey = md5($siteId . $startDate . $endDate);
        $cacheFile = sys_get_temp_dir() . '/visitor_stats_' . $cacheKey . '.json';
        $cacheExpire = 300; // 5分钟缓存

        // 检查是否有可用缓存
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheExpire)) {
            $cachedData = file_get_contents($cacheFile);
            if ($cachedData !== false) {
                ob_end_clean();
                echo $cachedData;
                exit;
            }
        }

        // 获取总访问量
        $totalVisitsResult = $db->fetchObject(
            $db->select('COUNT(id) as total')
                ->from($prefix . 'visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
        );
        $totalVisits = $totalVisitsResult->total;

        // 获取国家和地区访问数据
        $countryCountsResult = $db->fetchAll(
            $db->select('country', 'COUNT(id) as count')
                ->from($prefix . 'visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
                ->group('country')
                ->order('count', Typecho_Db::SORT_DESC)
        );

        $countryData = [];
        $provinceData = [];
        $totalCountries = 0;

        foreach ($countryCountsResult as $row) {
            $rawCountry = $row['country'] ?? '';
            $count = (int)($row['count'] ?? 0);

            if ($rawCountry === '') {
                $stdCountry = '未知';
            } elseif (strpos($rawCountry, '中国') !== false) {
                // 所有含“中国”的都归为“中国”
                $stdCountry = '中国';

                // 同时尝试提取省份
                foreach ($provinces as $province) {
                    if (strpos($rawCountry, $province) !== false) {
                        if (!isset($provinceData[$province])) {
                            $provinceData[$province] = 0;
                        }
                        $provinceData[$province] += $count;
                        break; // 匹配到第一个即停止
                    }
                }
            } else {
                // 非中国：尝试匹配标准国家名（用于归一化，如“美国加州”→“美国”）
                $stdCountry = $rawCountry; // 默认保留原值
                foreach ($countries as $country) {
                    if (strpos($rawCountry, $country) !== false) {
                        $stdCountry = $country;
                        break; // 匹配到即停止（因列表已按长优先排序）
                    }
                }
            }

            // 累计国家统计
            if (!isset($countryData[$stdCountry])) {
                $countryData[$stdCountry] = 0;
                $totalCountries++;
            }
            $countryData[$stdCountry] += $count;
        }

        // 获取路由访问数据
        $routeCountsResult = $db->fetchAll(
            $db->select("SUBSTRING_INDEX(route, '?', 1) as clean_route", 'COUNT(id) as count')
                ->from($prefix . 'visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
                ->group('clean_route')
        );

        $routeCounts = [];
        foreach ($routeCountsResult as $row) {
            $decodedRoute = urldecode($row['clean_route']);
            $routeCounts[$decodedRoute] = $row['count'];
        }

        arsort($countryData);
        arsort($provinceData);
        arsort($routeCounts);

        // 保存完整数据副本
        $allCountryData = $countryData;
        $allProvinceData = $provinceData;

        // 只保留前30个国家/地区（确保不过滤掉任何数据）
        $countryData = array_slice($countryData, 0, 30, true);

        // 只保留前30个省份（确保不过滤掉任何数据）
        $provinceData = array_slice($provinceData, 0, 30, true);

        // 只保留前20个路由
        $routeCounts = array_slice($routeCounts, 0, 20, true);

        $result = [
            'countryData' => $countryData,
            'provinceData' => $provinceData,
            'routeData' => array_filter($routeCounts, function ($count) {
                return $count > 0;
            }),
            'totalVisits' => $totalVisits,
            'totalCountries' => $totalCountries
        ];

        // 将结果缓存到临时文件
        $jsonResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($cacheFile, $jsonResult);

        // 在输出前再次清理缓冲区，并发送响应
        ob_end_clean();
        echo $jsonResult;
        exit;
    } catch (Exception $e) {
        error_log('Error in getVisitStatistic.php: ' . $e->getMessage());

        // 在输出前再次清理缓冲区，并发送响应
        ob_end_clean();
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
} else {
    // 返回错误响应
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid request method'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
