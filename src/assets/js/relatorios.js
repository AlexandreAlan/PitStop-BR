document.addEventListener('DOMContentLoaded', function () {
    var botaoPdf = document.getElementById('botaoExportarPdf');
    if (botaoPdf) {
        botaoPdf.addEventListener('click', function () {
            window.print();
        });
    }

    function paraYMD(data) {
        return data.toISOString().slice(0, 10);
    }

    document.querySelectorAll('.atalhos-periodo [data-periodo]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var hoje = new Date();
            var inicio, fim = hoje;

            if (botao.dataset.periodo === 'mes') {
                inicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
            } else if (botao.dataset.periodo === '30dias') {
                inicio = new Date(hoje);
                inicio.setDate(inicio.getDate() - 30);
            } else {
                inicio = new Date(hoje.getFullYear(), 0, 1);
            }

            var campoInicio = document.getElementById('campoDataInicio');
            var campoFim = document.getElementById('campoDataFim');
            if (campoInicio) campoInicio.value = paraYMD(inicio);
            if (campoFim) campoFim.value = paraYMD(fim);

            var formulario = document.getElementById('formFiltroVeiculo');
            if (formulario) formulario.requestSubmit();
        });
    });

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
                datasets: [{
                    label: 'Gasto (R$)', data: dados.gastoMes.valores, backgroundColor: corPrimaria,
                    borderRadius: 6, barPercentage: 0.5, categoryPercentage: 0.6, maxBarThickness: 56,
                }]
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
                datasets: [{
                    label: 'Km rodado', data: dados.kmMes.valores, backgroundColor: '#1c1f26',
                    borderRadius: 6, barPercentage: 0.5, categoryPercentage: 0.6, maxBarThickness: 56,
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    var graficoCategorias = document.getElementById('graficoCategorias');
    if (graficoCategorias) {
        var paletaCategorias = ['#ff6b35', '#0e8a8f', '#1c1f26', '#ffb35c', '#0b6d71', '#c98a12', '#8a8f98'];
        new Chart(graficoCategorias, {
            type: 'doughnut',
            data: {
                labels: dados.categorias.labels,
                datasets: [{ data: dados.categorias.valores, backgroundColor: paletaCategorias, borderWidth: 0 }]
            },
            options: {
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } } },
                cutout: '62%'
            }
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
