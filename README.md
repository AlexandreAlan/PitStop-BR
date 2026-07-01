# PitStop BR

[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Sistema web simples, focado em mobile, para registrar abastecimentos e manutenções de veículos e
acompanhar consumo (km/l) e gastos. Acesse em **https://pitstop.morenadoaco.com.br**.

## Funcionalidades

- Multi-usuário: cada conta só enxerga e mexe nos próprios veículos/registros
- Cadastro aberto com confirmação de e-mail (código de 6 dígitos, válido por 15 min, com limite de
  tentativas e reenvio), rate limit de 5 cadastros por hora por IP; também dá pra entrar por
  convite de quem já usa o app (link com token de uso único e validade de 7 dias, e-mail
  considerado confirmado automaticamente nesse caso)
- Painel Administrativo (conta com papel "admin"): visão agregada de todas as contas — veículos,
  registros e total gasto por conta — sem acessar o detalhe de nenhum registro individual
- Funciona sem internet: Service Worker + fila offline (IndexedDB) guardam o que você registra sem
  sinal e sincronizam sozinhos assim que a conexão volta (inclusive em segundo plano, via
  Background Sync); aviso de "o que mudou" nas primeiras aberturas depois de uma atualização
- Login com bloqueio temporário após tentativas falhas
- Cadastro, edição e exclusão de veículos (nome + tipo: Moto/Carro/Outro)
- Registro, edição e exclusão de abastecimentos (km, litros, valor pago, combustível: Gasolina
  Comum/Aditivada, Etanol, Diesel, GNV ou Outro), manutenções (km, valor, descrição) e despesas
  (Seguro, IPVA, Estacionamento, Pedágio, Multa, Lavagem ou Outro)
- Lembretes de manutenção/documentos por km ou por data (ex.: troca de óleo aos 40.000km, seguro
  vencendo em uma data), com status Vencido/Próximo/Em dia e alerta no painel principal
- Cálculo automático da última média de consumo (km/l), do preço por litro e do gasto do mês
- Relatórios com gráficos (Chart.js): gasto por mês, km rodado por mês e evolução do consumo, cards
  de gasto total, gasto médio por dia e preço médio por litro, filtro por veículo e por período
  (data início/fim), e exportação em CSV ou PDF (impressão do navegador)
- Filtro de registros e relatórios por veículo
- Conformidade com a LGPD: política de privacidade, aceite de consentimento obrigatório no
  cadastro/convite e exclusão definitiva da própria conta e dados (direito ao esquecimento)
- Identidade visual própria (paleta laranja + teal, logo, favicons), com medidor (gauge) de
  consumo, selos de ícone coloridos nas estatísticas, transições e micro-interações em toda a
  interface, e manifest PWA (instalável na tela inicial)
- App Android nativo (TWA assinado) pra instalar via APK, além do PWA — página `/instalar.php`
  com instruções pras duas formas
- Interface mobile-first (Bootstrap 5 + Bootstrap Icons) com navegação inferior fixa e botão de
  novo registro embutido na própria barra (elevado, ao centro); em telas ≥992px a navegação vira
  uma sidebar compacta e as páginas ganham layout de painel (ex.: gráficos em grade de 2 colunas
  nos Relatórios)
- Estados vazios com ícone, texto e chamada para ação em vez de mensagens soltas

## Stack

- **Frontend:** HTML5 + Bootstrap 5 (CDN) + Bootstrap Icons + Chart.js (CDN) + identidade visual própria (CSS, com animações) + manifest PWA + Service Worker/IndexedDB (modo offline)
- **Backend:** PHP 8.2 puro (sem framework), Apache
- **Banco:** MySQL 8.0, acesso exclusivo via PDO (prepared statements)
- **E-mail:** cliente SMTP próprio em PHP puro (sem dependências), usado pro envio de convites
- **App Android:** TWA (Trusted Web Activity) gerado com Bubblewrap, assinado com keystore próprio
- **Infra:** Docker Compose (build próprio da imagem PHP+Apache hardenizada)

## Estrutura de pastas

```
pitstop-br/
├── docker-compose.yml
├── .env                    # credenciais (gitignored, gerado localmente)
├── db/
│   └── init.sql            # schema + seed inicial
├── docker/php/
│   ├── Dockerfile          # imagem PHP+Apache hardenizada
│   ├── php.ini             # hardening PHP (expose_php off, sessão segura, sem upload...)
│   └── security.conf       # hardening Apache (headers, sem listagem de diretório)
└── src/
    ├── api/
    │   ├── registro.php    # POST idempotente (client_uuid) usado pela fila offline
    │   ├── lembrete.php    # POST idempotente (client_uuid) usado pela fila offline
    │   └── versao.php      # versão + changelog em JSON (aviso de atualização)
    ├── assets/
    │   ├── css/brand.css   # identidade visual (paleta, header, bottom-nav, telas de auth)
    │   ├── img/            # logo, favicons e ícones PWA
    │   └── js/
    │       ├── offline.js       # registra o SW, intercepta formulários sem sinal, aviso de atualização
    │       └── idb-outbox.js    # fila offline (IndexedDB), compartilhada com o Service Worker
    ├── config/
    │   ├── bootstrap.php   # sessão segura (30 dias, PWA) + carrega conexão/CSRF/auth/funções/versão
    │   ├── conexao.php     # PDO (lê credenciais do ambiente)
    │   ├── csrf.php        # geração/validação de token CSRF
    │   ├── auth.php        # login/registro/logout/guard, papel admin, verificação de e-mail, lockout
    │   ├── versao.php      # versão do app + changelog (rodapé e aviso de atualização)
    │   └── mailer.php      # cliente SMTP mínimo (sem dependências) pro envio de convites/códigos
    ├── includes/
    │   ├── functions.php   # helpers (escape, flash, cálculo de consumo, validação de registro/lembrete)
    │   ├── header.php
    │   └── footer.php
    ├── manifest.json       # manifest PWA (instalável na tela inicial)
    ├── sw.php              # Service Worker (cache com versionamento, fallback offline, Background Sync)
    ├── login.php / cadastro.php / verificar_email.php / logout.php   # autenticação + confirmação de e-mail
    ├── convidar.php / convite.php              # envio e aceite de convite (registro por convite)
    ├── gerenciador.php     # painel administrativo (dados agregados por conta; só para papel admin)
    ├── conta.php / privacidade.php             # minha conta (exclusão de dados) e política LGPD
    ├── index.php           # dashboard (última média, gastos do mês, alerta de lembretes, registros)
    ├── relatorios.php      # gráficos de gasto, km rodado e consumo; filtro por período; export CSV/PDF
    ├── adicionar.php / registro_editar.php / excluir.php   # CRUD de registros (abastecimento/manutenção/despesa)
    ├── lembretes.php / lembrete_concluir.php / lembrete_excluir.php   # lembretes de manutenção (km ou data)
    └── veiculos.php / veiculo_editar.php / veiculo_excluir.php   # CRUD de veículos
```

## Segurança aplicada

- 100% PDO com prepared statements (sem SQL cru/concatenado) — zero SQLi
- Proteção CSRF (token por sessão, `hash_equals`) em todo formulário POST
- Output sempre escapado (`htmlspecialchars`) — zero XSS refletido
- Validação estrita (whitelist) de tipo de registro, tipo de veículo, datas e números
- Autenticação: senha com `password_hash`/`password_verify`, bloqueio de 15 min após 5 tentativas
  falhas, mensagem de erro genérica no login (sem enumeração de e-mail), `session_regenerate_id`
  após login/cadastro
- Isolamento multi-usuário: toda consulta/gravação de veículo e registro é restrita por
  `usuario_id` (via FK + `JOIN`/`WHERE`), prevenindo IDOR entre contas
- Confirmação de e-mail: código de 6 dígitos, armazenado só como hash SHA-256 (nunca em texto
  plano), validade de 15 min, limite de tentativas e rate limit de reenvio; sem essa confirmação
  a conta não consegue logar
- Rate limit de cadastro (5 por hora por IP, hash do IP no banco) contra automação de contas em massa
- Papel admin: página administrativa responde 404 (não 403) pra quem não é admin, evitando revelar
  que a rota existe; painel mostra só dados agregados por conta, nunca o detalhe de um registro
- API offline (`api/registro.php`, `api/lembrete.php`) reusa a mesma validação e o mesmo escopo por
  `usuario_id` dos formulários clássicos; inserções são idempotentes por `client_uuid` (`UNIQUE`),
  então reenviar o mesmo item da fila offline nunca duplica dados
- Convites: token de 32 bytes aleatórios, armazenado só como hash SHA-256 no banco (nunca em
  texto plano), expira em 7 dias, uso único garantido por lock transacional (`SELECT ... FOR
  UPDATE`)
- Exclusão de conta exige reautenticação por senha antes de apagar os dados definitivamente
- Sessão: cookie `HttpOnly`, `SameSite=Strict`, `Secure` (atrás de proxy HTTPS)
- Apache: `ServerTokens Prod`, sem listagem de diretório, headers `CSP`/`X-Frame-Options`/
  `X-Content-Type-Options`/`Referrer-Policy`, bloqueio de arquivos sensíveis (`.env`, `.sql`, etc.)
- PHP: `expose_php=Off`, `display_errors=Off`, uploads desabilitados, limites de memória/execução
- Container do app: `read_only` filesystem, `cap_drop: ALL` (com apenas as 3 capabilities
  mínimas necessárias), `no-new-privileges`, sem privilégio de root persistente
- MySQL **sem porta exposta ao host** — acessível apenas pela rede Docker interna
- Senhas geradas aleatoriamente (`openssl rand`), armazenadas só em `.env` (gitignored, `chmod 600`)
- Limites de CPU/memória por container (`deploy.resources.limits`)

## Configuração de e-mail (convites)

Pra enviar convites por e-mail, defina no `.env` (raiz do projeto, gitignored):

```
SMTP_HOST=smtp.exemplo.com
SMTP_PORT=465
SMTP_SECURE=true
SMTP_USER=noreply@pitstop.morenadoaco.com.br
SMTP_PASS=...
SMTP_FROM=PitStop BR <noreply@pitstop.morenadoaco.com.br>
```

Sem essas variáveis, o convite continua sendo gerado no banco normalmente, mas o e-mail não é
enviado (fica registrado em log). O cliente SMTP é caseiro (sem dependências externas), suporta
TLS implícito (porta 465) ou STARTTLS (porta 587) com `AUTH LOGIN`.

## Como rodar

```bash
cd pitstop-br
docker compose up -d --build
```

App disponível em `http://127.0.0.1:8033` (atrás de proxy reverso Nginx + TLS em produção).

## Histórico de Versões

| Versão | Data       | Descrição                                                                 |
|--------|------------|-----------------------------------------------------------------------------|
| 1.6.3  | 2026-07-01 | Auto-reload quando o Service Worker troca de versão (`controllerchange`): antes, uma aba já aberta continuava presa na versão antiga até fechar tudo manualmente — agora correções futuras chegam sozinhas, sem intervenção do usuário |
| 1.6.2  | 2026-07-01 | Correção do layout "de PC" surgindo em celulares/PWA/APK: o breakpoint de sidebar (≥992px) passou a exigir `pointer: fine` (mouse), já que alguns WebViews reportam a largura de viewport errada pro CSS num toque; o `.container` também trava em 100% de largura fora do modo desktop-com-mouse como segunda camada de proteção |
| 1.6.1  | 2026-07-01 | Correção de bug crítico do modo offline: faltava `connect-src` no CSP, então o `fetch()` do Service Worker pro CDN (Bootstrap/ícones) era bloqueado depois do 1º login, derrubando a formatação de todo o app; grandfather de contas anteriores à confirmação de e-mail e ajuste do `padding-bottom` da bottom-nav vazando nas telas de login/cadastro |
| 1.6.0  | 2026-07-01 | Modo offline completo (Service Worker + fila IndexedDB com sincronização automática/Background Sync, API idempotente por `client_uuid`), reabertura do cadastro público com confirmação de e-mail por código de 6 dígitos (rate limit por IP), Painel Administrativo com dados agregados por conta (papel admin) e aviso de atualização com changelog simplificado nas primeiras aberturas após uma nova versão |
| 1.5.0  | 2026-07-01 | Categoria "Despesa" no registro (Seguro, IPVA, Estacionamento, Pedágio, Multa, Lavagem, Outro), lembretes de manutenção/documentos por km ou por data com alerta no painel principal, filtro de relatórios por período (data início/fim) e exportação em CSV ou PDF (impressão do navegador) |
| 1.4.0  | 2026-07-01 | Redesign visual com base em pesquisa de apps reais da categoria (Drivvo): correção definitiva do bug do botão de novo registro sobrepondo valores da lista (agora embutido na barra de navegação, não mais flutuante), paleta com duas cores (laranja + teal) e selos de ícone nas estatísticas, estados vazios com ícone/texto/CTA, sidebar de navegação compacta e grade de 2 colunas nos gráficos para telas ≥992px, barras dos gráficos com largura proporcional e emojis trocados por ícones Bootstrap Icons na página de instalação |
| 1.3.0  | 2026-06-30 | Redesign visual (cantos suaves, sombras com tom da marca, transições de toque/hover, entrada animada de página/listas, medidor (gauge) SVG animado com contagem progressiva do km/l, respeitando `prefers-reduced-motion`), app Android nativo (TWA assinado via Bubblewrap) com APK para download, Digital Asset Links (abre em tela cheia sem barra de URL) e página pública `/instalar.php` com instruções APK/PWA |
| 1.2.0  | 2026-06-30 | Registro por convite (token único por e-mail, SMTP próprio sem dependências), conformidade LGPD (política de privacidade, consentimento, exclusão de conta), combustível no abastecimento (Gasolina Comum/Aditivada, Etanol, Diesel, GNV, Outro), preço por litro calculado, página de Relatórios com gráficos (gasto por mês, km rodado, evolução do consumo) e reorganização da navegação (dropdown de conta + bottom-nav com Relatórios) |
| 1.1.0  | 2026-06-30 | Multi-usuário: cadastro/login/logout com lockout de tentativas, isolamento de dados por conta (correção de IDOR em exclusão/edição), edição de veículo e registro, identidade visual própria (logo, paleta, favicons) e manifest PWA |
| 1.0.0  | 2026-06-30 | Versão inicial: CRUD de veículos/registros, cálculo de km/l, hardening completo, deploy em produção com Nginx + Let's Encrypt em pitstop.morenadoaco.com.br |
