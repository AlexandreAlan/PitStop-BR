<?php
declare(strict_types=1);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// 30 dias: o app roda instalado (PWA/TWA) e precisa continuar logado mesmo
// depois de dias sem abrir — inclusive offline, quando não há como logar de novo.
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Timeout de inatividade: o cookie dura 30 dias (pro uso offline do
// PWA/TWA), mas isso não devia significar "logado pra sempre" — 7 dias sem
// nenhuma requisição derruba a sessão no servidor, mesmo com o cookie ainda
// válido no navegador (reduz a janela de um cookie roubado/aparelho
// destravado continuar valendo por até 30 dias). session_regenerate_id
// também troca o id da sessão, no mesmo padrão já usado em login/exclusão
// de conta (auth.php).
const SESSAO_IDLE_TIMEOUT_SEGUNDOS = 60 * 60 * 24 * 7;
if (
    !empty($_SESSION['usuario_id'])
    && !empty($_SESSION['ultima_atividade'])
    && (time() - (int) $_SESSION['ultima_atividade']) > SESSAO_IDLE_TIMEOUT_SEGUNDOS
) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['ultima_atividade'] = time();

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/versao.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getConexao();
