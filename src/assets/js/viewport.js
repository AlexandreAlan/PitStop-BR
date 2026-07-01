(function () {
    // pointer:fine/hover:hover sozinhos não bastam: eles voltam "verdadeiro" se o
    // aparelho tiver QUALQUER entrada de precisão disponível — inclusive uma caneta
    // (ex.: S Pen em celulares Samsung), mesmo que o uso real seja sempre por toque.
    // Foi isso que fez o layout "de PC" (sidebar/colunas largas) voltar a aparecer
    // mesmo depois da trava por pointer:fine. maxTouchPoints é a checagem confiável:
    // tela de toque sempre reporta >0, não importa o que a caneta declare.
    var ehDesktopDeVerdade =
        window.matchMedia('(pointer: fine) and (hover: hover)').matches &&
        navigator.maxTouchPoints === 0;

    if (ehDesktopDeVerdade) {
        document.documentElement.classList.add('is-desktop-real');
    }
})();
