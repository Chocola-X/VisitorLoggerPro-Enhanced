<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

if (isset($_GET['view']) && $_GET['view'] === 'trend') {
    require dirname(__FILE__) . '/trend.php';
    return;
}

// 引入 Typecho 后台模板
if (!defined('__TYPECHO_ADMIN__')) {
    include 'common.php';
}
$options = Helper::options();
$security = Typecho_Widget::widget('Widget_Security');
$currentUser = Typecho_Widget::widget('Widget_User');
$panelUrl = Typecho_Common::url('extending.php?panel=VisitorLoggerPro%2Fpanel.php', $options->adminUrl);
$trendPanelUrl = Typecho_Common::url('extending.php?panel=VisitorLoggerPro%2Fpanel.php&view=trend', $options->adminUrl);

if (isset($_POST['clean_up']) || isset($_POST['delete_searched_ip'])) {
    if (!$currentUser->hasLogin() || !$currentUser->pass('administrator', true)) {
        throw new Typecho_Widget_Exception(_t('禁止访问'), 403);
    }
    $security->protect();
    if (isset($_POST['clean_up'])) {
        $days = max(0, (int) ($_POST['days'] ?? 0));
        $message = $days > 0 ? VisitorLoggerPro_Plugin::cleanUpRecordsByDays($days) : '请输入有效天数';
    } else {
        $ipToDelete = trim((string) ($_POST['ip_to_delete'] ?? ''));
        if ($ipToDelete === '') {
            $message = '请输入要删除的 IP';
        } else {
            $db = Typecho_Db::get();
            $deleted = $db->query($db->delete('table.visitor_log')->where('ip LIKE ?', '%' . $ipToDelete . '%'));
            $message = '已删除 ' . (int) $deleted . ' 条匹配记录';
        }
    }
    Typecho_Widget::widget('Widget_Notice')->set(_t($message), null, 'success');
    header('Location: ' . $panelUrl);
    exit;
}
include 'header.php';
include 'menu.php';

// 获取配置的背景URL（带默认值）
$backgroundUrl = Helper::options()->plugin('VisitorLoggerPro')->backgroundUrl ?: 'https://api.mikupara.com/api/h';
if (!filter_var($backgroundUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($backgroundUrl, PHP_URL_SCHEME), array('http', 'https'), true)) {
    $backgroundUrl = '';
}

// 获取配置的卡片背景色（带默认值）
$backgroundColour = Helper::options()->plugin('VisitorLoggerPro')->backgroundColour ?: '#ffffffc4';
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $backgroundColour)) {
    $backgroundColour = '#ffffffc4';
}
$aggregateApiUrl = $security->getIndex('/action/visitor-stats-api?do=aggregate');
$pluginAssetUrl = rtrim($options->pluginUrl, '/') . '/VisitorLoggerPro';
$pluginOptions = Helper::options()->plugin('VisitorLoggerPro');
$resourceConfig = array(
    'echartsSource' => isset($pluginOptions->echartsSource) && $pluginOptions->echartsSource === 'local' ? 'local' : 'cdn',
    'echartsCdn' => 'https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js',
    'echartsLocal' => $pluginAssetUrl . '/js/echarts.min.js',
    'flatpickrCdn' => 'https://cdn.jsdelivr.net/npm/flatpickr',
    'flatpickrLocal' => $pluginAssetUrl . '/js/flatpickr.js',
    'flatpickrCssCdn' => 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
    'flatpickrCssLocal' => $pluginAssetUrl . '/css/flatpickr.min.css'
);


$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = 10;

$db = Typecho_Db::get();
$prefix = $db->getPrefix();

$ip = isset($_POST['ipQuery']) ? $_POST['ipQuery'] : (isset($_GET['ipQuery']) ? $_GET['ipQuery'] : '');
$totalLogs = $db->fetchObject($db->select(array('COUNT(*)' => 'num'))->from($prefix . 'visitor_log')->where('ip LIKE ?', '%' . $ip . '%'))->num;
$totalPages = ceil($totalLogs / $pageSize);

$logs = VisitorLoggerPro_Plugin::getSearchVisitorLogs($page, $pageSize, $ip);

$startDate = isset($_POST['startDate']) ? $_POST['startDate'] : date('Y-m-d 00:00:00', strtotime('-6 days'));
$endDate = isset($_POST['endDate']) ? $_POST['endDate'] : date('Y-m-d 23:59:59');

$countryStats = $db->fetchAll(
    $db->select('country', array('COUNT(id)' => 'count'))->from($prefix . 'visitor_log')
        ->where('ip LIKE ?', '%' . $ip . '%')->group('country')
        ->order('count', Typecho_Db::SORT_DESC)->limit(30)
);
$routeStats = $db->fetchAll(
    $db->select('route', array('COUNT(id)' => 'count'))->from($prefix . 'visitor_log')
        ->where('ip LIKE ?', '%' . $ip . '%')->group('route')
        ->order('count', Typecho_Db::SORT_DESC)->limit(30)
);
?>

<script src="<?php echo htmlspecialchars($pluginAssetUrl . '/js/resource-loader.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
const visitorLoggerProResources = <?php echo json_encode($resourceConfig, JSON_UNESCAPED_SLASHES); ?>;
Promise.allSettled([
    VisitorLoggerProLoader.loadECharts(visitorLoggerProResources),
    VisitorLoggerProLoader.loadFlatpickr(visitorLoggerProResources)
]).then(() => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeApp);
    } else {
        initializeApp();
    }
});

function initializeApp() {
    // 确保在资源加载完成后执行初始化
    if (typeof window.startChartInitialization === 'function') {
        window.startChartInitialization();
    }
}
</script>

<script>
    // 调试函数
    const DEBUG = false; // 设置为false，禁用调试输出
    function debugLog(message, data = null) {
        if (!DEBUG) return;

        console.log(`[${new Date().toTimeString().split(' ')[0]}] ${message}`, data || '');
    }

    // 错误处理函数
    window.addEventListener('error', function(event) {
        if (DEBUG) {
            console.error(`错误: ${event.message} (${event.filename}:${event.lineno})`);
        }
    });

    // 定义全局初始化函数，供智能加载机制调用
    window.startChartInitialization = function() {
        debugLog('🟢 开始图表初始化...');

        try {
            // 检查图表容器是否存在
            const countryChartElement = document.getElementById('countryChartContent');
            const provinceChartElement = document.getElementById('provinceChartContent');
            const routeChartElement = document.getElementById('routeChartContent');

            debugLog('检查图表容器', {
                country: Boolean(countryChartElement),
                province: Boolean(provinceChartElement),
                route: Boolean(routeChartElement)
            });

            // 检查 ECharts 是否加载
            if (typeof echarts === 'undefined') {
                debugLog('❌ ECharts 仍未加载，等待重试...');
                // 延迟重试
                setTimeout(() => {
                    if (typeof echarts !== 'undefined') {
                        debugLog('✅ ECharts 延迟加载成功');
                        initializeCharts();
                    } else {
                        debugLog('❌ ECharts 最终加载失败');
                        alert('图表库加载失败，请刷新页面重试');
                    }
                }, 1000);
                return;
            } else {
                debugLog('✅ ECharts 已加载');
            }

            function initializeCharts() {
                try {
                    // 为图表容器设置明确的尺寸
                    ['countryChartContent', 'provinceChartContent', 'routeChartContent'].forEach(id => {
                        const element = document.getElementById(id);
                        if (element) {
                            element.style.width = '100%';
                            element.style.height = '220px';
                            debugLog(`设置 ${id} 尺寸为 width: 100%, height: 220px`);
                        }
                    });

                    // 强制延迟初始化以确保容器已经渲染
                    setTimeout(function() {
                        try {
                            // --- 1. 初始化 ECharts 实例 ---
                            debugLog('正在初始化 ECharts 实例...');

                            // 使用主题和适当选项初始化
                            const initOptions = {
                                renderer: 'canvas',
                                devicePixelRatio: window.devicePixelRatio
                            };

                            let countryChart, provinceChart, routeChart;

                            try {
                                countryChart = echarts.init(document.getElementById('countryChartContent'), null, initOptions);
                                debugLog('✅ 国家图表初始化成功');
                            } catch (e) {
                                debugLog('❌ 国家图表初始化失败', e.message);
                            }

                            try {
                                provinceChart = echarts.init(document.getElementById('provinceChartContent'), null, initOptions);
                                debugLog('✅ 省份图表初始化成功');
                            } catch (e) {
                                debugLog('❌ 省份图表初始化失败', e.message);
                            }

                            try {
                                routeChart = echarts.init(document.getElementById('routeChartContent'), null, initOptions);
                                debugLog('✅ 路由图表初始化成功');
                            } catch (e) {
                                debugLog('❌ 路由图表初始化失败', e.message);
                            }

                            // 显示加载中动画
                            if (countryChart) countryChart.showLoading();
                            if (provinceChart) provinceChart.showLoading();
                            if (routeChart) routeChart.showLoading();

                            // --- 2. 定义所有功能函数 ---
                            function fetchVisitData(startDate, endDate) {
                                debugLog('📊 获取数据', {
                                    startDate,
                                    endDate
                                });

                fetch(<?php echo json_encode($aggregateApiUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json'
                                        },
                                        body: JSON.stringify({
                                            startDate,
                                            endDate
                                        })
                                    })
                                    .then(response => {
                                        debugLog('📊 API响应状态', response.status);
                                        return response.json();
                                    })
                                    .then(data => {
                                        debugLog('📊 API返回数据', {
                                            countryCount: Object.keys(data.countryData || {}).length,
                                            provinceCount: Object.keys(data.provinceData || {}).length,
                                            routeCount: Object.keys(data.routeData || {}).length
                                        });

                                        if (data.error) {
                                            debugLog('❌ API错误', data.error);
                                            return;
                                        }

                                        if (countryChart) {
                                            updateChart(countryChart, '国家访问统计', 'pie', data.countryData || {});
                                            updateList('countryList', data.countryData || {});
                                        }

                                        if (provinceChart) {
                                            updateChart(provinceChart, '省份访问统计', 'pie', data.provinceData || {});
                                            updateList('provinceList', data.provinceData || {});
                                        }

                                        if (routeChart) {
                                            updateChart(routeChart, '路由访问统计', 'bar', data.routeData || {});
                                            updateList('routeList', data.routeData || {});
                                        }
                                    })
                                    .catch(error => {
                                        debugLog('❌ 数据获取错误', error.message);
                                        if (countryChart) countryChart.hideLoading();
                                        if (provinceChart) provinceChart.hideLoading();
                                        if (routeChart) routeChart.hideLoading();
                                    });
                            }

                            function updateChart(chartInstance, title, type, rawData) {
                                try {
                                    debugLog(`更新图表 ${title}`, {
                                        dataCount: Object.keys(rawData).length
                                    });

                                    // 隐藏加载动画
                                    chartInstance.hideLoading();

                                    const chartData = Object.entries(rawData).map(([name, value]) => ({
                                        name,
                                        value
                                    }));

                                    if (chartData.length === 0) {
                                        debugLog(`⚠️ ${title} 没有数据可显示`);
                                        // 显示无数据提示
                                        chartInstance.setOption({
                                            title: {
                                                text: '暂无数据',
                                                left: 'center',
                                                top: 'center',
                                                textStyle: {
                                                    color: '#999',
                                                    fontSize: 16
                                                }
                                            },
                                            series: []
                                        });
                                        return;
                                    }

                                    // 为饼图定义丰富的颜色方案
                                    const pieColors = [
                                        '#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6',
                                        '#1abc9c', '#e67e22', '#34495e', '#f1c40f', '#95a5a6',
                                        '#2ecc71', '#e91e63', '#ff9800', '#607d8b', '#8bc34a'
                                    ];

                                    const option = {
                                        backgroundColor: type === 'pie' ? {
                                            type: 'radial',
                                            x: 0.5,
                                            y: 0.5,
                                            r: 0.8,
                                            colorStops: [{
                                                offset: 0,
                                                color: 'rgba(255, 255, 255, 1)'
                                            }, {
                                                offset: 1,
                                                color: 'rgba(248, 250, 252, 0.8)'
                                            }]
                                        } : 'transparent',
                                        color: type === 'pie' ? pieColors : undefined,
                                        title: {
                                            text: title.includes('路由') ? title : '',
                                            left: 'center',
                                            top: 5,
                                            textStyle: {
                                                color: '#2c3e50',
                                                fontSize: 14,
                                                fontWeight: 'bold'
                                            }
                                        },
                                        tooltip: {
                                            trigger: type === 'pie' ? 'item' : 'axis',
                                            formatter: type === 'pie' ? '{b}: {c} ({d}%)' : '{a} <br/>{b} : {c}'
                                        },
                                        legend: {
                                            show: type === 'pie',
                                            type: 'scroll',
                                            orient: chartData.length <= 8 ? 'vertical' : 'horizontal',
                                            right: chartData.length <= 8 ? 5 : 'center',
                                            top: chartData.length <= 8 ? 20 : 'bottom',
                                            bottom: chartData.length <= 8 ? 10 : 5,
                                            left: chartData.length <= 8 ? undefined : 'center',
                                            itemWidth: 12,
                                            itemHeight: 8,
                                            textStyle: {
                                                fontSize: 10
                                            }
                                        },
                                        series: [{
                                            name: title,
                                            type: type,
                                            radius: type === 'pie' ? (chartData.length <= 6 ? ['35%', '75%'] : ['45%', '80%']) : undefined,
                                            center: type === 'pie' ? (chartData.length <= 6 ? ['50%', '50%'] : ['50%', '50%']) : undefined,
                                            data: chartData,
                                            label: type === 'pie' ? {
                                                show: true,
                                                position: chartData.length <= 5 ? 'outside' : 'inside',
                                                fontSize: chartData.length <= 5 ? 10 : 9,
                                                formatter: chartData.length <= 5 ? '{b}\n{d}%' : '{d}%',
                                                color: chartData.length <= 5 ? '#333' : '#fff'
                                            } : undefined,
                                            labelLine: type === 'pie' ? {
                                                show: chartData.length <= 5,
                                                length: 10,
                                                length2: 6
                                            } : undefined,
                                            itemStyle: {
                                                borderRadius: type === 'pie' ? 8 : [4, 4, 0, 0],
                                                borderColor: type === 'pie' ? '#fff' : undefined,
                                                borderWidth: type === 'pie' ? 2 : 0,
                                                shadowBlur: type === 'pie' ? 10 : 0,
                                                shadowColor: type === 'pie' ? 'rgba(0, 0, 0, 0.1)' : undefined
                                            },
                                            emphasis: {
                                                itemStyle: {
                                                    shadowBlur: 15,
                                                    shadowOffsetX: 0,
                                                    shadowColor: 'rgba(0, 0, 0, 0.4)',
                                                    borderWidth: type === 'pie' ? 3 : 0
                                                },
                                                scale: type === 'pie' ? 1.05 : 1
                                            }
                                        }]
                                    };

                                    if (type === 'bar') {
                                        option.grid = {
                                            left: '8%',
                                            right: '4%',
                                            bottom: '35%',
                                            top: title.includes('路由') ? '15%' : '5%',
                                            containLabel: true
                                        };
                                        option.xAxis = {
                                            type: 'category',
                                            data: chartData.map(item => item.name),
                                            axisLabel: {
                                                interval: 0,
                                                rotate: 45,
                                                fontSize: 9,
                                                formatter: function(value) {
                                                    return value.length > 15 ? value.substring(0, 15) + '...' : value;
                                                }
                                            }
                                        };
                                        option.yAxis = {
                                            type: 'value',
                                            axisLabel: {
                                                fontSize: 10
                                            }
                                        };
                                        // 为柱状图系列添加配置
                                        option.series[0].itemStyle = {
                                            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{
                                                    offset: 0,
                                                    color: '#3498db'
                                                },
                                                {
                                                    offset: 1,
                                                    color: '#2980b9'
                                                }
                                            ]),
                                            borderRadius: [4, 4, 0, 0]
                                        };
                                        option.series[0].emphasis = {
                                            itemStyle: {
                                                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{
                                                        offset: 0,
                                                        color: '#e74c3c'
                                                    },
                                                    {
                                                        offset: 1,
                                                        color: '#c0392b'
                                                    }
                                                ])
                                            }
                                        };
                                    }

                                    chartInstance.setOption(option, true);

                                    // 确保图表大小适应容器
                                    setTimeout(() => chartInstance.resize(), 100);

                                    debugLog(`✅ ${title} 图表已更新`);
                                } catch (e) {
                                    debugLog(`❌ 更新 ${title} 图表出错`, e.message);
                                }
                            }

                            function updateList(containerId, data) {
                                const container = document.getElementById(containerId);
                                if (!container) return;

                                let html = '';
                                const items = [];

                                // 计算总访问量
                                const totalVisits = Object.values(data).reduce((sum, count) => sum + count, 0);

                                // 转换数据为数组并排序
                                for (const [name, count] of Object.entries(data)) {
                                    items.push({
                                        name,
                                        count
                                    });
                                }
                                items.sort((a, b) => b.count - a.count);

                                // 创建HTML表格内容 - 不限制行数，显示所有数据
                                html = items.map(item => {
                                    const percentage = ((item.count / totalVisits) * 100).toFixed(2);
                                    return `
                                        <div class="stats-item">
                                            <span class="name">${item.name}</span>
                                            <span class="count">${item.count}</span>
                                            <span class="percentage">${percentage}%</span>
                                        </div>
                                    `;
                                }).join('');

                                container.innerHTML = html || '<div class="no-data">暂无数据</div>';
                            }

                            const dateButtons = document.querySelectorAll('.date-btn');
                            const setActiveButton = (activeBtn) => {
                                dateButtons.forEach(btn => btn.classList.remove('active'));
                                if (activeBtn) activeBtn.classList.add('active');
                                debugLog('设置活跃按钮', activeBtn ? activeBtn.id : 'none');
                            };

                            // --- 3. 初始化 Flatpickr ---
                            debugLog('初始化日期选择器');
                            const flatpickrInstance = flatpickr("#dateRange", {
                                mode: "range",
                                dateFormat: "Y-m-d",
                                onChange: function(selectedDates) {
                                    if (selectedDates.length === 2) {
                                        const start = flatpickr.formatDate(selectedDates[0], "Y-m-d 00:00:00");
                                        const end = flatpickr.formatDate(selectedDates[1], "Y-m-d 23:59:59");
                                        setActiveButton(null);
                                        fetchVisitData(start, end);
                                    }
                                }
                            });
                            debugLog('✅ 日期选择器初始化成功');

                            // --- 4. 绑定事件监听器 ---
                            debugLog('绑定事件监听器');

                            document.getElementById('todayBtn').addEventListener('click', function() {
                                debugLog('点击今天按钮');
                                const today = new Date();
                                const start = flatpickr.formatDate(today, "Y-m-d 00:00:00");
                                const end = flatpickr.formatDate(today, "Y-m-d 23:59:59");
                                flatpickrInstance.setDate([start, end], false);
                                setActiveButton(this);
                                fetchVisitData(start, end);
                            });

                            document.getElementById('last7DaysBtn').addEventListener('click', function() {
                                debugLog('点击最近7天按钮');
                                const today = new Date();
                                const last7 = new Date();
                                last7.setDate(today.getDate() - 6);
                                const start = flatpickr.formatDate(last7, "Y-m-d 00:00:00");
                                const end = flatpickr.formatDate(today, "Y-m-d 23:59:59");
                                flatpickrInstance.setDate([start, end], false);
                                setActiveButton(this);
                                fetchVisitData(start, end);
                            });

                            document.getElementById('last30DaysBtn').addEventListener('click', function() {
                                debugLog('点击最近30天按钮');
                                const today = new Date();
                                const last30 = new Date();
                                last30.setDate(today.getDate() - 29);
                                const start = flatpickr.formatDate(last30, "Y-m-d 00:00:00");
                                const end = flatpickr.formatDate(today, "Y-m-d 23:59:59");
                                flatpickrInstance.setDate([start, end], false);
                                setActiveButton(this);
                                fetchVisitData(start, end);
                            });

                            document.getElementById('allTimeBtn').addEventListener('click', function() {
                                debugLog('点击全部按钮');
                                const allTimeStart = new Date('2020-01-01');
                                const today = new Date();
                                const start = flatpickr.formatDate(allTimeStart, "Y-m-d 00:00:00");
                                const end = flatpickr.formatDate(today, "Y-m-d 23:59:59");
                                flatpickrInstance.setDate([start, end], false);
                                setActiveButton(this);
                                fetchVisitData(start, end);
                            });

                            document.querySelectorAll('.chart-container').forEach(container => {
                                container.querySelectorAll('.chart-tab').forEach(tab => {
                                    tab.addEventListener('click', () => {
                                        const view = tab.dataset.view;
                                        container.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
                                        tab.classList.add('active');

                                        const chartContent = container.querySelector('.chart-content');
                                        const listContent = container.querySelector('.list-content');

                                        chartContent.style.display = view === 'chart' ? 'block' : 'none';
                                        listContent.style.display = view === 'list' ? 'block' : 'none';

                                        debugLog('切换视图', {
                                            container: container.id,
                                            view: view
                                        });

                                        if (view === 'chart') {
                                            if (chartContent.id === 'countryChartContent' && countryChart) countryChart.resize();
                                            if (chartContent.id === 'provinceChartContent' && provinceChart) provinceChart.resize();
                                            if (chartContent.id === 'routeChartContent' && routeChart) routeChart.resize();
                                        }
                                    });
                                });
                            });
                            debugLog('✅ 事件监听器绑定完成');

                            // --- 5. 初始加载数据 ---
                            debugLog('🔄 初始化加载数据 - 点击今天按钮');
                            const todayBtn = document.getElementById('todayBtn');
                            if (todayBtn) {
                                todayBtn.click();
                            } else {
                                debugLog('❌ 找不到今天按钮');
                            }

                            // --- 6. 窗口大小调整 ---
                            window.addEventListener('resize', () => {
                                debugLog('窗口大小改变，调整图表大小');
                                if (countryChart) countryChart.resize();
                                if (provinceChart) provinceChart.resize();
                                if (routeChart) routeChart.resize();
                            });

                            debugLog('✅ 所有初始化步骤完成');

                        } catch (e) {
                            debugLog('❌ 初始化图表时发生错误', e.message);
                        }
                    }, 500); // 延迟500毫秒确保DOM已完全渲染

                } catch (e) {
                    debugLog('❌ initializeCharts函数执行出错', e.message);
                }
            }

            // 开始初始化
            initializeCharts();

        } catch (e) {
            debugLog('❌ 主逻辑执行出错', e.message);
        }

        // --- 7. 分页逻辑 (保持不变) ---
        const paginationContainer = document.getElementById('pagination');
        if (!paginationContainer) {
            debugLog('⚠️ 找不到分页容器');
        } else {
            debugLog('处理分页逻辑');
            try {
                const currentPage = <?php echo $page; ?>;
                const totalPages = <?php echo $totalPages; ?>;
                const ipQuery = <?php echo json_encode($ip, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

                debugLog('分页信息', {
                    current: currentPage,
                    total: totalPages,
                    query: ipQuery
                });

                if (totalPages > 1) {
                    const maxPagesToShow = 5;
                    let pagination = [];
                    if (totalPages <= maxPagesToShow) {
                        for (let i = 1; i <= totalPages; i++) pagination.push(i);
                    } else {
                        let start = currentPage - 2;
                        let end = currentPage + 2;
                        if (start < 1) {
                            end += 1 - start;
                            start = 1;
                        }
                        if (end > totalPages) {
                            start -= end - totalPages;
                            end = totalPages;
                        }
                        if (start > 1) pagination.push(1, '...');
                        for (let i = start; i <= end; i++) pagination.push(i);
                        if (end < totalPages) pagination.push('...', totalPages);
                    }

                    pagination.forEach(page => {
                        const li = document.createElement('li');
                        if (page === '...') {
                            li.innerHTML = `<span>...</span>`;
                        } else {
                            const a = document.createElement('a');
                            a.href = `?panel=VisitorLoggerPro%2Fpanel.php&page=${page}&ipQuery=${ipQuery}`;
                            a.textContent = page;
                            if (page === currentPage) li.classList.add('current');
                            li.appendChild(a);
                        }
                        paginationContainer.appendChild(li);
                    });
                    debugLog('✅ 分页生成成功');
                } else {
                    debugLog('无需分页 (总页数 <= 1)');
                }
            } catch (e) {
                debugLog('❌ 分页处理出错', e.message);
            }
        }
    };
</script>

<style>
    #vlp-admin {
        padding: 20px;
        min-height: 100vh;
        background-image: url(<?php echo json_encode($backgroundUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        background-repeat: no-repeat;
        background-position: center center;
        background-attachment: fixed;
        background-size: cover;
    }

    #vlp-admin .body.container {
        display: block;
        width: 100%;
        max-width: 100%;
        margin: 0 auto;
        padding: 0 20px;
        box-sizing: border-box;
    }

    .page-header {
        background: <?php echo $backgroundColour; ?>;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        border: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .page-header h2 {
        color: #2c3e50;
        margin: 0;
        font-size: 24px;
        font-weight: 600;
    }

    .nav-links {
        display: flex;
        gap: 12px;
    }

    .nav-link {
        padding: 8px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        text-decoration: none;
        color: #4a5568;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s;
        background: #f8fafcb5;
    }

    .nav-link:hover {
        background: #e2e8f0;
        color: #2c3e50;
    }

    .nav-link.active {
        background: #3498db;
        color: white;
        border-color: #3498db;
    }

    .info-panel {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        border: 1px solid #e2e8f0;
    }

    .info-header h3 {
        color: #2c3e50;
        margin: 0 0 12px 0;
        font-size: 16px;
        font-weight: 600;
    }

    .info-content {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .db-info {
        padding: 8px 12px;
        background: #f8fafcb5;
        border-radius: 6px;
        font-size: 14px;
        line-height: 1.5;
        border-left: 4px solid #3498db;
    }

    .db-info strong {
        color: #2c3e50;
    }

    .content-wrapper {
        display: grid;
        grid-template-columns: minmax(900px, 2fr) minmax(300px, 1fr);
        gap: 24px;
        align-items: start;
        min-width: 0;
        width: 100%;
    }

    .left-section {
        display: flex;
        flex-direction: column;
        gap: 24px;
        min-width: 0;
    }

    .action-forms {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        /* margin-bottom: 24px; */
    }

    .action-form {
        background: <?php echo $backgroundColour; ?>;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        border: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
    }

    .action-form label {
        display: block;
        margin-bottom: 8px;
        color: #2c3e50;
        font-weight: 600;
        font-size: 14px;
    }

    .action-form input,
    .action-form .date-btn,
    .action-form button {
        /* width: 100%; */
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.3s;
    }

    .action-form input:focus {
        border-color: #3498db;
        box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
    }

    .action-form button {
        margin-top: auto;
        border: none;
        background-color: #3498db;
        color: white;
        font-weight: 500;
        cursor: pointer;
    }

    .action-form button:hover {
        background-color: #2980b9;
    }

    .date-buttons {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        margin-top: 8px;
    }

    .date-btn {
        background-color: #f8fafcb5;
        text-align: center;
        cursor: pointer;
    }

    .date-btn.active {
        background-color: #3498db;
        color: white;
        border-color: #3498db;
    }

    .logs-section {
        background: <?php echo $backgroundColour; ?>;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        /* height: calc(100vh - 200px); */
        overflow: auto;
        min-width: 0;
        border: 1px solid #e2e8f0;
    }

    .typecho-list-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .typecho-list-table th,
    .typecho-list-table td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
        /*white-space: nowrap;*/
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .typecho-list-table th {
        font-weight: 600;
        color: #4a5568;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .typecho-list-table th:nth-child(1),
    .typecho-list-table td:nth-child(1) {
        width: 12%;
    }

    .typecho-list-table th:nth-child(2),
    .typecho-list-table td:nth-child(2) {
        width: 15%;
    }

    .typecho-list-table th:nth-child(3),
    .typecho-list-table td:nth-child(3) {
        width: 15%;
    }

    .typecho-list-table th:nth-child(4),
    .typecho-list-table td:nth-child(4) {
        width: 30%;
        max-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        cursor: help;
        position: relative;
    }

    .typecho-list-table td:nth-child(4):hover {
        background-color: #f0f8ffa3;
    }

    .typecho-list-table th:nth-child(5),
    .typecho-list-table td:nth-child(5) {
        width: 12%;
    }

    .typecho-list-table tr:hover {
        background-color: #f8fafcb5;
    }

    .typecho-list-table tr:last-child td {
        border-bottom: none;
    }

    .stats-section {
        background: <?php echo $backgroundColour; ?>;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        /* height: calc(100vh - 200px); */
        display: flex;
        flex-direction: column;
        gap: 24px;
        border: 1px solid #e2e8f0;
        min-width: 0;
        max-width: 100%;
    }

    .chart-container {
        flex: 1;
        min-height: 260px;
        background: <?php echo $backgroundColour; ?>;
        border-radius: 12px;
        padding: 8px;
        border: 1px solid #e2e8f0;
        position: relative;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        transition: all 0.3s ease;
        overflow: hidden;
        min-width: 0;
        max-width: 100%;
        box-sizing: border-box;
    }

    .chart-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, #3498db, #e74c3c, #f39c12, #27ae60);
        opacity: 0.6;
    }

    .chart-container:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    }

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        padding: 4px 0;
    }

    .chart-title {
        font-size: 13px;
        font-weight: 600;
        color: #2c3e50;
    }

    .chart-tabs {
        display: flex;
        gap: 4px;
    }

    .chart-tab {
        padding: 3px 6px;
        border: 1px solid #e2e8f0;
        border-radius: 3px;
        background: #fff;
        color: #4a5568;
        cursor: pointer;
        font-size: 11px;
        transition: all 0.3s;
    }

    .chart-tab.active {
        background: #3498db;
        color: white;
        border-color: #3498db;
    }

    .chart-content {
        height: calc(100% - 32px);
        width: 100%;
    }

    .list-content {
        display: none;
        height: calc(100% - 32px);
        overflow: auto;
    }

    .list-content.active {
        display: block;
    }

    .stats-list {
        background: #fff;
        border-radius: 8px;
        padding: 12px;
    }

    .stats-item {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 8px;
        padding: 6px 0;
        border-bottom: 1px solid #f0f0f0;
        font-size: 13px;
    }

    .stats-item .name {
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .stats-item .count {
        font-weight: bold;
        color: #3498db;
    }

    .stats-item .percentage {
        color: #7f8c8d;
    }

    .no-data {
        text-align: center;
        padding: 20px;
        color: #999;
        font-style: italic;
    }

    .stats-item:last-child {
        border-bottom: none;
    }

    .typecho-pager {
        /*margin-top: 24px;*/
        display: flex;
        justify-content: center;
        padding-bottom: 20px;
    }

    .typecho-pager ul {
        display: flex;
        gap: 8px;
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .typecho-pager li {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 4px 12px;
        transition: all 0.3s;
    }

    .typecho-pager li:hover {
        background: rgb(255, 255, 255);
    }

    .typecho-pager li.current {
        background: rgb(255, 255, 255);
        color: white;
        border-color: #3498db;
    }

    @media (max-width: 1400px) {
        .content-wrapper {
            grid-template-columns: 1fr;
        }

        .chart-container {
            min-height: 250px;
        }
    }

    @media (max-width: 768px) {
        #vlp-admin {
            padding: 8px;
        }

        #vlp-admin .body.container {
            width: 100%;
            padding: 0;
            box-sizing: border-box;
        }

        .page-header {
            align-items: flex-start;
            flex-direction: column;
            gap: 12px;
            padding: 14px;
        }

        .nav-links {
            width: 100%;
        }

        .nav-link {
            flex: 1;
            padding: 8px;
            text-align: center;
        }

        .content-wrapper {
            display: block;
        }

        .action-forms {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .action-form,
        .logs-section,
        .stats-section {
            padding: 12px;
            box-sizing: border-box;
        }

        .logs-section {
            max-width: 100%;
            overflow-x: auto;
        }

        .logs-section .typecho-list-table {
            min-width: 720px;
        }

        .stats-section {
            margin-top: 16px;
            gap: 16px;
        }
    }
</style>

<main class="main" id="vlp-admin">
    <div class="body container">
        <div class="page-header">
            <h2>访客日志</h2>
            <div class="nav-links">
                <a href="<?php echo htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link active">访客日志</a>
                <a href="<?php echo htmlspecialchars($trendPanelUrl, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">趋势分析</a>
            </div>
        </div>



        <div class="content-wrapper">
            <div class="left-section">
                <div class="action-forms">
                    <form class="action-form" method="post" action="<?php echo htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="_" value="<?php echo htmlspecialchars($security->getToken($request->getRequestUrl()), ENT_QUOTES, 'UTF-8'); ?>">
                        <label for="days">删除最早的几天记录</label>
                        <input type="number" id="days" name="days" min="0" value="3">
                        <button type="submit" name="clean_up" onclick="return confirm('此操作将删除从最早记录开始计算的指定天数内的所有记录！确定要继续吗？')">删除</button>
                    </form>

                    <form class="action-form" method="post" action="<?php echo htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        <label for="ipQuery">IP地址查询</label>
                        <input type="text" id="ipQuery" name="ipQuery" value="<?php echo htmlspecialchars($ip); ?>" placeholder="支持模糊查询">
                        <button type="submit" name="searchLogs">查询</button>
                    </form>

                    <div class="action-form">
                        <label for="dateRange">图表日期范围</label>
                        <input type="text" id="dateRange" name="dateRange" placeholder="选择日期范围">
                        <div class="date-buttons">
                            <button type="button" id="todayBtn" class="date-btn">今天</button>
                            <button type="button" id="last7DaysBtn" class="date-btn">最近7天</button>
                            <button type="button" id="last30DaysBtn" class="date-btn">最近30天</button>
                            <button type="button" id="allTimeBtn" class="date-btn">全部</button>
                        </div>
                    </div>
                </div>

                <?php if (!empty($ip)): ?>
                    <div class="action-forms" style="margin-top: -12px; margin-bottom: 12px;">
                        <form class="action-form" method="post" action="<?php echo htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('确定删除所有匹配该 IP 的日志吗？');">
                            <input type="hidden" name="_" value="<?php echo htmlspecialchars($security->getToken($request->getRequestUrl()), ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="deleteIp">删除IP日志</label>
                            <input type="hidden" name="ip_to_delete" value="<?php echo htmlspecialchars($ip); ?>">
                            <button type="submit" name="delete_searched_ip" style="background-color: #d9534f; color:white;">删除 "<?php echo htmlspecialchars($ip); ?>" 的所有记录</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="logs-section">
                    <table class="typecho-list-table">
                        <thead>
                            <tr>
                                <th>IP</th>
                                <th>访问路由</th>
                                <th>访问地点</th>
                                <th>User-Agent</th>
                                <th>时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5">暂无记录</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td><?php echo htmlspecialchars(urldecode($log['route'])); ?></td>
                                        <td><?php echo htmlspecialchars(VisitorLoggerPro_Location::format($log)); ?></td>
                                        <td title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>"><?php
                                                                                                                $userAgent = $log['user_agent'] ?? '';
                                                                                                                if (strlen($userAgent) > 50) {
                                                                                                                    echo htmlspecialchars(substr($userAgent, 0, 50) . '...');
                                                                                                                } else {
                                                                                                                    echo htmlspecialchars($userAgent);
                                                                                                                }
                                                                                                                ?></td>
                                        <td><?php echo htmlspecialchars($log['time']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="typecho-pager">
                        <ul id="pagination"></ul>
                    </div>
                </div>
            </div>

            <div class="stats-section">
                <div id="countryChartContainer" class="chart-container">
                    <div class="chart-header">
                        <div class="chart-title">国家访问统计</div>
                        <div class="chart-tabs">
                            <button class="chart-tab active" data-view="chart">图表</button>
                            <button class="chart-tab" data-view="list">列表</button>
                        </div>
                    </div>
                    <div class="chart-content" id="countryChartContent"></div>
                    <div class="list-content" id="countryListContent" style="display: none;">
                        <div class="stats-list" id="countryList"></div>
                    </div>
                </div>

                <div id="provinceChartContainer" class="chart-container">
                    <div class="chart-header">
                        <div class="chart-title">省份访问统计</div>
                        <div class="chart-tabs">
                            <button class="chart-tab active" data-view="chart">图表</button>
                            <button class="chart-tab" data-view="list">列表</button>
                        </div>
                    </div>
                    <div class="chart-content" id="provinceChartContent"></div>
                    <div class="list-content" id="provinceListContent" style="display: none;">
                        <div class="stats-list" id="provinceList"></div>
                    </div>
                </div>

                <div id="routeChartContainer" class="chart-container">
                    <div class="chart-header">
                        <div class="chart-title">路由访问统计</div>
                        <div class="chart-tabs">
                            <button class="chart-tab active" data-view="chart">图表</button>
                            <button class="chart-tab" data-view="list">列表</button>
                        </div>
                    </div>
                    <div class="chart-content" id="routeChartContent"></div>
                    <div class="list-content" id="routeListContent" style="display: none;">
                        <div class="stats-list" id="routeList"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
include 'footer.php';
?>
