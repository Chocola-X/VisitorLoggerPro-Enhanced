<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$user = Typecho_Widget::widget('Widget_User');
if (!$user->hasLogin() || !$user->pass('administrator', true)) {
    http_response_code(403);
    exit;
}

$period = isset($_GET['period']) ? (int) $_GET['period'] : 7;
$period = max(1, min(365, $period));
$endDate = date('Y-m-d 23:59:59');
$startDate = date('Y-m-d 00:00:00', strtotime('-' . ($period - 1) . ' days'));

require_once dirname(__DIR__) . '/Database.php';
require_once dirname(__DIR__) . '/Statistics.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    VisitorLoggerPro_Statistics::trend($startDate, $endDate),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
