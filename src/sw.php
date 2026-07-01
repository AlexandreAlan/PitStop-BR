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

// IMPORTANTE: nenhuma página autenticada (index.php, adicionar.php, etc.) entra
// aqui. O Service Worker registra em toda página, inclusive login.php ANTES do
// usuário logar — se essas páginas estivessem na lista, o install pré-cachearia
// a resposta redirecionada (login.php, HTTP 200 após seguir o redirect) com a
// chave "/index.php" e o modo offline mostraria a tela de login pra sempre. As
// páginas autenticadas são cacheadas sob demanda pelo handler de "navigate"
// abaixo, só quando a resposta de rede não vier de um redirect de login.
const PRECACHE_URLS = [
    '/manifest.json',
    '/assets/css/brand.css',
    '/assets/js/index.js',
    '/assets/js/adicionar.js',
    '/assets/js/lembretes.js',
    '/assets/js/veiculos.js',
    '/assets/js/relatorios.js',
    '/assets/js/animacoes.js',
    '/assets/js/offline.js',
    '/assets/js/idb-outbox.js',
    '/assets/img/icon-192.png',
    '/assets/img/icon-512.png',
    '/assets/img/logo-mark.svg',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function (cache) { return cache.addAll(PRECACHE_URLS); })
            .then(function () { return self.skipWaiting(); })
    );
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
                        return cached || caches.match('/index.php');
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
