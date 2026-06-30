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

$stmt = $pdo->prepare('DELETE FROM veiculos WHERE id = :id AND usuario_id = :usuario_id');
$stmt->execute([':id' => $id, ':usuario_id' => $usuario['id']]);

if ($stmt->rowCount() === 0) {
    flashSet('erro', 'Veículo não encontrado.');
} else {
    flashSet('sucesso', 'Veículo e todo o histórico vinculado foram excluídos.');
}
header('Location: veiculos.php');
exit;
