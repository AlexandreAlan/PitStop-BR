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

/**
 * Concede a sessão autenticada (login normal, aceite de convite ou
 * verificação de e-mail — os 3 pontos de entrada da conta). Sempre
 * regenera o ID de sessão (evita fixation) e grava 'sessao_emitida_em':
 * usado por checarRevogacaoDeSessao() em bootstrap.php pra derrubar
 * sessões antigas quando o dono troca a senha em outro aparelho (ver
 * redefinirSenhaComToken() e usuarios.sessao_valida_apos).
 */
function iniciarSessaoUsuario(int $id, string $nome, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['usuario_id']       = $id;
    $_SESSION['usuario_nome']     = $nome;
    $_SESSION['usuario_role']     = $role;
    $_SESSION['sessao_emitida_em'] = time();
}

/**
 * Derruba a sessão atual se ela foi emitida antes da última troca de senha
 * do usuário (usuarios.sessao_valida_apos) — fecha a janela de uma sessão
 * sequestrada continuar válida depois do dono já ter trocado a senha em
 * outro aparelho. Sessão sem 'sessao_emitida_em' (anterior a esse controle
 * existir) é tratada como a mais antiga possível: cai assim que
 * sessao_valida_apos for setado pela primeira vez, nunca depois.
 */
function checarRevogacaoDeSessao(PDO $pdo): void
{
    if (empty($_SESSION['usuario_id'])) {
        return;
    }

    $stmt = $pdo->prepare('SELECT sessao_valida_apos FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => (int) $_SESSION['usuario_id']]);
    $validaApos = $stmt->fetchColumn();

    if ($validaApos === false || $validaApos === null) {
        return;
    }

    $emitidaEm = (int) ($_SESSION['sessao_emitida_em'] ?? 0);
    if ($emitidaEm < (new DateTime($validaApos))->getTimestamp()) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
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
        // Faz o mesmo bcrypt "inútil" que o caminho de sucesso faria — sem
        // isso, esse retorno adiantado é uns bons milissegundos mais rápido
        // que o caminho que hasheia e insere de verdade, e esse tempo de
        // resposta sozinho já revelaria quais e-mails têm conta (a mesma
        // classe de vazamento já corrigida em esqueci_senha.php).
        password_hash($senha, PASSWORD_DEFAULT);
        // Não revela pro requisitante que o e-mail já tem conta (evita
        // enumeração de contas): 'email_existente' é só um sinal interno pra
        // cadastro.php decidir o que fazer (avisar o dono da conta por
        // e-mail), nunca é texto mostrado na tela.
        return ['ok' => false, 'erro' => 'email_existente'];
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
        ':codigo_hash' => password_hash($codigo, PASSWORD_DEFAULT),
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

    if (!password_verify($codigo, $registro['codigo_hash'])) {
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

    iniciarSessaoUsuario((int) $usuario['id'], $usuario['nome'], $usuario['role']);

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
        // sessao_valida_apos = NOW() derruba qualquer sessão sequestrada em
        // outro aparelho (ver checarRevogacaoDeSessao em bootstrap.php) —
        // sem isso, uma sessão roubada continuava válida até o dono já ter
        // trocado a senha de propósito.
        $pdo->prepare('UPDATE usuarios SET senha_hash = :hash, tentativas_falhas = 0, bloqueado_ate = NULL, sessao_valida_apos = NOW() WHERE id = :id')
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
