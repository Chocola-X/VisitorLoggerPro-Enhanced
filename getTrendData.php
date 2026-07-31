<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(array(
    'success' => false,
    'error' => '此接口已迁移到受保护的 Typecho Action 路由。'
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
