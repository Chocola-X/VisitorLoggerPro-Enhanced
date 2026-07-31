(function (window, document) {
    'use strict';

    var loadedScripts = {};
    var loadedStyles = {};

    function loadScript(src, timeout) {
        if (loadedScripts[src]) {
            return loadedScripts[src];
        }

        loadedScripts[src] = new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[src="' + src + '"]');
            if (existing && existing.dataset.vlpLoaded === '1') {
                resolve(src);
                return;
            }

            var script = existing || document.createElement('script');
            var timer = window.setTimeout(function () {
                reject(new Error('Timed out loading ' + src));
            }, timeout || 3000);

            script.addEventListener('load', function () {
                window.clearTimeout(timer);
                script.dataset.vlpLoaded = '1';
                resolve(src);
            }, {once: true});
            script.addEventListener('error', function () {
                window.clearTimeout(timer);
                reject(new Error('Failed to load ' + src));
            }, {once: true});

            if (!existing) {
                script.src = src;
                document.head.appendChild(script);
            }
        });

        return loadedScripts[src];
    }

    function loadStyle(href) {
        if (loadedStyles[href] || document.querySelector('link[href="' + href + '"]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
        loadedStyles[href] = true;
    }

    function loadECharts(config) {
        if (window.echarts) {
            return Promise.resolve('existing');
        }
        if (config.echartsSource === 'local') {
            return loadScript(config.echartsLocal, 10000).then(function () { return 'local'; });
        }
        return loadScript(config.echartsCdn, 3000)
            .then(function () { return 'cdn'; })
            .catch(function () {
                return loadScript(config.echartsLocal, 10000).then(function () { return 'local'; });
            });
    }

    function loadFlatpickr(config) {
        if (window.flatpickr) {
            return Promise.resolve('existing');
        }
        return loadScript(config.flatpickrCdn, 3000)
            .then(function () {
                loadStyle(config.flatpickrCssCdn);
                return 'cdn';
            })
            .catch(function () {
                loadStyle(config.flatpickrCssLocal);
                return loadScript(config.flatpickrLocal, 10000).then(function () { return 'local'; });
            });
    }

    window.VisitorLoggerProLoader = {
        loadECharts: loadECharts,
        loadFlatpickr: loadFlatpickr
    };
})(window, document);
