<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/adapter.php';
require_once dirname(__FILE__) . '/Database.php';
require_once dirname(__FILE__) . '/Statistics.php';

/**
 * Framework-routed JSON API. Raw visit data and trend data require an
 * authenticated administrator; the public endpoint only returns aggregates.
 */
class VisitorLoggerPro_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        try {
            $operation = (string) $this->request->get('do', '');
            if ($operation === 'aggregate') {
                $this->aggregate();
                return;
            }

            $this->requireAdministrator();
            if ($operation === 'trend') {
                $this->trend();
                return;
            }
            if ($operation === 'summary') {
                $this->summary();
                return;
            }

            $this->json(array('success' => false, 'error' => '未知操作'), 404);
        } catch (InvalidArgumentException $e) {
            $this->json(array('success' => false, 'error' => $e->getMessage()), 400);
        } catch (Exception $e) {
            error_log('VisitorLoggerPro API error: ' . $e->getMessage());
            $this->json(array('success' => false, 'error' => '统计服务暂时不可用'), 500);
        }
    }

    private function aggregate()
    {
        $pluginOptions = Helper::options()->plugin('VisitorLoggerPro');
        if (isset($pluginOptions->enableStats) && (string) $pluginOptions->enableStats !== '1') {
            $this->json(array('success' => false, 'error' => '访客统计功能未启用'), 403);
        }
        $input = $this->getInput();
        $startDate = isset($input['startDate']) ? $input['startDate'] : VisitorLoggerPro_Database::siteDate('Y-m-d 00:00:00', time() - 6 * 86400);
        $endDate = isset($input['endDate']) ? $input['endDate'] : VisitorLoggerPro_Database::siteDate('Y-m-d 23:59:59');
        $this->json(VisitorLoggerPro_Statistics::aggregate($startDate, $endDate));
    }

    private function trend()
    {
        $input = $this->getInput();
        $startDate = isset($input['startDate']) ? $input['startDate'] : VisitorLoggerPro_Database::siteDate('Y-m-d 00:00:00', time() - 6 * 86400);
        $endDate = isset($input['endDate']) ? $input['endDate'] : VisitorLoggerPro_Database::siteDate('Y-m-d 23:59:59');
        $this->json(VisitorLoggerPro_Statistics::trend($startDate, $endDate));
    }

    private function summary()
    {
        $db = Typecho_Db::get();
        $today = VisitorLoggerPro_Database::siteDate('Y-m-d 00:00:00');
        $tomorrow = VisitorLoggerPro_Database::siteDate('Y-m-d 00:00:00', time() + 86400);
        $yesterday = VisitorLoggerPro_Database::siteDate('Y-m-d 00:00:00', time() - 86400);
        $total = $db->fetchRow($db->select(array('COUNT(id)' => 'total'))->from('table.visitor_log'));
        $todayRow = $db->fetchRow(
            $db->select(array('COUNT(id)' => 'total'))->from('table.visitor_log')
                ->where('time >= ?', $today)->where('time < ?', $tomorrow)
        );
        $yesterdayRow = $db->fetchRow(
            $db->select(array('COUNT(id)' => 'total'))->from('table.visitor_log')
                ->where('time >= ?', $yesterday)->where('time < ?', $today)
        );
        $this->json(array(
            'total' => (int) ($total['total'] ?? 0),
            'today' => (int) ($todayRow['total'] ?? 0),
            'yesterday' => (int) ($yesterdayRow['total'] ?? 0)
        ));
    }

    private function requireAdministrator()
    {
        $user = $this->widget('Widget_User');
        if (!$user->hasLogin() || !$user->pass('administrator', true)) {
            $this->json(array('success' => false, 'error' => '无权限访问'), 403);
        }
    }

    private function getInput()
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($contentType, 'application/json') !== false) {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true);
                if (!is_array($input)) {
                    throw new InvalidArgumentException('请求数据格式无效');
                }
                return $input;
            }
        }
        return array(
            'startDate' => $this->request->get('startDate'),
            'endDate' => $this->request->get('endDate')
        );
    }

    private function json($data, $status = 200)
    {
        $this->response->setStatus($status);
        $this->response->setContentType('application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
