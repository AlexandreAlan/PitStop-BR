/**
 * Fila offline (IndexedDB) compartilhada entre a página (offline.js) e o
 * Service Worker (sw.php, via importScripts). Um registro/lembrete criado
 * sem internet entra aqui e só sai quando o POST pro servidor confirma —
 * client_uuid garante que reenviar o mesmo item não duplica nada (ver
 * api/registro.php e api/lembrete.php).
 */
(function (global) {
    'use strict';

    const DB_NAME = 'pitstop-offline';
    const DB_VERSAO = 1;
    const STORE = 'outbox';

    function abrirDb() {
        return new Promise(function (resolve, reject) {
            const req = indexedDB.open(DB_NAME, DB_VERSAO);
            req.onupgradeneeded = function () {
                const db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'client_uuid' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function gerarUuid() {
        if (global.crypto && global.crypto.randomUUID) {
            return global.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    async function enfileirar(tipo, payload, csrfToken) {
        const db = await abrirDb();
        const item = {
            client_uuid: gerarUuid(),
            tipo: tipo,
            payload: payload,
            csrf_token: csrfToken,
            criado_em: Date.now(),
            tentativas: 0,
            ultimo_erro: null,
        };
        await new Promise(function (resolve, reject) {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).add(item);
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
        return item;
    }

    async function listarPendentes() {
        const db = await abrirDb();
        return new Promise(function (resolve, reject) {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).getAll();
            req.onsuccess = function () { resolve(req.result.sort((a, b) => a.criado_em - b.criado_em)); };
            req.onerror = function () { reject(req.error); };
        });
    }

    async function remover(clientUuid) {
        const db = await abrirDb();
        return new Promise(function (resolve, reject) {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).delete(clientUuid);
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
    }

    async function atualizar(item) {
        const db = await abrirDb();
        return new Promise(function (resolve, reject) {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(item);
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
    }

    const ENDPOINTS = { registro: '/api/registro.php', lembrete: '/api/lembrete.php' };

    /**
     * Tenta enviar cada item pendente, em ordem. Item com erro de validação
     * (422) fica marcado com o erro e NÃO é reenviado sozinho — precisa de
     * correção manual. Falha de rede interrompe o flush inteiro (ainda offline).
     */
    async function sincronizarFila() {
        const pendentes = await listarPendentes();
        const resultado = { enviados: 0, comErro: 0, aindaOffline: false };

        for (const item of pendentes) {
            try {
                const resp = await fetch(ENDPOINTS[item.tipo], {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({}, item.payload, {
                        client_uuid: item.client_uuid,
                        csrf_token: item.csrf_token,
                    })),
                });

                if (resp.ok) {
                    await remover(item.client_uuid);
                    resultado.enviados++;
                    continue;
                }

                if (resp.status === 422 || resp.status === 400) {
                    const corpo = await resp.json().catch(() => ({}));
                    item.ultimo_erro = (corpo.erros || [corpo.erro] || ['Erro ao sincronizar.']).join(' ');
                    item.tentativas++;
                    await atualizar(item);
                    resultado.comErro++;
                    continue;
                }

                // 401 (sessão expirada) ou 403/5xx: para o flush, tenta de novo depois.
                resultado.aindaOffline = true;
                break;
            } catch (erroDeRede) {
                resultado.aindaOffline = true;
                break;
            }
        }

        return resultado;
    }

    global.PitstopOutbox = {
        enfileirar: enfileirar,
        listarPendentes: listarPendentes,
        remover: remover,
        sincronizarFila: sincronizarFila,
    };
})(typeof self !== 'undefined' ? self : this);
