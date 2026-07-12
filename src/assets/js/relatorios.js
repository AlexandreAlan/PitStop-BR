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

            switch (botao.dataset.periodo) {
                case '7dias':
                    inicio = new Date(hoje);
                    inicio.setDate(inicio.getDate() - 6);
                    break;
                case 'mes':
                    inicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
                    break;
                case 'mespassado':
                    inicio = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
                    fim = new Date(hoje.getFullYear(), hoje.getMonth(), 0);
                    break;
                case '30dias':
                    inicio = new Date(hoje);
                    inicio.setDate(inicio.getDate() - 30);
                    break;
                case 'semanapassada':
                    // Semana passada = segunda a domingo anteriores a hoje.
                    var diaSemana = hoje.getDay() === 0 ? 7 : hoje.getDay(); // domingo=0 -> 7
                    fim = new Date(hoje);
                    fim.setDate(hoje.getDate() - diaSemana);
                    inicio = new Date(fim);
                    inicio.setDate(fim.getDate() - 6);
                    break;
                default: // ano
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

    function formatarMoeda(v) {
        return 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function formatarKm(v) {
        return Number(v).toLocaleString('pt-BR') + ' km';
    }

    // Recessivo por padrão (só a linha do eixo, sem grade nem borda do
    // dataset) — grid claro o bastante pra não competir com as barras/linha.
    var opcoesEixoBase = {
        grid: { color: 'rgba(19, 21, 26, 0.06)' },
        ticks: { color: '#6b7280' },
    };

    var graficoGastoMes = document.getElementById('graficoGastoMes');
    if (graficoGastoMes) {
        new Chart(graficoGastoMes, {
            type: 'bar',
            data: {
                labels: dados.gastoMes.labels,
                datasets: [{
                    label: 'Gasto', data: dados.gastoMes.valores, backgroundColor: corPrimaria,
                    borderRadius: 6, barPercentage: 0.5, categoryPercentage: 0.6, maxBarThickness: 56,
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return formatarMoeda(ctx.parsed.y); } } },
                },
                scales: {
                    y: Object.assign({ beginAtZero: true, ticks: { callback: function (v) { return formatarMoeda(v); } } }, opcoesEixoBase),
                    x: Object.assign({}, opcoesEixoBase, { grid: { display: false } }),
                },
            }
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
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return formatarKm(ctx.parsed.y); } } },
                },
                scales: {
                    y: Object.assign({ beginAtZero: true, ticks: { callback: function (v) { return formatarKm(v); } } }, opcoesEixoBase),
                    x: Object.assign({}, opcoesEixoBase, { grid: { display: false } }),
                },
            }
        });
    }

    var graficoCategorias = document.getElementById('graficoCategorias');
    if (graficoCategorias) {
        // Paleta categórica validada (8 checks: banda de luminosidade, piso de
        // chroma, separação CVD, contraste — ver skill dataviz) mapeada por
        // NOME da categoria, não por posição no array: assim a cor de
        // "Combustível" nunca muda quando o ranking dos gastos muda entre
        // filtros — só um 8º valor generico caso apareça uma categoria nova
        // fora da lista fixa abaixo.
        var corPorCategoria = {
            'Combustível': '#eb6834', // laranja — âncora da marca
            'Manutenção': '#2a78d6', // azul
            'Seguro': '#4a3aa7', // violeta
            'IPVA': '#eda100', // amarelo
            'Estacionamento': '#1baf7a', // aqua
            'Pedagio': '#e87ba4', // magenta
            'Pedágio': '#e87ba4',
            'Multa': '#e34948', // vermelho
            'Lavagem': '#008300', // verde
            'Outro': '#8a8f98', // cinza neutro — não faz parte da roda categórica
        };
        var corReserva = '#6b7280';
        var coresCategorias = dados.categorias.labels.map(function (nome) {
            return corPorCategoria[nome] || corReserva;
        });
        // Total no centro da rosca — plugin inline (afterDraw), só texto,
        // sem libs extras. Registrado só nessa instância (não globalmente),
        // pra não vazar pros outros gráficos da página.
        var totalCategorias = dados.categorias.valores.reduce(function (a, b) { return a + b; }, 0);
        var pluginTotalCentro = {
            id: 'totalCentro',
            afterDraw: function (chart) {
                var ctx = chart.ctx;
                var largura = chart.chartArea.left + (chart.chartArea.right - chart.chartArea.left) / 2;
                var altura = chart.chartArea.top + (chart.chartArea.bottom - chart.chartArea.top) / 2;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#8a8f98';
                ctx.font = '12px -apple-system, Segoe UI, Roboto, sans-serif';
                ctx.fillText('Total', largura, altura - 12);
                ctx.fillStyle = '#13151a';
                ctx.font = 'bold 17px -apple-system, Segoe UI, Roboto, sans-serif';
                ctx.fillText(formatarMoeda(totalCategorias), largura, altura + 8);
                ctx.restore();
            },
        };

        new Chart(graficoCategorias, {
            type: 'doughnut',
            data: {
                labels: dados.categorias.labels,
                datasets: [{ data: dados.categorias.valores, backgroundColor: coresCategorias, borderWidth: 2, borderColor: '#fff' }]
            },
            plugins: [pluginTotalCentro],
            options: {
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return ctx.label + ': ' + formatarMoeda(ctx.parsed) + ' (' + pct + '%)';
                            },
                        },
                    },
                },
                cutout: '62%'
            }
        });
    }

    var graficoConsumo = document.getElementById('graficoConsumo');
    if (graficoConsumo) {
        var datasetsConsumo = [{
            label: 'Seu consumo', data: dados.consumo.valores,
            borderColor: corPrimaria, backgroundColor: corPrimaria,
            tension: 0.25, fill: false, pointRadius: 4, pointHoverRadius: 6,
        }];

        // Linha de referência do consumo de fábrica (cidade/estrada) — só
        // aparece com 1 veículo filtrado com modelo vinculado (ver
        // relatorios.php). Tracejada, cinza neutro: não compete visualmente
        // com a linha real, é só contexto.
        var temReferenciaFabrica = dados.consumo.fabricaCidade !== null || dados.consumo.fabricaEstrada !== null;
        if (temReferenciaFabrica && dados.consumo.labels.length > 0) {
            if (dados.consumo.fabricaCidade !== null) {
                datasetsConsumo.push({
                    label: 'Fábrica (cidade)',
                    data: dados.consumo.labels.map(function () { return dados.consumo.fabricaCidade; }),
                    borderColor: '#8a8f98', borderDash: [6, 4], borderWidth: 1.5,
                    pointRadius: 0, fill: false, tension: 0,
                });
            }
            if (dados.consumo.fabricaEstrada !== null) {
                datasetsConsumo.push({
                    label: 'Fábrica (estrada)',
                    data: dados.consumo.labels.map(function () { return dados.consumo.fabricaEstrada; }),
                    borderColor: '#c5c8ce', borderDash: [2, 3], borderWidth: 1.5,
                    pointRadius: 0, fill: false, tension: 0,
                });
            }
        }

        new Chart(graficoConsumo, {
            type: 'line',
            data: { labels: dados.consumo.labels, datasets: datasetsConsumo },
            options: {
                plugins: {
                    legend: { display: temReferenciaFabrica, position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' km/l'; } } },
                },
                scales: {
                    y: Object.assign({ beginAtZero: false, ticks: { callback: function (v) { return v + ' km/l'; } } }, opcoesEixoBase),
                    x: Object.assign({}, opcoesEixoBase, { grid: { display: false } }),
                },
            }
        });
    }
});
