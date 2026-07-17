document.addEventListener('DOMContentLoaded', function () {
    // Lê o CSV no navegador (File API) e manda como texto normal de
    // formulário — não é upload de arquivo de verdade (file_uploads
    // continua desabilitado no servidor, ver docker/php/php.ini), então
    // funciona só com o post_max_size padrão, sem precisar mexer nisso.
    const form = document.getElementById('formImportarCsv');
    const campoArquivo = document.getElementById('campoArquivoCsv');
    const campoConteudo = document.getElementById('campoCsvConteudo');
    if (!form || !campoArquivo || !campoConteudo) return;

    form.addEventListener('submit', function (evento) {
        const arquivo = campoArquivo.files && campoArquivo.files[0];
        if (!arquivo) return; // required no input cuida da mensagem nativa

        if (campoConteudo.value !== '') return; // já lido, deixa submeter

        evento.preventDefault();
        const leitor = new FileReader();
        leitor.onload = function () {
            campoConteudo.value = String(leitor.result || '');
            form.requestSubmit();
        };
        leitor.onerror = function () {
            alert('Não foi possível ler esse arquivo. Tente novamente.');
        };
        leitor.readAsText(arquivo, 'UTF-8');
    });
});
