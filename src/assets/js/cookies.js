document.addEventListener('DOMContentLoaded', function () {
    var aviso = document.getElementById('aviso-cookies');
    var botao = document.getElementById('botaoAceitarCookies');
    if (!aviso || !botao) return;

    var CHAVE = 'pitstop_cookies_aceitos';

    if (!localStorage.getItem(CHAVE)) {
        aviso.classList.remove('d-none');
    }

    botao.addEventListener('click', function () {
        localStorage.setItem(CHAVE, '1');
        aviso.classList.add('d-none');
    });
});
