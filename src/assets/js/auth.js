document.addEventListener('DOMContentLoaded', function () {
    var CHAVE_EMAIL_LEMBRADO = 'pitstop_email_lembrado';

    var campoEmail = document.querySelector('input[name="email"]');
    var checkboxLembrar = document.getElementById('lembrarEmail');
    if (campoEmail && checkboxLembrar) {
        var emailSalvo = localStorage.getItem(CHAVE_EMAIL_LEMBRADO);
        if (emailSalvo && !campoEmail.value) {
            campoEmail.value = emailSalvo;
            checkboxLembrar.checked = true;
        }

        var formulario = campoEmail.closest('form');
        if (formulario) {
            formulario.addEventListener('submit', function () {
                if (checkboxLembrar.checked) {
                    localStorage.setItem(CHAVE_EMAIL_LEMBRADO, campoEmail.value.trim());
                } else {
                    localStorage.removeItem(CHAVE_EMAIL_LEMBRADO);
                }
            });
        }
    }

    document.querySelectorAll('.campo-senha-toggle').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = document.getElementById(botao.dataset.alvo);
            if (!campo) return;
            var vaiMostrar = campo.type === 'password';
            campo.type = vaiMostrar ? 'text' : 'password';
            botao.querySelector('i').className = vaiMostrar ? 'bi bi-eye-slash' : 'bi bi-eye';
            botao.setAttribute('aria-label', vaiMostrar ? 'Ocultar senha' : 'Mostrar senha');
        });
    });
});
