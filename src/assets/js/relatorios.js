document.addEventListener('DOMContentLoaded', function () {
    var elDados = document.getElementById('dados-relatorios');
    if (!elDados || typeof Chart === 'undefined') {
        return;
    }
    var dados = JSON.parse(elDados.textContent);
    var corPrimaria = '#ff6b35';

    var graficoGastoMes = document.getElementById('graficoGastoMes');
    if (graficoGastoMes) {
        new Chart(graficoGastoMes, {
            type: 'bar',
            data: {
                labels: dados.gastoMes.labels,
                datasets: [{ label: 'Gasto (R$)', data: dados.gastoMes.valores, backgroundColor: corPrimaria }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    var graficoKmMes = document.getElementById('graficoKmMes');
    if (graficoKmMes) {
        new Chart(graficoKmMes, {
            type: 'bar',
            data: {
                labels: dados.kmMes.labels,
                datasets: [{ label: 'Km rodado', data: dados.kmMes.valores, backgroundColor: '#1c1f26' }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    var graficoConsumo = document.getElementById('graficoConsumo');
    if (graficoConsumo) {
        new Chart(graficoConsumo, {
            type: 'line',
            data: {
                labels: dados.consumo.labels,
                datasets: [{
                    label: 'km/l', data: dados.consumo.valores,
                    borderColor: corPrimaria, backgroundColor: corPrimaria,
                    tension: 0.25, fill: false,
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: false } } }
        });
    }
});
