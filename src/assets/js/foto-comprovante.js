/**
 * Foto de comprovante (nota fiscal/recibo) anexada a um registro. Compacta a
 * imagem no aparelho (canvas, sem lib nova) antes de guardar num campo
 * hidden como data URL — funciona igual online ou offline, porque não é
 * upload de arquivo de verdade: passa pelo MESMO formulário/fila offline
 * (idb-outbox.js) que os outros campos do registro (ver includes/functions.php,
 * salvarFotoRegistro()).
 *
 * Compartilhado entre adicionar.php e registro_editar.php — ambos têm os
 * mesmos IDs de campo.
 */
(function () {
    'use strict';

    const ALVO_BYTES = 300000; // alvo pós-compressão (limite real no servidor é maior, ~900KB)
    const DIMENSAO_MAXIMA = 1280;

    document.addEventListener('DOMContentLoaded', function () {
        const campoArquivo = document.getElementById('campoFotoComprovante');
        const campoBase64 = document.getElementById('campoFotoBase64');
        const previa = document.getElementById('previaFotoComprovante');
        const previaImg = document.getElementById('previaFotoComprovanteImg');
        const botaoRemover = document.getElementById('botaoRemoverFotoComprovante');
        const campoRemoverFoto = document.getElementById('campoRemoverFoto');
        if (!campoArquivo || !campoBase64) return;

        campoArquivo.addEventListener('change', function () {
            const arquivo = campoArquivo.files && campoArquivo.files[0];
            if (!arquivo) return;

            compactarImagem(arquivo).then(function (dataUrl) {
                campoBase64.value = dataUrl;
                if (campoRemoverFoto) campoRemoverFoto.value = '0';
                mostrarPrevia(dataUrl);
            }).catch(function () {
                mostrarToastFoto('Não foi possível processar essa imagem. Tente outra foto.');
                campoArquivo.value = '';
            });
        });

        if (botaoRemover) {
            botaoRemover.addEventListener('click', function () {
                campoBase64.value = '';
                campoArquivo.value = '';
                if (campoRemoverFoto) campoRemoverFoto.value = '1';
                previa.classList.add('d-none');
                previaImg.src = '';
            });
        }

        function mostrarPrevia(dataUrl) {
            previaImg.src = dataUrl;
            previa.classList.remove('d-none');
        }
    });

    function compactarImagem(arquivo) {
        return new Promise(function (resolve, reject) {
            const leitor = new FileReader();
            leitor.onerror = reject;
            leitor.onload = function () {
                const img = new Image();
                img.onerror = reject;
                img.onload = function () {
                    try {
                        resolve(reduzirAteAlvo(img));
                    } catch (erro) {
                        reject(erro);
                    }
                };
                img.src = leitor.result;
            };
            leitor.readAsDataURL(arquivo);
        });
    }

    function reduzirAteAlvo(img) {
        let dimensaoMaxima = DIMENSAO_MAXIMA;
        const qualidades = [0.8, 0.6, 0.45, 0.3];

        for (let tentativa = 0; tentativa < 2; tentativa++) {
            for (const qualidade of qualidades) {
                const dataUrl = desenharECodificar(img, dimensaoMaxima, qualidade);
                if (tamanhoBase64EmBytes(dataUrl) <= ALVO_BYTES) {
                    return dataUrl;
                }
            }
            dimensaoMaxima = Math.round(dimensaoMaxima * 0.7); // ainda grande demais: reduz a resolução e tenta de novo
        }

        // Última tentativa, o que sair é o que sai — melhor entregar uma
        // imagem um pouco acima do alvo do que travar o registro por causa
        // da foto (o limite real de segurança no servidor é bem folgado).
        return desenharECodificar(img, dimensaoMaxima, 0.3);
    }

    function desenharECodificar(img, dimensaoMaxima, qualidade) {
        const escala = Math.min(1, dimensaoMaxima / Math.max(img.width, img.height));
        const largura = Math.max(1, Math.round(img.width * escala));
        const altura = Math.max(1, Math.round(img.height * escala));

        const canvas = document.createElement('canvas');
        canvas.width = largura;
        canvas.height = altura;
        canvas.getContext('2d').drawImage(img, 0, 0, largura, altura);

        return canvas.toDataURL('image/jpeg', qualidade);
    }

    function tamanhoBase64EmBytes(dataUrl) {
        const virgula = dataUrl.indexOf(',');
        const base64 = virgula >= 0 ? dataUrl.slice(virgula + 1) : dataUrl;
        return Math.round(base64.length * 0.75);
    }

    function mostrarToastFoto(mensagem) {
        if (typeof window.mostrarToast === 'function') {
            window.mostrarToast(mensagem);
        } else {
            alert(mensagem);
        }
    }
})();
