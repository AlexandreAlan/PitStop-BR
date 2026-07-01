document.addEventListener('DOMContentLoaded', function () {
    registrarServiceWorker();
    interceptarFormulariosOffline();
    renderizarPendencias();
    tentarSincronizarAgora();
    window.addEventListener('online', tentarSincronizarAgora);
    verificarAvisoDeAtualizacao();
});

function registrarServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    // Quando uma versão nova do Service Worker assume o controle (skipWaiting +
    // clients.claim), a ABA JÁ ABERTA continua servida pela versão antiga até
    // recarregar — sem isso, quem já estava com o app aberto (ou com uma versão
    // travada de antes de uma correção) nunca via a correção sem fechar tudo à
    // mão. Recarrega uma única vez (guarda contra loop) assim que troca de dono.
    let recarregouPorAtualizacao = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (recarregouPorAtualizacao) return;
        recarregouPorAtualizacao = true;
        window.location.reload();
    });

    navigator.serviceWorker.register('/sw.php').then(function (registro) {
        // O navegador só rechecka o sw.php sozinho a cada 24h — força a checagem
        // a cada carregamento de página, pra uma correção no Service Worker
        // chegar pro usuário rápido em vez de ficar preso na versão antiga.
        registro.update().catch(function () {});

        registro.addEventListener('updatefound', function () {
            const novoWorker = registro.installing;
            if (!novoWorker) return;
            novoWorker.addEventListener('statechange', function () {
                if (novoWorker.state === 'activated') {
                    // Nova versão já assumiu o controle (skipWaiting/clients.claim no sw.php).
                    // Marca pra mostrar o aviso de "o que mudou" na próxima navegação.
                    localStorage.setItem('pitstop_atualizacao_pendente', '1');
                }
            });
        });
    }).catch(function () {
        // Sem service worker, o app ainda funciona online normalmente — só perde o modo offline.
    });
}

async function interceptarFormulariosOffline() {
    if (!window.PitstopOutbox) return;

    const mapaFormularios = {
        'form[action="adicionar.php"]': { tipo: 'registro', camposNumericos: ['veiculo_id', 'km_atual', 'litros', 'valor_pago'] },
        'form[action="lembretes.php"]': { tipo: 'lembrete', camposNumericos: ['veiculo_id', 'km_alvo'] },
    };

    Object.keys(mapaFormularios).forEach(function (seletor) {
        const form = document.querySelector(seletor);
        if (!form) return;
        const config = mapaFormularios[seletor];

        form.addEventListener('submit', function (evento) {
            evento.preventDefault();
            prosseguirComEnvio(form, config);
        });
    });
}

async function prosseguirComEnvio(form, config) {
    if (await conexaoRealDisponivel()) {
        // HTMLFormElement.prototype.submit() não dispara o evento 'submit' de novo
        // (é assim que a spec define) — segue pro POST normal pro PHP, comportamento
        // já testado, sem risco de loop com o listener acima.
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    enviarParaFilaOffline(form, config);
}

/**
 * navigator.onLine só diz se o rádio (wifi/dados) está ligado, não se chega
 * internet de verdade — wifi conectado num roteador sem internet, por
 * exemplo, ainda reporta "online". Confiar só nisso faz o app tentar mandar
 * o formulário direto pro servidor sem conexão real, e o navegador trava
 * numa tela de erro genérica ou no aviso de "confirmar reenvio do
 * formulário". Por isso, mesmo com navigator.onLine=true, testa uma busca
 * real e rápida antes de decidir.
 */
async function conexaoRealDisponivel() {
    if (!navigator.onLine) return false;
    try {
        const controlador = new AbortController();
        const tempoLimite = setTimeout(function () { controlador.abort(); }, 2500);
        await fetch('/manifest.json', { method: 'HEAD', cache: 'no-store', signal: controlador.signal });
        clearTimeout(tempoLimite);
        return true;
    } catch (erro) {
        return false;
    }
}

async function enviarParaFilaOffline(form, config) {
    const dados = new FormData(form);
    const payload = {};
    dados.forEach(function (valor, chave) {
        if (chave === 'csrf_token') return;
        payload[chave] = valor;
    });

    const csrfToken = dados.get('csrf_token');
    await window.PitstopOutbox.enfileirar(config.tipo, payload, csrfToken);

    mostrarToast('Sem internet — salvo no aparelho. Assim que a conexão voltar, sincroniza sozinho.');
    setTimeout(function () { window.location.href = 'index.php'; }, 900);
}

async function tentarSincronizarAgora() {
    if (!navigator.onLine || !window.PitstopOutbox) return;

    const pendentesAntes = await window.PitstopOutbox.listarPendentes();
    if (pendentesAntes.length === 0) return;

    const resultado = await window.PitstopOutbox.sincronizarFila();
    if (resultado.enviados > 0) {
        mostrarToast(resultado.enviados === 1
            ? '1 registro pendente foi sincronizado.'
            : resultado.enviados + ' registros pendentes foram sincronizados.');
    }
    renderizarPendencias();

    // Também registra o Background Sync pra continuar tentando em segundo plano
    // se ainda sobrou algo (ex.: a conexão caiu de novo no meio do envio).
    if (resultado.aindaOffline && 'serviceWorker' in navigator && 'SyncManager' in window) {
        navigator.serviceWorker.ready.then(function (reg) { return reg.sync.register('sync-outbox'); }).catch(function () {});
    }
}

async function renderizarPendencias() {
    const container = document.getElementById('pendencias-offline');
    if (!container || !window.PitstopOutbox) return;

    const pendentes = await window.PitstopOutbox.listarPendentes();
    if (pendentes.length === 0) {
        container.innerHTML = '';
        container.classList.add('d-none');
        return;
    }

    const comErro = pendentes.filter(function (p) { return p.ultimo_erro; }).length;
    container.classList.remove('d-none');
    container.innerHTML =
        '<div class="alert ' + (comErro > 0 ? 'alert-danger' : 'alert-warning') + ' py-2 px-3 d-flex align-items-center gap-2 mx-1 mb-3">' +
        '<i class="bi bi-cloud-arrow-up"></i>' +
        '<span class="small">' + pendentes.length + (pendentes.length === 1 ? ' registro pendente de sincronização' : ' registros pendentes de sincronização') +
        (comErro > 0 ? ' — ' + comErro + ' com erro' : '') + '</span>' +
        '</div>';
}

function mostrarToast(mensagem) {
    const div = document.createElement('div');
    div.className = 'toast-offline';
    div.textContent = mensagem;
    document.body.appendChild(div);
    requestAnimationFrame(function () { div.classList.add('visivel'); });
    setTimeout(function () {
        div.classList.remove('visivel');
        setTimeout(function () { div.remove(); }, 300);
    }, 4000);
}

/**
 * Aviso de atualização: aparece só nas 2 primeiras aberturas do app depois
 * de uma versão nova, em linguagem simples (o texto vem de config/versao.php).
 */
async function verificarAvisoDeAtualizacao() {
    const container = document.getElementById('aviso-atualizacao');
    if (!container || !navigator.onLine) return;

    try {
        const resp = await fetch('/api/versao.php', { credentials: 'same-origin' });
        if (!resp.ok) return;
        const dados = await resp.json();
        if (!dados.ok || !dados.changelog || !dados.changelog.length) return;

        const versaoVista = localStorage.getItem('pitstop_versao_vista');
        let contagem = parseInt(localStorage.getItem('pitstop_aviso_contagem') || '0', 10);

        if (versaoVista !== dados.versao) {
            localStorage.setItem('pitstop_versao_vista', dados.versao);
            contagem = 0;
        }

        if (contagem >= 2) return;

        const atual = dados.changelog[0];
        container.innerHTML =
            '<div class="alert alert-info py-2 px-3 mx-1 mb-3 aviso-atualizacao-card">' +
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
            '<div><i class="bi bi-stars me-1"></i><strong>Atualizamos o app</strong> <span class="text-muted small">(v' + atual.versao + ')</span>' +
            '<p class="small mb-0 mt-1">' + atual.resumo + '</p></div>' +
            '<button type="button" class="btn-close" aria-label="Fechar"></button>' +
            '</div></div>';
        container.classList.remove('d-none');

        localStorage.setItem('pitstop_aviso_contagem', String(contagem + 1));

        container.querySelector('.btn-close').addEventListener('click', function () {
            container.classList.add('d-none');
            container.innerHTML = '';
        });
    } catch (erro) {
        // sem internet ou API fora do ar: simplesmente não mostra o aviso agora.
    }
}
