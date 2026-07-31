<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class VisitorLoggerPro_Statistics
{
    public static function validateRange($startDate, $endDate, $maxDays = 3660)
    {
        if ($startDate === 'all') {
            $db = Typecho_Db::get();
            $first = $db->fetchRow($db->select(array('MIN(time)' => 'first_time'))->from('table.visitor_log'));
            $startDate = !empty($first['first_time'])
                ? date('Y-m-d 00:00:00', strtotime($first['first_time']))
                : VisitorLoggerPro_Database::siteDate('Y-m-d 00:00:00');
        }

        $startDate = (string) $startDate;
        $endDate = (string) $endDate;
        $start = DateTime::createFromFormat('!Y-m-d H:i:s', $startDate);
        $end = DateTime::createFromFormat('!Y-m-d H:i:s', $endDate);
        if (!$start || !$end || $start->format('Y-m-d H:i:s') !== $startDate || $end->format('Y-m-d H:i:s') !== $endDate) {
            throw new InvalidArgumentException('日期格式无效');
        }
        if ($start > $end) {
            throw new InvalidArgumentException('开始日期不能晚于结束日期');
        }
        if ($start->diff($end)->days > $maxDays) {
            throw new InvalidArgumentException('查询日期范围过大');
        }
        return array($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
    }

    public static function aggregate($startDate, $endDate)
    {
        list($startDate, $endDate) = self::validateRange($startDate, $endDate);
        $db = Typecho_Db::get();

        $total = $db->fetchRow(
            $db->select(array('COUNT(id)' => 'total'))
                ->from('table.visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
        );
        $countries = self::groupCounts($db, 'country', $startDate, $endDate, 30);
        $regions = self::groupCounts($db, 'region', $startDate, $endDate, 30);
        $routes = self::groupCounts($db, 'route', $startDate, $endDate, 20);
        $countryTotal = $db->fetchRow(
            $db->select(array('COUNT(DISTINCT country)' => 'total'))
                ->from('table.visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
                ->where('country IS NOT NULL AND country != ?', '')
                ->where('country != ?', 'Unknown')
        );

        return array(
            'countryData' => self::toMap($countries, 'country'),
            'provinceData' => self::toMap($regions, 'region'),
            'routeData' => self::toMap($routes, 'route', true),
            'totalVisits' => (int) ($total['total'] ?? 0),
            'totalCountries' => (int) ($countryTotal['total'] ?? 0)
        );
    }

    public static function trend($startDate, $endDate)
    {
        list($startDate, $endDate) = self::validateRange($startDate, $endDate, 3660);
        $db = Typecho_Db::get();
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $isSingleDay = $start->format('Y-m-d') === $end->format('Y-m-d');
        $bucketExpression = self::bucketExpression($db, $isSingleDay);

        $rows = $db->fetchAll(
            $db->select(
                array($bucketExpression => 'bucket'),
                array('COUNT(id)' => 'pv_count'),
                array('COUNT(DISTINCT ip)' => 'unique_ip_count'),
                array('COUNT(DISTINCT visitor_hash)' => 'unique_visitor_count')
            )
                ->from('table.visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
                ->group('bucket')
                ->order('bucket', Typecho_Db::SORT_ASC)
        );

        $sessions = self::sessionCounts($db, $startDate, $endDate, $isSingleDay);
        $rowMap = array();
        foreach ($rows as $row) {
            $bucket = $isSingleDay ? sprintf('%02d:00', (int) $row['bucket']) : $row['bucket'];
            $rowMap[$bucket] = array(
                'pv_count' => (int) $row['pv_count'],
                'unique_ip_count' => (int) $row['unique_ip_count'],
                'unique_visitor_count' => (int) $row['unique_visitor_count'],
                'session_count' => isset($sessions[$bucket]) ? $sessions[$bucket] : 0
            );
        }

        $buckets = self::makeBuckets($start, $end, $isSingleDay);
        $data = array();
        foreach ($buckets as $bucket) {
            $values = isset($rowMap[$bucket]) ? $rowMap[$bucket] : array(
                'pv_count' => 0,
                'unique_ip_count' => 0,
                'unique_visitor_count' => 0,
                'session_count' => isset($sessions[$bucket]) ? $sessions[$bucket] : 0
            );
            $values['date'] = $bucket;
            $data[] = $values;
        }

        $totals = $db->fetchRow(
            $db->select(
                array('COUNT(id)' => 'total_pv'),
                array('COUNT(DISTINCT ip)' => 'total_unique_ip'),
                array('COUNT(DISTINCT visitor_hash)' => 'total_unique_visitor')
            )
                ->from('table.visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
        );

        return array(
            'success' => true,
            'data' => $data,
            'range' => array(
                'start' => $startDate,
                'end' => $endDate,
                'days' => $start->diff($end)->days + 1,
                'is_single_day' => $isSingleDay
            ),
            'totals' => array(
                'total_pv' => (int) ($totals['total_pv'] ?? 0),
                'total_unique_ip' => (int) ($totals['total_unique_ip'] ?? 0),
                'total_unique_visitor' => (int) ($totals['total_unique_visitor'] ?? 0),
                'total_session' => array_sum($sessions)
            )
        );
    }

    private static function groupCounts($db, $column, $startDate, $endDate, $limit)
    {
        return $db->fetchAll(
            $db->select($column, array('COUNT(id)' => 'count'))
                ->from('table.visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
                ->where($column . ' IS NOT NULL AND ' . $column . ' != ?', '')
                ->where($column . ' != ?', 'Unknown')
                ->group($column)
                ->order('count', Typecho_Db::SORT_DESC)
                ->limit($limit)
        );
    }

    private static function toMap($rows, $key, $decode = false)
    {
        $result = array();
        foreach ($rows as $row) {
            $label = $decode ? urldecode($row[$key]) : $row[$key];
            $result[$label ?: '未知'] = (int) $row['count'];
        }
        return $result;
    }

    private static function bucketExpression($db, $isSingleDay)
    {
        if (VisitorLoggerPro_Database::isSQLite($db)) {
            return $isSingleDay ? "CAST(strftime('%H', time) AS INTEGER)" : 'date(time)';
        }
        return $isSingleDay ? 'HOUR(time)' : 'DATE(time)';
    }

    /**
     * A single ordered query replaces the previous 24/N session queries.
     */
    private static function sessionCounts($db, $startDate, $endDate, $isSingleDay)
    {
        $rows = $db->fetchAll(
            $db->select('visitor_hash', 'time')
                ->from('table.visitor_log')
                ->where('time >= ?', $startDate)
                ->where('time <= ?', $endDate)
                ->order('visitor_hash', Typecho_Db::SORT_ASC)
                ->order('time', Typecho_Db::SORT_ASC)
        );
        $previous = array();
        $counts = array();
        foreach ($rows as $row) {
            $hash = $row['visitor_hash'];
            $timestamp = strtotime($row['time']);
            if (!isset($previous[$hash]) || $timestamp - $previous[$hash] > 1800) {
                $bucket = $isSingleDay ? date('H:00', $timestamp) : date('Y-m-d', $timestamp);
                $counts[$bucket] = isset($counts[$bucket]) ? $counts[$bucket] + 1 : 1;
            }
            $previous[$hash] = $timestamp;
        }
        return $counts;
    }

    private static function makeBuckets($start, $end, $isSingleDay)
    {
        if ($isSingleDay) {
            $hours = array();
            for ($hour = 0; $hour < 24; $hour++) {
                $hours[] = sprintf('%02d:00', $hour);
            }
            return $hours;
        }

        $dates = array();
        $cursor = clone $start;
        $cursor->setTime(0, 0, 0);
        $last = clone $end;
        $last->setTime(0, 0, 0);
        while ($cursor <= $last) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
        return $dates;
    }
}
