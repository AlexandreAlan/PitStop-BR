<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$usuario = exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Método não permitido.');
}

csrfVerificarOuFalhar();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    die('ID inválido.');
}

if (excluirPosto($pdo, $usuario['id'], $id)) {
    flashSet('sucesso', 'Posto excluído. Os registros que apontavam pra ele continuam, só sem o posto vinculado.');
} else {
    flashSet('erro', 'Posto não encontrado.');
}
header('Location: postos.php');
exit;
