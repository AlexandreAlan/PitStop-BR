
<?php if (!empty($telaAuth)): ?>
    </div>
</div>
<?php else: ?>
</main>

<?php if ($usuarioLogado !== null): ?>
<?php $paginaAtual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')); ?>
<nav class="bottom-nav">
    <a href="index.php" class="<?= $paginaAtual === 'index.php' ? 'ativo' : '' ?>">
        <i class="bi bi-house-fill"></i>Início
    </a>
    <a href="relatorios.php" class="<?= $paginaAtual === 'relatorios.php' ? 'ativo' : '' ?>">
        <i class="bi bi-bar-chart-fill"></i>Relatórios
    </a>
    <a href="veiculos.php" class="<?= $paginaAtual === 'veiculos.php' ? 'ativo' : '' ?>">
        <i class="bi bi-car-front-fill"></i>Veículos
    </a>
</nav>
<?php endif; ?>
</div><!-- .app-shell -->
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
