<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$tituloPagina = 'Política de Privacidade — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="px-1 pb-4" style="font-size: 0.95rem;">
    <h5 class="mb-3">Política de Privacidade</h5>
    <p class="text-muted small">Última atualização: 01/07/2026.</p>

    <h6 class="mt-4">1. Quem somos</h6>
    <p>O PitStop BR é um aplicativo pessoal de controle de abastecimentos e manutenções de veículos,
    operado por Alexandre Alan. Para qualquer assunto relacionado a esta política ou aos seus dados,
    o contato é <strong>alexandre.basto444@gmail.com</strong>.</p>

    <h6 class="mt-4">2. Quais dados coletamos</h6>
    <ul>
        <li>Dados de cadastro: nome e e-mail.</li>
        <li>Senha: armazenada apenas como hash criptográfico (nunca em texto plano).</li>
        <li>Confirmação do e-mail: um código de 6 dígitos é enviado no cadastro pra provar que o
            e-mail informado é seu; guardamos só o hash desse código, nunca o código em claro.</li>
        <li>Dados que você cadastra no uso do app: veículos, registros de abastecimento e manutenção
            (km, valores, datas, combustível, descrição).</li>
        <li>Dados técnicos de sessão (cookie de login), necessários pro funcionamento do app — inclusive
            offline, já que o app funciona sem internet e sincroniza quando a conexão volta.</li>
    </ul>

    <h6 class="mt-4">3. Finalidade do tratamento</h6>
    <p>Seus dados são usados exclusivamente para operar as funcionalidades do app: autenticação,
    isolamento da sua conta em relação às demais, e exibição/cálculo dos seus próprios registros
    (consumo, gastos, histórico). Não usamos seus dados para publicidade, perfilamento ou qualquer
    finalidade fora do funcionamento do próprio app. O administrador do sistema tem acesso a um painel
    com dados agregados de todas as contas (nome, e-mail, quantidade de veículos/registros e o total
    gasto) exclusivamente pra administrar e dar suporte ao sistema — esse painel não mostra o detalhe
    de cada registro (descrição, categoria etc.) e é acessível apenas ao administrador.</p>

    <h6 class="mt-4">4. Compartilhamento com terceiros</h6>
    <p>Não compartilhamos, vendemos ou cedemos seus dados a terceiros. O único envio a um serviço
    externo é o e-mail de convite (enviado via SMTP) quando alguém com conta no app te convida.</p>

    <h6 class="mt-4">5. Retenção e exclusão</h6>
    <p>Seus dados ficam armazenados enquanto sua conta existir. Você pode excluir sua conta e todos
    os dados vinculados (veículos e registros) a qualquer momento, na página <a href="conta.php">Minha
    Conta</a>. A exclusão é definitiva e imediata.</p>

    <h6 class="mt-4">6. Seus direitos (Lei 13.709/2018 — LGPD)</h6>
    <p>Você pode, a qualquer momento: confirmar a existência de tratamento, acessar seus dados,
    corrigir dados incompletos/desatualizados, solicitar a eliminação dos dados, revogar o
    consentimento e ser informado sobre o uso compartilhado (que, como descrito acima, não ocorre).
    Acesso e correção podem ser feitos diretamente no app; eliminação está disponível em
    <a href="conta.php">Minha Conta</a>; qualquer outro pedido pode ser feito por e-mail ao
    controlador citado no item 1.</p>

    <h6 class="mt-4">7. Segurança</h6>
    <p>Senhas com hash forte (bcrypt), conexão ao banco via consultas parametrizadas, cookies de
    sessão `HttpOnly`/`Secure`/`SameSite=Strict`, proteção CSRF em formulários, bloqueio temporário
    de login após tentativas falhas e isolamento de dados por conta.</p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
