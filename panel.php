<?php
require_once 'common.php';
require_once 'header.php';
require_once 'menu.php';

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

// 获取所有记录用于统计
$allLogsForStats = $db->fetchAll($db->select('country, route')
    ->from($prefix . 'visitor_log')
    ->where('ip LIKE ?', '%' . $ip . '%'));

// 在PHP中进行统计
$countryStats = [];
$routeStats = [];

foreach ($allLogsForStats as $log) {
    // 统计国家访问
    $country = $log['country'];
    if (!isset($countryStats[$country])) {
        $countryStats[$country] = ['country' => $country, 'count' => 0];
    }
    $countryStats[$country]['count']++;

    // 统计路由访问
    $route = $log['route'];
    if (!isset($routeStats[$route])) {
        $routeStats[$route] = ['route' => $route, 'count' => 0];
    }
    $routeStats[$route]['count']++;
}

// 按count降序排序
uasort($countryStats, function ($a, $b) {
    return $b['count'] - $a['count'];
});

uasort($routeStats, function ($a, $b) {
    return $b['count'] - $a['count'];
});

$countryStats = array_values($countryStats);
$routeStats = array_values($routeStats);
?>

<script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

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

    document.addEventListener('DOMContentLoaded', function() {
        debugLog('🟢 DOM加载完成，开始初始化...');

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
                debugLog('❌ ECharts 未加载');
                // 加载 ECharts
                const script = document.createElement('script');
                script.src = "https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js";
                document.head.appendChild(script);
                script.onload = function() {
                    debugLog('✅ ECharts 已手动加载成功');
                    initializeCharts();
                };
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
                            element.style.height = '300px';
                            debugLog(`设置 ${id} 尺寸为 width: 100%, height: 300px`);
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

                                fetch('../usr/plugins/VisitorLoggerPro/getVisitStatistic.php', {
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

                                    const option = {
                                        title: {
                                            text: title,
                                            left: 'center',
                                            top: 0,
                                            textStyle: {
                                                color: '#333',
                                                fontSize: 16
                                            }
                                        },
                                        tooltip: {
                                            trigger: type === 'pie' ? 'item' : 'axis',
                                            formatter: '{a} <br/>{b} : {c} ({d}%)'
                                        },
                                        legend: {
                                            show: type === 'pie',
                                            type: 'scroll',
                                            orient: 'vertical',
                                            right: 10,
                                            top: 20,
                                            bottom: 20
                                        },
                                        series: [{
                                            name: title,
                                            type: type,
                                            radius: type === 'pie' ? ['40%', '70%'] : undefined,
                                            center: type === 'pie' ? ['40%', '50%'] : undefined,
                                            data: chartData,
                                            itemStyle: {
                                                borderRadius: type === 'pie' ? 10 : [4, 4, 0, 0]
                                            },
                                            emphasis: {
                                                itemStyle: {
                                                    shadowBlur: 10,
                                                    shadowOffsetX: 0,
                                                    shadowColor: 'rgba(0, 0, 0, 0.5)'
                                                }
                                            }
                                        }]
                                    };

                                    if (type === 'bar') {
                                        option.grid = {
                                            left: '3%',
                                            right: '4%',
                                            bottom: '15%',
                                            containLabel: true
                                        };
                                        option.xAxis = {
                                            type: 'category',
                                            data: chartData.map(item => item.name),
                                            axisLabel: {
                                                interval: 0,
                                                rotate: 45,
                                                formatter: function(value) {
                                                    return value.length > 15 ? value.substring(0, 15) + '...' : value;
                                                }
                                            }
                                        };
                                        option.yAxis = {
                                            type: 'value'
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
                            debugLog('🔄 初始化加载数据 - 点击最近7天按钮');
                            const last7DaysBtn = document.getElementById('last7DaysBtn');
                            if (last7DaysBtn) {
                                last7DaysBtn.click();
                            } else {
                                debugLog('❌ 找不到最近7天按钮');
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
                const ipQuery = '<?php echo $ip; ?>';

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
    });
</script>

<style>
    .main {
        padding: 20px;
        background-color: #f5f7fa;
        min-height: 100vh;
    }

    .body.container {
        max-width: 100%;
        margin: 0 auto;
        padding: 0 20px;
    }

    .page-header {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        border: 1px solid #e2e8f0;
    }

    .page-header h2 {
        color: #2c3e50;
        margin: 0;
        font-size: 24px;
        font-weight: 600;
    }

    .content-wrapper {
        display: grid;
        grid-template-columns: minmax(800px, 1fr) minmax(400px, 1fr);
        gap: 24px;
        align-items: start;
    }

    .left-section {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .action-forms {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 24px;
    }

    .action-form {
        background: #fff;
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
        background-color: #f8fafc;
        text-align: center;
        cursor: pointer;
    }

    .date-btn.active {
        background-color: #3498db;
        color: white;
        border-color: #3498db;
    }

    .logs-section {
        background: #fff;
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
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .typecho-list-table th {
        background-color: #f8fafc;
        font-weight: 600;
        color: #4a5568;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .typecho-list-table th:nth-child(1),
    .typecho-list-table td:nth-child(1) {
        width: 15%;
    }

    .typecho-list-table th:nth-child(2),
    .typecho-list-table td:nth-child(2) {
        width: 45%;
    }

    .typecho-list-table th:nth-child(3),
    .typecho-list-table td:nth-child(3) {
        width: 20%;
    }

    .typecho-list-table th:nth-child(4),
    .typecho-list-table td:nth-child(4) {
        width: 20%;
    }

    .typecho-list-table tr:hover {
        background-color: #f8fafc;
    }

    .typecho-list-table tr:last-child td {
        border-bottom: none;
    }

    .stats-section {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        /* height: calc(100vh - 200px); */
        display: flex;
        flex-direction: column;
        gap: 24px;
        border: 1px solid #e2e8f0;
    }

    .chart-container {
        flex: 1;
        min-height: 300px;
        background: #fff;
        border-radius: 8px;
        padding: 16px;
        border: 1px solid #e2e8f0;
        position: relative;
    }

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .chart-title {
        font-size: 14px;
        font-weight: 600;
        color: #2c3e50;
    }

    .chart-tabs {
        display: flex;
        gap: 8px;
    }

    .chart-tab {
        padding: 4px 8px;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        background: #fff;
        color: #4a5568;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.3s;
    }

    .chart-tab.active {
        background: #3498db;
        color: white;
        border-color: #3498db;
    }

    .chart-content {
        height: calc(100% - 40px);
        width: 100%;
    }

    .list-content {
        display: none;
        height: calc(100% - 40px);
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
        margin-top: 24px;
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

    @media (max-width: 1600px) {
        .content-wrapper {
            grid-template-columns: 1fr;
        }

        .chart-container {
            min-height: 300px;
        }
    }
</style>

<div class="main">
    <div class="body container">
        <div class="page-header">
            <h2>访客日志</h2>
        </div>

        <div class="content-wrapper">
            <div class="left-section">
                <div class="action-forms">
                    <form class="action-form" method="post" action="?panel=VisitorLoggerPro%2Fpanel.php&page=<?php echo $page; ?>">
                        <label for="days">删除最早的几天记录</label>
                        <input type="number" id="days" name="days" min="0" value="3">
                        <button type="submit" name="clean_up" onclick="return confirm('此操作将删除从最早记录开始计算的指定天数内的所有记录！确定要继续吗？')">删除</button>
                    </form>

                    <form class="action-form" method="post" action="?panel=VisitorLoggerPro%2Fpanel.php&page=<?php echo $page; ?>">
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
                        <form class="action-form" method="post" action="?panel=VisitorLoggerPro/panel.php" onsubmit="return confirm('警告：此操作将删除所有与查询IP" <?php echo htmlspecialchars($ip); ?>"匹配的日志，且不可恢复。您确定要继续吗？');">
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
                                <th>时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="4">暂无记录</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td><?php echo htmlspecialchars(urldecode($log['route'])); ?></td>
                                        <td><?php echo htmlspecialchars($log['country']); ?></td>
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
</div>

<?php
include 'footer.php';

if (isset($_POST['clean_up'])) {
    $days = intval($_POST['days']);
    if ($days > 0) {
        $result = VisitorLoggerPro_Plugin::cleanUpRecordsByDays($days);
        echo "<script>alert('" . $result . "'); window.location.href = '?panel=VisitorLoggerPro/panel.php';</script>";
    } else {
        echo "<script>alert('请输入有效天数');</script>";
    }
    exit;
}

if (isset($_POST['delete_searched_ip'])) {
    $ipToDelete = $_POST['ip_to_delete'];
    if (!empty($ipToDelete)) {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $db->query($db->delete($prefix . 'visitor_log')->where('ip LIKE ?', '%' . $ipToDelete . '%'));

        echo "<script>alert('已成功删除IP" . htmlspecialchars($ipToDelete) . "的所有匹配记录。'); window.location.href = '?panel=VisitorLoggerPro/panel.php';</script>";
        exit;
    }
}
?>