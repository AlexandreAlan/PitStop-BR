<?php
declare(strict_types=1);

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidar(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfVerificarOuFalhar(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValidar($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Requisição inválida (token CSRF ausente ou expirado). Volte e tente novamente.');
    }
}
