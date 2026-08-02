<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class VisitorLoggerPro_Location
{
    public static function fromIp2Region($raw)
    {
        $parts = array_pad(explode('|', (string) $raw), 5, '');
        return self::clean(array(
            'country' => self::part($parts[0]),
            'region' => self::part($parts[2]),
            'city' => self::part($parts[3]),
            'isp' => self::part($parts[4])
        ));
    }

    public static function fromDatabase($result)
    {
        return self::clean(is_array($result) ? $result : array());
    }

    public static function fromCz88($result)
    {
        $raw = isset($result['data']['country']) ? $result['data']['country'] : '';
        $region = isset($result['province']) ? $result['province'] : '';
        $city = isset($result['city']) ? $result['city'] : '';
        $isp = isset($result['data']['isp']) ? $result['data']['isp'] : '';
        return self::parse($raw, $region, $city, $isp);
    }

    public static function parse($raw, $regionHint = '', $cityHint = '', $ispHint = '')
    {
        $raw = trim(preg_replace('/\s+/u', '', (string) $raw));
        if (strpos($raw, '|') !== false) {
            return self::fromIp2Region($raw);
        }

        $region = self::part($regionHint);
        $city = self::part($cityHint);
        $country = '';
        $chinaPattern = '/^(?:中国)?(北京市|天津市|上海市|重庆市|内蒙古自治区|广西壮族自治区|西藏自治区|宁夏回族自治区|新疆维吾尔自治区|香港特别行政区|澳门特别行政区|台湾省|[\p{Han}]{2,8}省)/u';
        $chinaRegionPattern = '/^(?:北京|天津|上海|重庆|内蒙古|广西|西藏|宁夏|新疆|香港|澳门|台湾|河北|山西|辽宁|吉林|黑龙江|江苏|浙江|安徽|福建|江西|山东|河南|湖北|湖南|广东|海南|四川|贵州|云南|陕西|甘肃|青海)(?:省|市|自治区|壮族自治区|回族自治区|维吾尔自治区|特别行政区)?$/u';
        $rawIsChina = preg_match($chinaPattern, $raw, $matches);
        if (($region !== '' && preg_match($chinaRegionPattern, $region)) || $rawIsChina) {
            $country = '中国';
            if ($region === '' && !empty($matches[1])) {
                $region = $matches[1];
            }

            $remaining = preg_replace('/^中国/u', '', $raw);
            if ($region !== '') {
                $remaining = preg_replace('/^' . preg_quote($region, '/') . '/u', '', $remaining);
                $shortRegion = preg_replace('/[省市]$/u', '', $region);
                $remaining = preg_replace('/^' . preg_quote($shortRegion, '/') . '[省市]?/u', '', $remaining);
            }
            if ($city === '' && preg_match('/^([\p{Han}]{2,12}(?:市|自治州|地区|盟))/u', $remaining, $cityMatch)) {
                $city = $cityMatch[1];
            }
            if ($city === '' && preg_match('/^(北京市|天津市|上海市|重庆市)$/u', $region)) {
                $city = $region;
            }
        } elseif (preg_match('/^中国/u', $raw)) {
            $country = '中国';
        } else {
            $countries = array(
                '阿拉伯联合酋长国', '大不列颠及北爱尔兰联合王国', '中华人民共和国',
                '美国', '日本', '韩国', '朝鲜', '俄罗斯', '英国', '法国', '德国', '加拿大',
                '澳大利亚', '新西兰', '新加坡', '印度', '印度尼西亚', '马来西亚', '泰国',
                '越南', '菲律宾', '巴西', '阿根廷', '墨西哥', '意大利', '西班牙', '荷兰',
                '瑞士', '瑞典', '挪威', '芬兰', '丹麦', '波兰', '乌克兰', '土耳其',
                '南非', '埃及', '以色列', '沙特阿拉伯', '阿联酋', '巴基斯坦', '蒙古'
            );
            foreach ($countries as $candidate) {
                if (strpos($raw, $candidate) === 0) {
                    $country = $candidate === '中华人民共和国' ? '中国' : $candidate;
                    break;
                }
            }
        }

        if ($country === '' && in_array($raw, array('本机地址', '局域网'), true)) {
            $country = $raw;
        }
        if ($country === '' && $raw !== '' && mb_strlen($raw, 'UTF-8') <= 16) {
            $country = $raw;
        }

        return self::clean(array(
            'country' => $country,
            'region' => $region,
            'city' => $city,
            'isp' => $ispHint
        ));
    }

    public static function format($location)
    {
        $location = self::clean(is_array($location) ? $location : array());
        $parts = array();
        foreach (array('country', 'region', 'city', 'isp') as $key) {
            $value = $location[$key];
            if ($value !== '' && (empty($parts) || end($parts) !== $value)) {
                $parts[] = $value;
            }
        }
        return implode(' ', $parts);
    }

    private static function clean($location)
    {
        $cleaned = array();
        foreach (array('country', 'region', 'city', 'isp') as $key) {
            $cleaned[$key] = self::part(isset($location[$key]) ? $location[$key] : '');
        }
        if (in_array($cleaned['country'], array('中华人民共和国', '中国大陆'), true)) {
            $cleaned['country'] = '中国';
        }
        if ($cleaned['country'] === '中国') {
            $aliases = array(
                '内蒙古自治区' => '内蒙古', '广西壮族自治区' => '广西',
                '西藏自治区' => '西藏', '宁夏回族自治区' => '宁夏',
                '新疆维吾尔自治区' => '新疆', '香港特别行政区' => '香港',
                '澳门特别行政区' => '澳门'
            );
            if (isset($aliases[$cleaned['region']])) {
                $cleaned['region'] = $aliases[$cleaned['region']];
            } else {
                $cleaned['region'] = preg_replace('/[省市]$/u', '', $cleaned['region']);
            }
            $cleaned['city'] = preg_replace('/市$/u', '', $cleaned['city']);
        }
        if ($cleaned['region'] === $cleaned['country']) {
            $cleaned['region'] = '';
        }
        return $cleaned;
    }

    private static function part($value)
    {
        $value = trim((string) $value);
        return $value === '0'
            || strcasecmp($value, 'null') === 0
            || strcasecmp($value, 'unknown') === 0
            ? '' : $value;
    }
}
