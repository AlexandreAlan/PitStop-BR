<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$tituloPagina = 'Instalar — PitStop BR';
$telaAuth = true;
require __DIR__ . '/includes/header.php';
?>

<div class="tab-instalar mb-3">
    <button type="button" class="tab-btn ativo" data-tab="apk"><i class="bi bi-phone me-1"></i>APK Android</button>
    <button type="button" class="tab-btn" data-tab="pwa"><i class="bi bi-globe2 me-1"></i>Instalar (PWA)</button>
</div>

<div id="pane-apk" class="pane-instalar ativo">
    <a class="btn btn-primary btn-lg w-100 mb-3" href="downloads/pitstop-br.apk" download><i class="bi bi-download me-1"></i>Baixar APK (Android)</a>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body p-3">
            <h2 class="h6 text-muted mb-2">Como instalar o APK</h2>
            <ol class="small mb-0 ps-3">
                <li class="mb-2">Toque em <strong>Baixar APK</strong> acima.</li>
                <li class="mb-2">Abra o arquivo baixado (<code>pitstop-br.apk</code>).</li>
                <li class="mb-2">O Android vai pedir pra permitir <strong>"instalar apps desconhecidos"</strong> — toque em <strong>Configurações</strong> e ative pro seu navegador.</li>
                <li class="mb-2">Volte e toque em <strong>Instalar</strong>.</li>
                <li>Pronto — o ícone <strong>PitStop BR</strong> aparece na tela inicial.</li>
            </ol>
        </div>
    </div>
    <p class="text-center text-muted small">App assinado · SHA-256: D2:57:C8:5B:61:E9:3A:3E:C2:40:1A:D2:11:52:BD:F2:70:58:1E:F7:16:2B:FC:FF:40:DF:EB:DD:19:25:AB:47</p>
</div>

<div id="pane-pwa" class="pane-instalar">
    <a class="btn btn-outline-primary btn-lg w-100 mb-3" href="login.php">Abrir o app no navegador</a>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body p-3">
            <h2 class="h6 text-muted mb-2">Instalar como PWA (sem APK)</h2>
            <ol class="small mb-0 ps-3">
                <li class="mb-2">Abra <code>pitstop.morenadoaco.com.br</code> no <strong>Chrome</strong> (Android) ou <strong>Safari</strong> (iPhone).</li>
                <li class="mb-2"><strong>Android:</strong> menu <i class="bi bi-three-dots-vertical"></i> → <strong>Adicionar à tela inicial</strong> / <strong>Instalar app</strong>.</li>
                <li class="mb-2"><strong>iPhone:</strong> botão <strong>Compartilhar</strong> <i class="bi bi-box-arrow-up"></i> → <strong>Adicionar à Tela de Início</strong>.</li>
                <li>Vira um ícone em tela cheia, igual a um app.</li>
            </ol>
        </div>
    </div>
    <p class="text-center text-muted small">No iPhone não existe APK — o PWA é o jeito de instalar.</p>
</div>

<script src="assets/js/instalar.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
