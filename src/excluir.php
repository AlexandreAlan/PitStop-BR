<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

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

$stmt = $pdo->prepare('DELETE FROM registros WHERE id = :id');
$stmt->execute([':id' => $id]);

flashSet('sucesso', 'Registro excluído.');
header('Location: index.php');
exit;
