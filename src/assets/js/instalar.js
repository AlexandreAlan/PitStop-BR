document.addEventListener('DOMContentLoaded', function () {
    var botoes = document.querySelectorAll('.tab-btn');
    botoes.forEach(function (botao) {
        botao.addEventListener('click', function () {
            var alvo = botao.dataset.tab;
            botoes.forEach(function (b) { b.classList.toggle('ativo', b === botao); });
            document.querySelectorAll('.pane-instalar').forEach(function (pane) {
                pane.classList.toggle('ativo', pane.id === 'pane-' + alvo);
            });
        });
    });
});
