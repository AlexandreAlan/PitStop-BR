<?php
declare(strict_types=1);

const AUTH_MAX_TENTATIVAS = 5;
const AUTH_BLOQUEIO_MINUTOS = 15;
const AUTH_SENHA_MIN = 8;

function usuarioAtual(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }

    return [
        'id'   => (int) $_SESSION['usuario_id'],
        'nome' => (string) ($_SESSION['usuario_nome'] ?? ''),
    ];
}

function exigirLogin(): array
{
    $usuario = usuarioAtual();
    if ($usuario === null) {
        header('Location: login.php');
        exit;
    }

    return $usuario;
}

function registrarUsuario(PDO $pdo, string $nome, string $email, string $senha): array
{
    $nome  = trim($nome);
    $email = mb_strtolower(trim($email));

    if ($nome === '' || mb_strlen($nome) > 100) {
        return ['ok' => false, 'erro' => 'Informe seu nome (máx. 100 caracteres).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        return ['ok' => false, 'erro' => 'E-mail inválido.'];
    }
    if (mb_strlen($senha) < AUTH_SENHA_MIN) {
        return ['ok' => false, 'erro' => 'A senha precisa ter pelo menos ' . AUTH_SENHA_MIN . ' caracteres.'];
    }

    $existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = :email');
    $existe->execute([':email' => $email]);
    if ($existe->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Já existe uma conta com esse e-mail.'];
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash) VALUES (:nome, :email, :senha_hash)');
    $stmt->execute([':nome' => $nome, ':email' => $email, ':senha_hash' => $hash]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'nome' => $nome];
}

function loginUsuario(PDO $pdo, string $email, string $senha): array
{
    $email = mb_strtolower(trim($email));
    $erroGenerico = ['ok' => false, 'erro' => 'E-mail ou senha inválidos.'];

    $stmt = $pdo->prepare(
        'SELECT id, nome, senha_hash, tentativas_falhas, bloqueado_ate FROM usuarios WHERE email = :email'
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        return $erroGenerico;
    }

    if ($usuario['bloqueado_ate'] !== null && new DateTime($usuario['bloqueado_ate']) > new DateTime()) {
        return ['ok' => false, 'erro' => 'Conta temporariamente bloqueada por excesso de tentativas. Tente novamente em alguns minutos.'];
    }

    if (!password_verify($senha, $usuario['senha_hash'])) {
        $tentativas = (int) $usuario['tentativas_falhas'] + 1;
        if ($tentativas >= AUTH_MAX_TENTATIVAS) {
            $bloqueio = (new DateTime())->modify('+' . AUTH_BLOQUEIO_MINUTOS . ' minutes')->format('Y-m-d H:i:s');
            $upd = $pdo->prepare('UPDATE usuarios SET tentativas_falhas = :t, bloqueado_ate = :b WHERE id = :id');
            $upd->execute([':t' => $tentativas, ':b' => $bloqueio, ':id' => $usuario['id']]);
        } else {
            $upd = $pdo->prepare('UPDATE usuarios SET tentativas_falhas = :t WHERE id = :id');
            $upd->execute([':t' => $tentativas, ':id' => $usuario['id']]);
        }
        return $erroGenerico;
    }

    $upd = $pdo->prepare('UPDATE usuarios SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = :id');
    $upd->execute([':id' => $usuario['id']]);

    session_regenerate_id(true);
    $_SESSION['usuario_id']   = (int) $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];

    return ['ok' => true];
}

function logoutUsuario(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
