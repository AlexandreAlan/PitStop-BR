<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

$registroId = filter_input(INPUT_GET, 'registro_id', FILTER_VALIDATE_INT);
if (!$registroId) {
    http_response_code(400);
    die('ID inválido.');
}

$foto = buscarFotoRegistro($pdo, $usuario['id'], $registroId);
if ($foto === null) {
    http_response_code(404);
    die('Foto não encontrada.');
}

header('Content-Type: ' . $foto['mime_type']);
header('Content-Length: ' . strlen($foto['dados']));
// Comprovante pode conter dado sensível (placa, endereço do posto) — cache
// só no navegador de quem tem acesso, nunca em proxy/CDN compartilhado.
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $foto['dados'];
