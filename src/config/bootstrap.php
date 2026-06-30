<?php
declare(strict_types=1);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getConexao();
