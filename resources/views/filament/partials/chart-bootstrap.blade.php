{{-- Alpine data factories defined before Alpine starts; Chart.js loads as a Vite module. --}}
<script>
    window.noiseChart = function (kind, config) {
        return {
            chart: null,
            init() {
                const mount = () => {
                    if (! window.NoiseCharts) {
                        window.addEventListener('noise-charts:ready', mount, { once: true });

                        return;
                    }

                    this.chart = kind === 'bands'
                        ? window.NoiseCharts.mountBandChart(this.$refs.canvas, config)
                        : window.NoiseCharts.mountTimeChart(this.$refs.canvas, config);
                };

                mount();
            },
            setCursor(ms) {
                if (! this.chart) {
                    return;
                }

                this.chart.options.plugins.nmCursor.at = ms;
                this.chart.update('none');
            },
            destroy() {
                this.chart?.destroy();
            },
        };
    };
</script>
