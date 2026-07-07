/**
 * Ativa/desativa notificações push dos lembretes (Minha Conta). O botão
 * troca de texto/estado conforme já existe (ou não) uma inscrição ativa no
 * navegador atual — checado direto no PushManager, sem precisar perguntar
 * pro servidor.
 */
document.addEventListener('DOMContentLoaded', function () {
    var botao = document.getElementById('btnPushToggle');
    if (!botao || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        if (botao) {
            botao.disabled = true;
            botao.textContent = 'Notificações push não suportadas neste navegador';
        }
        return;
    }

    var vapidPublicKey = botao.getAttribute('data-vapid');
    var csrfToken = botao.getAttribute('data-csrf');

    function base64UrlParaUint8Array(base64Url) {
        var padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
        var base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
        var bruto = window.atob(base64);
        var saida = new Uint8Array(bruto.length);
        for (var i = 0; i < bruto.length; i++) {
            saida[i] = bruto.charCodeAt(i);
        }
        return saida;
    }

    function atualizarBotao(inscrito) {
        botao.textContent = inscrito ? 'Desativar notificações' : 'Ativar notificações de lembretes';
        botao.classList.toggle('btn-outline-danger', inscrito);
        botao.classList.toggle('btn-primary', !inscrito);
    }

    function estadoAtual() {
        return navigator.serviceWorker.ready.then(function (registro) {
            return registro.pushManager.getSubscription();
        }).then(function (inscricao) {
            atualizarBotao(!!inscricao);
            return inscricao;
        });
    }

    async function ativar() {
        if (Notification.permission === 'denied') {
            alert('As notificações estão bloqueadas nas configurações do navegador/app. Libere pra esse site e tente de novo.');
            return;
        }
        var permissao = await Notification.requestPermission();
        if (permissao !== 'granted') {
            return;
        }

        var registro = await navigator.serviceWorker.ready;
        var inscricao = await registro.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlParaUint8Array(vapidPublicKey),
        });

        await fetch('api/push_inscrever.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrfToken, inscricao: inscricao.toJSON() }),
        });

        atualizarBotao(true);
    }

    async function desativar() {
        var registro = await navigator.serviceWorker.ready;
        var inscricao = await registro.pushManager.getSubscription();
        if (!inscricao) {
            atualizarBotao(false);
            return;
        }

        var endpoint = inscricao.endpoint;
        await inscricao.unsubscribe();
        await fetch('api/push_desinscrever.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrfToken, endpoint: endpoint }),
        });

        atualizarBotao(false);
    }

    botao.addEventListener('click', function () {
        botao.disabled = true;
        var acao = botao.classList.contains('btn-outline-danger') ? desativar() : ativar();
        acao.catch(function (erro) {
            console.error('[push] falha:', erro);
            alert('Não deu pra ativar/desativar as notificações agora. Tente de novo em instantes.');
        }).finally(function () {
            botao.disabled = false;
        });
    });

    estadoAtual();
});
