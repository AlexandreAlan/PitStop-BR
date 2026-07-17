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

$stmt = $pdo->prepare(
    'DELETE r FROM registros r
     INNER JOIN veiculos v ON v.id = r.veiculo_id
     WHERE r.id = :id AND ' . condicaoAcessoVeiculo('v')
);
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
bindAcessoVeiculo($stmt, $usuario['id']);
$stmt->execute();

if ($stmt->rowCount() === 0) {
    flashSet('erro', 'Registro não encontrado.');
} else {
    flashSet('sucesso', 'Registro excluído.');
}
header('Location: index.php');
exit;
