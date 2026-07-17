document.addEventListener('DOMContentLoaded', function () {
    var botaoCopiar = document.getElementById('botaoCopiarPassaporte');
    var campoLink = document.getElementById('linkPassaporte');
    if (!botaoCopiar || !campoLink) return;

    botaoCopiar.addEventListener('click', function () {
        campoLink.select();
        navigator.clipboard.writeText(campoLink.value).then(function () {
            var textoOriginal = botaoCopiar.innerHTML;
            botaoCopiar.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
            setTimeout(function () { botaoCopiar.innerHTML = textoOriginal; }, 2000);
        }).catch(function () {
            // Sem permissão de clipboard (ex.: contexto não seguro): o
            // campo já está selecionado, o usuário copia manualmente (Ctrl+C).
        });
    });
});
