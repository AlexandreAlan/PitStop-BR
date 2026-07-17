<?php
declare(strict_types=1);
require_once __DIR__ . '/config/versao.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
$versaoJs = json_encode(APP_VERSION);
?>
/**
 * Service Worker do PitStop BR — é o que faz o app abrir e mostrar os
 * últimos dados mesmo sem internet. O nome do cache muda a cada versão
 * (ver config/versao.php), então uma atualização de verdade limpa o cache
 * antigo sozinha; não tem passo manual de "invalidar cache".
 */
importScripts('/assets/js/idb-outbox.js');

const APP_VERSION = <?= $versaoJs ?>;
const CACHE_NAME = 'pitstop-' + APP_VERSION;

// Assets estáticos: sempre seguros de pré-cachear (não dependem de sessão).
const PRECACHE_URLS = [
    '/manifest.json',
    '/assets/css/brand.css',
    '/assets/js/index.js',
    '/assets/js/adicionar.js',
    '/assets/js/lembretes.js',
    '/assets/js/veiculos.js',
    '/assets/js/relatorios.js',
    '/assets/js/combustivel.js',
    '/assets/js/animacoes.js',
    '/assets/js/offline.js',
    '/assets/js/idb-outbox.js',
    '/assets/js/cookies.js',
    '/assets/js/passaporte.js',
    '/assets/js/veiculo_compartilhar.js',
    '/assets/js/foto-comprovante.js',
    '/assets/js/importar.js',
    '/assets/js/postos.js',
    '/assets/img/icon-192.png',
    '/assets/img/icon-512.png',
    '/assets/img/logo-mark.svg',
];

// Páginas autenticadas: NÃO entram no cache.addAll acima de propósito. O
// Service Worker registra em toda página, inclusive login.php ANTES do
// usuário logar — se essas URLs fossem cacheadas incondicionalmente, o
// install pré-cachearia a resposta redirecionada (login.php, HTTP 200 após
// seguir o redirect) com a chave "/index.php", e o modo offline mostraria a
// tela de login pra sempre (bug corrigido na v1.6.4). Em vez disso, cada uma
// é buscada individualmente com a sessão atual: se vier autenticada de
// verdade (sem redirect pra login), entra no cache; senão, é ignorada sem
// derrubar o install inteiro. Isso faz uma atualização de versão (que apaga
// o cache antigo) já deixar o modo offline funcionando de novo na hora,
// contanto que o usuário já esteja logado — sem precisar visitar página por
// página com internet antes de poder confiar no offline.
const PAGINAS_AUTENTICADAS = [
    '/index.php',
    '/adicionar.php',
    '/relatorios.php',
    '/veiculos.php',
    '/lembretes.php',
    '/conta.php',
    '/combustivel.php',
];

function recachearPaginasAutenticadas() {
    return caches.open(CACHE_NAME).then(function (cache) {
        return Promise.all(PAGINAS_AUTENTICADAS.map(function (url) {
            return fetch(url, { credentials: 'same-origin' }).then(function (resp) {
                const eRedirectDeLogin = resp.redirected && new URL(resp.url).pathname.endsWith('/login.php');
                if (resp.ok && !eRedirectDeLogin) {
                    return cache.put(url, resp);
                }
            }).catch(function () {
                // Sem rede agora: sem problema, essa página fica pra ser cacheada na
                // próxima visita online (handler de "navigate" abaixo já faz isso sozinho).
            });
        }));
    });
}

self.addEventListener('install', function (event) {
    // Instalação inicial: se ainda não tem sessão (ex.: SW registrando na
    // própria tela de login, ANTES do primeiro login), essas buscas vêm
    // redirecionadas e são ignoradas — nesse caso é a mensagem abaixo (disparada
    // pela página logo após o login) que efetivamente preenche o cache.
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function (cache) { return cache.addAll(PRECACHE_URLS); })
            .then(recachearPaginasAutenticadas)
            .then(function () { return self.skipWaiting(); })
    );
});

// Disparado pela página (offline.js) logo depois de confirmar que o usuário
// está logado. Cobre o caso do install ter rodado ANTES do primeiro login
// (app instalado do zero): sem essa segunda chance, o cache de páginas
// autenticadas ficava vazio até o usuário visitar cada tela manualmente.
self.addEventListener('message', function (event) {
    if (event.data && event.data.tipo === 'recachear-paginas') {
        event.waitUntil(recachearPaginasAutenticadas());
    }
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (nomes) {
                return Promise.all(
                    nomes.filter(function (nome) { return nome.startsWith('pitstop-') && nome !== CACHE_NAME; })
                        .map(function (nome) { return caches.delete(nome); })
                );
            })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    const req = event.request;

    // Peculiaridade do Chromium: requisições especulativas de recursos cross-origin
    // (CDN) às vezes chegam com cache "only-if-cached" fora do modo "same-origin" —
    // um fetch(req) direto nesse combo lança TypeError e derruba a página inteira
    // (perde CSS/JS do Bootstrap). Deixa essas passarem direto, sem o SW mexer.
    if (req.cache === 'only-if-cached' && req.mode !== 'same-origin') {
        return;
    }

    // POST/PUT/DELETE (formulários e APIs de escrita): sempre direto pra rede.
    // A fila offline é responsabilidade do offline.js, não do cache do SW.
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);

    // Navegação entre páginas: tenta a rede primeiro (dados sempre atuais
    // quando há sinal); se falhar, cai pro último snapshot salvo — é isso
    // que evita a tela de erro do navegador quando fica sem internet.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then(function (resp) {
                    // Sessão expirada/ausente: o servidor responde com um redirect pra
                    // login.php que o fetch já segue (vira HTTP 200 normal). Guardar essa
                    // tela de login sob a chave da página original é o bug que fazia o
                    // modo offline mostrar login em vez dos dados reais — nunca cacheia.
                    const eRedirectDeLogin = resp.redirected && new URL(resp.url).pathname.endsWith('/login.php');
                    if (!eRedirectDeLogin) {
                        const copia = resp.clone();
                        caches.open(CACHE_NAME).then(function (cache) { cache.put(req, copia); });
                    }
                    return resp;
                })
                .catch(function () {
                    return caches.match(req).then(function (cached) {
                        if (cached) return cached;
                        return caches.match('/index.php').then(function (shell) {
                            if (shell) return shell;
                            // Nem essa página nem o painel principal foram salvos ainda
                            // (ex.: logo após atualizar a versão, sem sessão pra
                            // pré-cachear). Sem isso, o navegador mostra a tela de erro
                            // genérica dele — essa aqui pelo menos explica o motivo.
                            return new Response(
                                '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">' +
                                '<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
                                '<title>Sem conexão — PitStop BR</title></head>' +
                                '<body style="font-family:sans-serif;background:#1c1f26;color:#fff;' +
                                'display:flex;align-items:center;justify-content:center;min-height:100vh;' +
                                'margin:0;text-align:center;padding:2rem;">' +
                                '<div><p style="font-size:1.1rem;">Sem conexão, e essa página ainda não ' +
                                'foi salva no aparelho.</p><p style="opacity:.7;font-size:.9rem;">Abra ela ' +
                                'uma vez com internet pra poder usar offline depois.</p></div></body></html>',
                                { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                            );
                        });
                    });
                })
        );
        return;
    }

    // Mesma origem (CSS/JS/ícones): cache primeiro, atualiza em segundo plano.
    if (url.origin === self.location.origin) {
        event.respondWith(
            caches.match(req).then(function (cached) {
                const buscaRede = fetch(req).then(function (resp) {
                    if (resp && resp.ok) {
                        const copia = resp.clone();
                        caches.open(CACHE_NAME).then(function (cache) { cache.put(req, copia); });
                    }
                    return resp;
                }).catch(function () { return cached; });
                return cached || buscaRede;
            })
        );
        return;
    }

    // CDN externa (Bootstrap): stale-while-revalidate.
    event.respondWith(
        caches.match(req).then(function (cached) {
            const buscaRede = fetch(req).then(function (resp) {
                caches.open(CACHE_NAME).then(function (cache) { cache.put(req, resp.clone()); });
                return resp;
            }).catch(function () { return cached; });
            return cached || buscaRede;
        })
    );
});

// Background Sync: quando o SO avisa que a internet voltou, tenta esvaziar
// a fila sozinho — sem precisar o usuário reabrir o app.
self.addEventListener('sync', function (event) {
    if (event.tag === 'sync-outbox') {
        event.waitUntil(self.PitstopOutbox.sincronizarFila());
    }
});

// Notificação push de lembrete (ver cron/enviar_lembretes_push.php no
// servidor) — o payload já vem pronto (título/corpo/url), o SW só exibe.
self.addEventListener('push', function (event) {
    let dados = { titulo: 'PitStop BR', corpo: 'Você tem um lembrete pendente.', url: '/lembretes.php' };
    if (event.data) {
        try { dados = Object.assign(dados, event.data.json()); } catch (erro) { /* payload não-JSON: usa o texto puro como corpo */
            dados.corpo = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(dados.titulo, {
            body: dados.corpo,
            icon: '/assets/img/icon-192.png',
            badge: '/assets/img/icon-192.png',
            data: { url: dados.url },
        })
    );
});

// Clique na notificação: foca uma aba já aberta na URL do lembrete, ou abre
// uma nova se não houver nenhuma.
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/lembretes.php';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (janelas) {
            for (const janela of janelas) {
                if (new URL(janela.url).pathname === url && 'focus' in janela) {
                    return janela.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
