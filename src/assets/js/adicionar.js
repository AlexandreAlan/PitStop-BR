document.addEventListener('DOMContentLoaded', function () {
    var campoLitros = document.getElementById('campoLitros');
    var radios = document.querySelectorAll('input[name="tipo_registro"]');

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            campoLitros.classList.toggle('d-none', radio.value === 'Manutencao' && radio.checked);
        });
    });
});
