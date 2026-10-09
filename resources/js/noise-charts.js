import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    BarController,
    BarElement,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, BarController, BarElement, CategoryScale, Tooltip, Legend, Filler);

const PALETTE = ['#0f766e', '#7c3aed', '#c2410c', '#2563eb', '#be123c', '#4d7c0f'];

const formatters = new Map();

function formatTime(ms, timezone, withSeconds = true) {
    const key = `${timezone}|${withSeconds}`;

    if (!formatters.has(key)) {
        formatters.set(key, new Intl.DateTimeFormat(undefined, {
            timeZone: timezone,
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: withSeconds ? '2-digit' : undefined,
            timeZoneName: 'short',
        }));
    }

    return formatters.get(key).format(new Date(ms));
}

function formatCoverage(point) {
    if (point.expected_ms == null) {
        return null;
    }

    const valid = Math.round((point.valid_ms ?? 0) / 1000);
    const expected = Math.round(point.expected_ms / 1000);

    return `${valid}s measured of ${expected}s` + (point.excluded_ms ? ` (${Math.round(point.excluded_ms / 1000)}s excluded by quality policy)` : '');
}

/**
 * Shaded event windows drawn behind the series. Detected events are amber,
 * reviewer-confirmed disturbances red; open events extend to the chart edge.
 */
const eventOverlay = {
    id: 'nmEventOverlay',
    beforeDatasetsDraw(chart, args, options) {
        const events = options.events ?? [];

        if (!events.length) {
            return;
        }

        const { ctx, chartArea, scales } = chart;

        ctx.save();

        for (const event of events) {
            const start = Math.max(scales.x.getPixelForValue(event.start), chartArea.left);
            const end = Math.min(scales.x.getPixelForValue(event.end ?? scales.x.max), chartArea.right);

            if (end < chartArea.left || start > chartArea.right) {
                continue;
            }

            ctx.fillStyle = event.highlight ? 'rgba(14, 165, 233, 0.22)' : (event.confirmed ? 'rgba(220, 38, 38, 0.14)' : 'rgba(217, 119, 6, 0.14)');
            ctx.fillRect(start, chartArea.top, Math.max(end - start, 2), chartArea.bottom - chartArea.top);
        }

        ctx.restore();
    },
};

/**
 * Playback cursor synchronised with the audio element on the event page.
 */
const cursorLine = {
    id: 'nmCursor',
    afterDatasetsDraw(chart, args, options) {
        if (options.at == null) {
            return;
        }

        const { ctx, chartArea, scales } = chart;
        const x = scales.x.getPixelForValue(options.at);

        if (x < chartArea.left || x > chartArea.right) {
            return;
        }

        ctx.save();
        ctx.strokeStyle = '#0ea5e9';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(x, chartArea.top);
        ctx.lineTo(x, chartArea.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

function datasetsFor(config) {
    const datasets = [];
    const palette = config.theme?.palette ?? PALETTE;

    config.series.forEach((series, index) => {
        const color = palette[index % palette.length];

        datasets.push({
            label: `${config.metric.label} (${config.metric.unit}) — ${series.label}`,
            data: series.points.map((point) => ({ x: point.t, y: point.v, point })),
            borderColor: color,
            backgroundColor: color,
            borderWidth: 1.5,
            pointRadius: series.points.length > 300 ? 0 : 2,
            spanGaps: false,
            tension: 0,
            nmKind: 'primary',
        });

        if (config.showMaxima && series.points.some((point) => point.m != null)) {
            datasets.push({
                label: `LAFmax (dBA) bucket maximum — ${series.label}`,
                data: series.points.map((point) => ({ x: point.t, y: point.m, point })),
                borderColor: color,
                backgroundColor: color,
                borderDash: [4, 3],
                borderWidth: 1,
                pointRadius: 0,
                spanGaps: false,
                tension: 0,
                nmKind: 'maxima',
            });
        }
    });

    return datasets;
}

function mountTimeChart(canvas, config) {
    const timezone = config.timezone;
    const shortSpan = (config.range.to - config.range.from) <= 6 * 3600 * 1000;
    // Optional colour overrides (e.g. the public dashboard in dark mode); Chart.js defaults otherwise.
    const theme = config.theme ?? {};
    const axisColors = {
        grid: theme.grid ? { color: theme.grid } : {},
        ticks: theme.text ? { color: theme.text } : {},
    };

    return new Chart(canvas, {
        type: 'line',
        data: { datasets: datasetsFor(config) },
        options: {
            parsing: false,
            animation: false,
            normalized: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false, axis: 'x' },
            scales: {
                x: {
                    type: 'linear',
                    min: config.range.from,
                    max: config.range.to,
                    grid: axisColors.grid,
                    ticks: {
                        ...axisColors.ticks,
                        maxTicksLimit: 8,
                        callback: (value) => formatTime(value, timezone, shortSpan),
                    },
                },
                y: {
                    title: { display: true, text: config.metric.axis, ...axisColors.ticks },
                    grid: axisColors.grid,
                    ticks: axisColors.ticks,
                },
            },
            plugins: {
                legend: { position: 'bottom', labels: { ...axisColors.ticks, boxWidth: 12, font: { size: canvas.clientWidth < 500 ? 9 : 12 } } },
                nmEventOverlay: { events: config.events ?? [] },
                nmCursor: { at: config.cursor ?? null },
                tooltip: {
                    callbacks: {
                        title: (items) => items.length ? `${formatTime(items[0].parsed.x, timezone)} · ${config.resolution.label} bucket` : '',
                        label: (item) => {
                            const point = item.raw.point;
                            const value = item.parsed.y == null ? 'no valid data' : `${item.parsed.y.toFixed(1)}`;

                            if (item.dataset.nmKind === 'maxima') {
                                return `Max LAFmax in bucket: ${value} dBA`;
                            }

                            return `${config.metric.label}${config.resolution.seconds > 1 && config.metric.aggregation === 'energy' ? ' (energy average)' : ''}: ${value} ${config.metric.unit}`;
                        },
                        afterBody: (items) => {
                            const point = items[0]?.raw?.point;

                            if (!point) {
                                return [];
                            }

                            const lines = [];
                            const coverage = formatCoverage(point);

                            if (coverage) {
                                lines.push(coverage);
                            }

                            if (point.flags && point.flags.length) {
                                lines.push(`Quality flags: ${point.flags.join(', ')}`);
                            }

                            return lines;
                        },
                    },
                },
            },
        },
        plugins: [eventOverlay, cursorLine],
    });
}

function mountBandChart(canvas, config) {
    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels: config.bands.map((band) => `${band.center_hz} Hz`),
            datasets: [{
                label: `${config.label} (${config.weighting}-weighted, dB)`,
                data: config.bands.map((band) => band.level_db),
                backgroundColor: '#0f766e',
            }],
        },
        options: {
            animation: false,
            maintainAspectRatio: false,
            scales: { y: { title: { display: true, text: 'dB' } } },
            plugins: { legend: { position: 'bottom' } },
        },
    });
}

window.NoiseCharts = { mountTimeChart, mountBandChart };
window.dispatchEvent(new CustomEvent('noise-charts:ready'));
