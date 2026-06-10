document.addEventListener('DOMContentLoaded', () => {

    // Timeline chart
    const tlCanvas = document.getElementById('timelineChart');
    if (tlCanvas && typeof timelineData !== 'undefined') {
        new Chart(tlCanvas, {
            type: 'bar',
            data: {
                labels: timelineData.map(r => r.hour),
                datasets: [{
                    label: 'Logs',
                    data: timelineData.map(r => r.cnt),
                    backgroundColor: 'rgba(88,166,255,.4)',
                    borderColor: '#58a6ff',
                    borderWidth: 1,
                    borderRadius: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: '#30363d' }, ticks: { color: '#8b949e' } },
                    y: { grid: { color: '#30363d' }, ticks: { color: '#8b949e' } }
                }
            }
        });
    }

    // Severity donut chart
    const sevCanvas = document.getElementById('sevChart');
    if (sevCanvas && typeof sevData !== 'undefined') {
        const colors = ['#f85149','#f85149','#f85149','#f85149','#d29922','#58a6ff','#3fb950','#8b949e'];
        new Chart(sevCanvas, {
            type: 'doughnut',
            data: {
                labels: sevData.map(r => sevLabels[r.severity] ?? r.severity),
                datasets: [{
                    data: sevData.map(r => r.cnt),
                    backgroundColor: sevData.map(r => colors[r.severity] ?? '#8b949e'),
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right', labels: { color: '#8b949e', boxWidth: 12 } }
                }
            }
        });
    }

    // Auto-refresh dashboard toutes les 30s
    if (document.getElementById('recent-logs')) {
        setTimeout(() => location.reload(), 30000);
    }
});
