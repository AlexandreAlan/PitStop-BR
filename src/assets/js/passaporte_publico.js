document.addEventListener('DOMContentLoaded', function () {
    // Mesmo mecanismo de "exportar PDF" já usado em Relatórios: impressão do
    // navegador sobre o CSS de @media print existente (assets/css/brand.css).
    var botaoPdf = document.getElementById('botaoImprimirPassaporte');
    if (botaoPdf) {
        botaoPdf.addEventListener('click', function () {
            window.print();
        });
    }
});
