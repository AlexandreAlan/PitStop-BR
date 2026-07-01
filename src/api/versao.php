<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (usuarioAtual() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Não autenticado.']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'versao'    => APP_VERSION,
    'changelog' => APP_CHANGELOG,
]);
