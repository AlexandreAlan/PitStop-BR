<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#ff6b35">
<title><?= h($tituloPagina ?? 'PitStop BR') ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16.png">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="manifest" href="manifest.json">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/brand.css">
</head>
<body<?= empty($telaAuth) ? ' class="tem-bottom-nav"' : '' ?>>

<?php $usuarioLogado = usuarioAtual(); ?>
<?php if (!empty($telaAuth)): ?>
<div class="auth-wrap">
    <div class="auth-card">
        <img src="assets/img/logo-mark.svg" class="auth-logo" alt="PitStop BR">
        <h1 class="h4 text-center text-white mb-4"><?= h($tituloPagina ?? 'PitStop BR') ?></h1>
<?php else: ?>
<header class="header-dark">
    <?php if (!empty($mostrarVoltar)): ?>
    <a href="index.php" class="voltar"><i class="bi bi-arrow-left"></i></a>
    <?php endif; ?>
    <img src="assets/img/logo-mark.svg" class="brand-logo" alt="">
    <h1 class="h4 mb-0 text-center brand-wordmark">Pit<span class="brand-wordmark-accent">Stop</span> BR</h1>
    <?php if ($usuarioLogado !== null): ?>
    <div class="dropdown sair">
        <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Conta">
            <i class="bi bi-person-circle"></i>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="conta.php"><i class="bi bi-person me-2"></i>Minha Conta</a></li>
            <li><a class="dropdown-item" href="convidar.php"><i class="bi bi-send me-2"></i>Convidar Pessoas</a></li>
            <?php if (($usuarioLogado['role'] ?? null) === 'admin'): ?>
            <li><a class="dropdown-item" href="gerenciador.php"><i class="bi bi-speedometer me-2"></i>Painel Administrativo</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item disabled versao-app-item" href="#" tabindex="-1"><i class="bi bi-info-circle me-2"></i>Versão <?= h(APP_VERSION) ?></a></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
        </ul>
    </div>
    <?php endif; ?>
</header>
<?php endif; ?>

<?php $flash = flashPegar(); ?>
<?php if ($flash): ?>
<div class="container mt-3">
    <div class="alert alert-<?= $flash['tipo'] === 'erro' ? 'danger' : 'success' ?> py-2 mb-3" role="alert">
        <?= h($flash['mensagem']) ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($telaAuth)): ?>
<div class="app-shell">
<main class="container">
<?php if ($usuarioLogado !== null): ?>
<div id="aviso-atualizacao" class="d-none"></div>
<div id="pendencias-offline" class="d-none"></div>
<?php endif; ?>
<?php endif; ?>
