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
            'city' => self::part($parts[3])
        ));
    }

    public static function fromCz88($result)
    {
        $raw = isset($result['data']['country']) ? $result['data']['country'] : '';
        $region = isset($result['province']) ? $result['province'] : '';
        $city = isset($result['city']) ? $result['city'] : '';
        return self::parse($raw, $region, $city);
    }

    public static function parse($raw, $regionHint = '', $cityHint = '')
    {
        $raw = trim(preg_replace('/\s+/u', '', (string) $raw));
        if (strpos($raw, '|') !== false) {
            return self::fromIp2Region($raw);
        }

        $region = self::part($regionHint);
        $city = self::part($cityHint);
        $country = '';
        $chinaPattern = '/^(?:中国)?(北京市|天津市|上海市|重庆市|内蒙古自治区|广西壮族自治区|西藏自治区|宁夏回族自治区|新疆维吾尔自治区|香港特别行政区|澳门特别行政区|台湾省|[\p{Han}]{2,8}省)/u';
        if ($region !== '' || preg_match($chinaPattern, $raw, $matches)) {
            $country = '中国';
            if ($region === '' && !empty($matches[1])) {
                $region = $matches[1];
            }
            if ($region !== '' && mb_substr($region, -1, 1, 'UTF-8') !== '省' && !preg_match('/(市|区)$/u', $region)) {
                $region .= '省';
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

        return self::clean(array('country' => $country, 'region' => $region, 'city' => $city));
    }

    private static function clean($location)
    {
        foreach ($location as $key => $value) {
            $location[$key] = self::part($value);
            if ($location[$key] === '') {
                $location[$key] = 'Unknown';
            }
        }
        return $location;
    }

    private static function part($value)
    {
        $value = trim((string) $value);
        return $value === '0' || strcasecmp($value, 'null') === 0 ? '' : $value;
    }
}
