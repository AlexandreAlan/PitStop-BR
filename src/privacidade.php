<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$tituloPagina = 'Política de Privacidade — PitStop BR';
$mostrarVoltar = true;
require __DIR__ . '/includes/header.php';
?>

<div class="px-1 pb-4" style="font-size: 0.95rem;">
    <h5 class="mb-3">Política de Privacidade</h5>
    <p class="text-muted small">Última atualização: 11/07/2026. <a href="#historico">Ver histórico de alterações</a>.</p>

    <h6 class="mt-4">1. Quem somos e como falar com a gente</h6>
    <p>O PitStop BR é um aplicativo pessoal de controle de abastecimentos e manutenções de veículos,
    operado por Alexandre Alan, que atua como controlador dos dados tratados neste app (Lei
    13.709/2018 — LGPD, Art. 5º, VI) e também como encarregado (Art. 5º, VIII) pra fins desta
    política. Para qualquer assunto relacionado a esta política, aos seus dados ou pra exercer
    qualquer um dos direitos do item 7, o contato é <strong>alexandre.basto444@gmail.com</strong>.</p>

    <h6 class="mt-4">2. Quais dados coletamos</h6>
    <ul>
        <li><strong>Cadastro:</strong> nome e e-mail.</li>
        <li><strong>Senha:</strong> armazenada apenas como hash criptográfico (bcrypt) — nunca em
            texto plano, nunca reversível.</li>
        <li><strong>Confirmação do e-mail:</strong> um código de 6 dígitos é enviado no cadastro pra
            provar que o e-mail informado é seu; guardamos só o hash desse código, nunca o código em
            claro.</li>
        <li><strong>Dados que você cadastra no uso do app:</strong> veículos (nome, tipo, cor, placa
            — a placa é tratada como dado pessoal), registros de abastecimento/manutenção/despesa
            (km, valores, datas, combustível, descrição) e lembretes.</li>
        <li><strong>Notificações push (opcional):</strong> se você ativar notificações, o navegador
            gera um identificador de inscrição que fica salvo pra podermos mandar o aviso — ver
            item 4 sobre o compartilhamento técnico que isso envolve.</li>
        <li><strong>Dados técnicos de sessão</strong> (cookie de login), necessários pro
            funcionamento do app — inclusive offline, já que o app funciona sem internet e sincroniza
            quando a conexão volta. Ver item 8 sobre cookies.</li>
        <li><strong>Registros técnicos de segurança:</strong> ao tentar entrar, cadastrar ou pedir
            redefinição de senha, guardamos um hash do seu IP por um período curto (até 48h),
            só pra limitar tentativas abusivas — não usamos isso pra te identificar ou rastrear.</li>
    </ul>

    <h6 class="mt-4">3. Finalidade e base legal do tratamento</h6>
    <p>Seus dados são usados exclusivamente para operar as funcionalidades do app: autenticação,
    isolamento da sua conta em relação às demais, e exibição/cálculo dos seus próprios registros
    (consumo, gastos, histórico). Não usamos seus dados para publicidade, perfilamento ou qualquer
    finalidade fora do funcionamento do próprio app — não existe nenhum tipo de decisão automatizada
    sobre você. A base legal é a <strong>execução de contrato</strong> (Art. 7º, V — os dados que
    você cadastra são o próprio serviço que o app entrega) combinada com <strong>consentimento</strong>
    (Art. 7º, I — aceite explícito desta política no cadastro, e opt-in específico pras notificações
    push). O administrador do sistema tem acesso a um painel com dados agregados de todas as contas
    (nome, e-mail, quantidade de veículos/registros e o total gasto) exclusivamente pra administrar e
    dar suporte ao sistema — esse painel não mostra o detalhe de cada registro (descrição, categoria
    etc.) e é acessível apenas ao administrador.</p>

    <h6 class="mt-4">4. Compartilhamento com terceiros</h6>
    <p>Não vendemos nem cedemos seus dados a terceiros pra qualquer finalidade comercial. Os únicos
    envios a serviços externos são:</p>
    <ul>
        <li><strong>E-mail:</strong> convites, código de confirmação e redefinição de senha são
            enviados via um provedor de SMTP — o conteúdo (endereço de e-mail e o texto da mensagem)
            passa por esse provedor só pra entrega, sem uso próprio dele.</li>
        <li><strong>Notificações push (só se você ativar):</strong> ao ativar, o navegador registra
            um identificador de inscrição num serviço de push do próprio navegador — Google (Chrome/
            Edge) ou Mozilla (Firefox), por exemplo — necessário pra entregar a notificação mesmo com
            o app fechado. Esse identificador é apagado automaticamente se você desativar as
            notificações ou excluir sua conta.</li>
        <li><strong>Bibliotecas de terceiros:</strong> o app carrega Bootstrap, ícones e gráficos de
            uma CDN (jsDelivr) — o navegador faz esse download direto, sem passar nenhum dado seu
            por lá; o arquivo baixado é verificado por hash (SRI) pra garantir que não foi alterado.</li>
    </ul>

    <h6 class="mt-4">5. Retenção e exclusão</h6>
    <p>Seus dados de uso (veículos, registros, lembretes) ficam armazenados enquanto sua conta
    existir. Você pode excluir sua conta e todos os dados vinculados a qualquer momento, na página
    <a href="conta.php">Minha Conta</a> — a exclusão é definitiva e imediata (nenhum backup retém
    esses dados depois). Registros técnicos de segurança (tentativas de login/cadastro/redefinição,
    códigos e tokens de uso único) são apagados automaticamente entre 48h e alguns dias após deixarem
    de ter uso, independente de você excluir a conta ou não.</p>

    <h6 class="mt-4">6. Seus direitos (Lei 13.709/2018 — LGPD, Art. 18)</h6>
    <p>Você pode, a qualquer momento:</p>
    <ul>
        <li><strong>Confirmar e acessar</strong> — ver exatamente quais dados temos sobre você em
            <a href="conta.php">Minha Conta</a> e nas telas de Veículos/Registros/Lembretes.</li>
        <li><strong>Corrigir</strong> — editar veículos e registros diretamente no app, a qualquer
            momento.</li>
        <li><strong>Portabilidade</strong> — baixar uma cópia estruturada (JSON) de todos os seus
            dados em <a href="conta.php">Minha Conta → Baixar meus dados</a>.</li>
        <li><strong>Eliminação</strong> — excluir a conta e todos os dados vinculados em
            <a href="conta.php">Minha Conta</a>.</li>
        <li><strong>Revogar consentimento</strong> — desativar notificações push a qualquer momento
            (o consentimento pra tratamento dos dados de cadastro em si é indissociável de manter a
            conta ativa, já que é a base do próprio serviço; nesse caso o caminho é a exclusão da
            conta).</li>
    </ul>
    <p>Qualquer pedido que não tenha um botão direto no app (ex.: informações adicionais sobre o
    tratamento, oposição a alguma finalidade específica) pode ser feito por e-mail ao controlador
    citado no item 1 — respondemos em até 15 dias, prazo do Art. 19 da LGPD.</p>

    <h6 class="mt-4">7. Cookies</h6>
    <p>Usamos só <strong>um</strong> cookie, estritamente necessário pro funcionamento do app: o
    cookie de sessão, que mantém você logado (`HttpOnly`, `Secure`, `SameSite=Strict` — não pode ser
    lido por script nenhum, só trafega por HTTPS). Não usamos cookies de rastreamento, publicidade
    ou analytics de terceiros. Como esse cookie é essencial (o app não funciona sem ele, do mesmo
    jeito que não tem como usar sem login), ele é definido automaticamente ao entrar — o aviso que
    aparece no primeiro acesso é só informativo, não é um pedido de opt-in (que a lei não exige pra
    cookies estritamente necessários).</p>

    <h6 class="mt-4">8. Segurança</h6>
    <p>Senhas e códigos de verificação com hash forte (bcrypt), conexão ao banco via consultas
    parametrizadas, cookies de sessão `HttpOnly`/`Secure`/`SameSite=Strict`, proteção CSRF em
    formulários, bloqueio temporário de login após tentativas falhas, revogação de sessões ativas ao
    trocar a senha, e isolamento de dados por conta em todas as telas e endpoints.</p>

    <h6 class="mt-4" id="historico">Histórico de alterações desta política</h6>
    <ul class="small text-muted">
        <li><strong>11/07/2026</strong> — reescrita completa: base legal explícita (item 3), detalhe
            do compartilhamento via notificações push e CDN (item 4), retenção de registros técnicos
            de segurança (item 5), direito de portabilidade com botão de exportação (item 6), seção
            dedicada a cookies (item 7).</li>
        <li><strong>01/07/2026</strong> — versão inicial.</li>
    </ul>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
