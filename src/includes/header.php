<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= h($tituloPagina ?? 'PitStop BR') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    body {
        background-color: #f4f6f9;
        padding-bottom: 110px;
    }
    .header-dark {
        background-color: #1c1f26;
        color: #fff;
        padding: 1.25rem 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .75rem;
        position: relative;
    }
    .header-dark .voltar {
        position: absolute;
        left: 1rem;
        color: #fff;
        font-size: 1.25rem;
    }
    .card-resumo {
        margin: -1.5rem 1rem 1.5rem;
        border-radius: 1rem;
        border: none;
    }
    .fab {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
        z-index: 1030;
    }
    .lista-registros .card,
    .lista-veiculos .card {
        border: none;
        border-radius: 0.75rem;
    }
</style>
</head>
<body>

<header class="header-dark">
    <?php if (!empty($mostrarVoltar)): ?>
    <a href="index.php" class="voltar"><i class="bi bi-arrow-left"></i></a>
    <?php endif; ?>
    <h1 class="h4 mb-0 text-center"><i class="bi bi-fuel-pump-fill me-2"></i>PitStop BR</h1>
</header>

<?php $flash = flashPegar(); ?>
<?php if ($flash): ?>
<div class="container mt-3">
    <div class="alert alert-<?= $flash['tipo'] === 'erro' ? 'danger' : 'success' ?> py-2 mb-0" role="alert">
        <?= h($flash['mensagem']) ?>
    </div>
</div>
<?php endif; ?>

<main class="container">
