<?php
declare(strict_types=1);

const AUTH_MAX_TENTATIVAS = 5;
const AUTH_BLOQUEIO_MINUTOS = 15;
const AUTH_SENHA_MIN = 8;
const AUTH_CODIGO_VALIDADE_MINUTOS = 15;
const AUTH_CODIGO_MAX_TENTATIVAS = 5;
const AUTH_RESET_VALIDADE_MINUTOS = 60;

function usuarioAtual(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }

    return [
        'id'   => (int) $_SESSION['usuario_id'],
        'nome' => (string) ($_SESSION['usuario_nome'] ?? ''),
        'role' => (string) ($_SESSION['usuario_role'] ?? 'user'),
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

function exigirAdmin(): array
{
    $usuario = exigirLogin();
    if ($usuario['role'] !== 'admin') {
        http_response_code(404);
        die('Página não encontrada.');
    }

    return $usuario;
}

function registrarUsuario(PDO $pdo, string $nome, string $email, string $senha, bool $aceitouPrivacidade): array
{
    $nome  = trim($nome);
    $email = mb_strtolower(trim($email));

    if ($nome === '' || mb_strlen($nome) > 100) {
        return ['ok' => false, 'erro' => 'Informe seu nome (máx. 100 caracteres).'];
    }
    if (!preg_match('/\S+\s+\S+/u', $nome)) {
        return ['ok' => false, 'erro' => 'Informe nome e sobrenome.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        return ['ok' => false, 'erro' => 'E-mail inválido.'];
    }
    if (mb_strlen($senha) < AUTH_SENHA_MIN) {
        return ['ok' => false, 'erro' => 'A senha precisa ter pelo menos ' . AUTH_SENHA_MIN . ' caracteres.'];
    }
    if (!$aceitouPrivacidade) {
        return ['ok' => false, 'erro' => 'É necessário aceitar a Política de Privacidade pra criar a conta.'];
    }

    $existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = :email');
    $existe->execute([':email' => $email]);
    if ($existe->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Já existe uma conta com esse e-mail.'];
    }

    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nome, email, senha_hash, aceite_privacidade_em) VALUES (:nome, :email, :senha_hash, NOW())'
    );
    $stmt->execute([':nome' => $nome, ':email' => $email, ':senha_hash' => $hash]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'nome' => $nome];
}

/**
 * Gera um código de 6 dígitos, guarda só o hash (nunca o código em claro) e
 * devolve o código pra ser mandado por e-mail. Cadastro só é considerado
 * confirmado (LGPD: prova de titularidade do e-mail) depois de validado
 * em verificarCodigoEmail().
 */
function gerarCodigoVerificacao(PDO $pdo, int $usuarioId): string
{
    $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiraEm = (new DateTime())->modify('+' . AUTH_CODIGO_VALIDADE_MINUTOS . ' minutes')->format('Y-m-d H:i:s');

    $pdo->prepare('DELETE FROM verificacoes_email WHERE usuario_id = :id')->execute([':id' => $usuarioId]);

    $stmt = $pdo->prepare(
        'INSERT INTO verificacoes_email (usuario_id, codigo_hash, expira_em) VALUES (:usuario_id, :codigo_hash, :expira_em)'
    );
    $stmt->execute([
        ':usuario_id'  => $usuarioId,
        ':codigo_hash' => hash('sha256', $codigo),
        ':expira_em'   => $expiraEm,
    ]);

    return $codigo;
}

function verificarCodigoEmail(PDO $pdo, int $usuarioId, string $codigo): array
{
    $stmt = $pdo->prepare(
        'SELECT id, codigo_hash, tentativas, expira_em FROM verificacoes_email
         WHERE usuario_id = :usuario_id ORDER BY criado_em DESC LIMIT 1'
    );
    $stmt->execute([':usuario_id' => $usuarioId]);
    $registro = $stmt->fetch();

    if (!$registro) {
        return ['ok' => false, 'erro' => 'Nenhum código pendente. Peça um novo código.'];
    }
    if ((int) $registro['tentativas'] >= AUTH_CODIGO_MAX_TENTATIVAS) {
        return ['ok' => false, 'erro' => 'Muitas tentativas erradas. Peça um novo código.'];
    }
    if (new DateTime($registro['expira_em']) < new DateTime()) {
        return ['ok' => false, 'erro' => 'Código expirado. Peça um novo código.'];
    }

    if (!hash_equals($registro['codigo_hash'], hash('sha256', $codigo))) {
        $pdo->prepare('UPDATE verificacoes_email SET tentativas = tentativas + 1 WHERE id = :id')
            ->execute([':id' => $registro['id']]);
        return ['ok' => false, 'erro' => 'Código incorreto.'];
    }

    $pdo->prepare('UPDATE usuarios SET email_verificado_em = NOW() WHERE id = :id')->execute([':id' => $usuarioId]);
    $pdo->prepare('DELETE FROM verificacoes_email WHERE usuario_id = :id')->execute([':id' => $usuarioId]);

    return ['ok' => true];
}

function loginUsuario(PDO $pdo, string $email, string $senha): array
{
    $email = mb_strtolower(trim($email));
    $erroGenerico = ['ok' => false, 'erro' => 'E-mail ou senha inválidos.'];

    $stmt = $pdo->prepare(
        'SELECT id, nome, role, senha_hash, tentativas_falhas, bloqueado_ate, email_verificado_em
         FROM usuarios WHERE email = :email'
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

    if ($usuario['email_verificado_em'] === null) {
        session_regenerate_id(true);
        $_SESSION['verificacao_pendente_id'] = (int) $usuario['id'];
        return ['ok' => true, 'precisaVerificar' => true];
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id']   = (int) $usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['usuario_role'] = $usuario['role'];

    return ['ok' => true];
}

/**
 * Gera um token de redefinição de senha (só o hash é guardado, igual ao
 * convite). Tokens pendentes anteriores do mesmo usuário são invalidados —
 * só o link mais recente enviado por e-mail funciona.
 */
function gerarTokenRedefinicaoSenha(PDO $pdo, int $usuarioId): string
{
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiraEm  = (new DateTime())->modify('+' . AUTH_RESET_VALIDADE_MINUTOS . ' minutes')->format('Y-m-d H:i:s');

    $pdo->prepare('DELETE FROM redefinicoes_senha WHERE usuario_id = :id')->execute([':id' => $usuarioId]);

    $stmt = $pdo->prepare(
        'INSERT INTO redefinicoes_senha (usuario_id, token_hash, expira_em) VALUES (:usuario_id, :token_hash, :expira_em)'
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':token_hash' => $tokenHash,
        ':expira_em'  => $expiraEm,
    ]);

    return $token;
}

function redefinirSenhaComToken(PDO $pdo, string $token, string $novaSenha): array
{
    if (mb_strlen($novaSenha) < AUTH_SENHA_MIN) {
        return ['ok' => false, 'erro' => 'A senha precisa ter pelo menos ' . AUTH_SENHA_MIN . ' caracteres.'];
    }

    $tokenHash = hash('sha256', $token);

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            'SELECT id, usuario_id FROM redefinicoes_senha
             WHERE token_hash = :token_hash AND usado_em IS NULL AND expira_em > NOW() FOR UPDATE'
        );
        $lock->execute([':token_hash' => $tokenHash]);
        $registro = $lock->fetch();

        if (!$registro) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Este link é inválido, já foi usado ou expirou.'];
        }

        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE usuarios SET senha_hash = :hash, tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = :id')
            ->execute([':hash' => $hash, ':id' => $registro['usuario_id']]);
        $pdo->prepare('UPDATE redefinicoes_senha SET usado_em = NOW() WHERE id = :id')
            ->execute([':id' => $registro['id']]);

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
