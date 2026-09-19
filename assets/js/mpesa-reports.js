/**
 * M-Pesa Reports page charts.
 *
 * Chart data and translated labels are passed in by
 * Marupurupu_Reports::enqueue_scripts() via wp_localize_script() as
 * `marupurupuReportsData` (JSON-encoded by WordPress, not echoed by hand).
 */
jQuery(function () {
    'use strict';

    if (typeof Chart === 'undefined' || typeof marupurupuReportsData === 'undefined') {
        return;
    }

    var data = marupurupuReportsData;
    var i18n = data.i18n;

    var revenueEl = document.getElementById('revenueChart');
    if (revenueEl) {
        new Chart(revenueEl.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.daily.labels,
                datasets: [{
                    label: i18n.revenue,
                    data: data.daily.revenue,
                    borderColor: '#0f834d',
                    backgroundColor: 'rgba(15, 131, 77, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: i18n.transactions,
                    data: data.daily.count,
                    borderColor: '#2271b1',
                    backgroundColor: 'rgba(34, 113, 177, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: i18n.revenueKes
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: i18n.transactions
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }

    var statusEl = document.getElementById('statusChart');
    if (statusEl) {
        new Chart(statusEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: [i18n.completed, i18n.pending, i18n.failed],
                datasets: [{
                    data: data.status,
                    backgroundColor: ['#0f834d', '#dba617', '#d63638'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
});
