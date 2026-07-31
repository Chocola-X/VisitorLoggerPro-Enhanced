<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(array(
    'error' => '此接口已迁移到 Typecho Action 路由，请更新插件页面。'
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
