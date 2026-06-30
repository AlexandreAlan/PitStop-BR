# PitStop BR

[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Sistema web simples, focado em mobile, para registrar abastecimentos e manutenções de veículos e
acompanhar consumo (km/l) e gastos. Acesse em **https://pitstop.morenadoaco.com.br**.

## Funcionalidades

- Cadastro de veículos (nome + tipo: Moto/Carro/Outro)
- Registro de abastecimentos (km, litros, valor pago) e manutenções (km, valor, descrição)
- Cálculo automático da última média de consumo (km/l) a partir dos dois últimos abastecimentos
- Filtro de registros por veículo e total gasto no mês
- Exclusão de registros
- Interface mobile-first (Bootstrap 5 + Bootstrap Icons), botão flutuante (FAB) para novo registro

## Stack

- **Frontend:** HTML5 + Bootstrap 5 (CDN) + Bootstrap Icons
- **Backend:** PHP 8.2 puro (sem framework), Apache
- **Banco:** MySQL 8.0, acesso exclusivo via PDO (prepared statements)
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
    ├── config/
    │   ├── bootstrap.php   # sessão segura + carrega conexão/CSRF/funções
    │   ├── conexao.php     # PDO (lê credenciais do ambiente)
    │   └── csrf.php        # geração/validação de token CSRF
    ├── includes/
    │   ├── functions.php   # helpers (escape, flash, cálculo de consumo)
    │   ├── header.php
    │   └── footer.php
    ├── index.php           # dashboard (última média, gastos do mês, registros)
    ├── adicionar.php       # formulário + processamento de novo registro
    ├── excluir.php         # exclusão de registro (POST + CSRF)
    └── veiculos.php        # lista + cadastro de veículos
```

## Segurança aplicada

- 100% PDO com prepared statements (sem SQL cru/concatenado) — zero SQLi
- Proteção CSRF (token por sessão, `hash_equals`) em todo formulário POST
- Output sempre escapado (`htmlspecialchars`) — zero XSS refletido
- Validação estrita (whitelist) de tipo de registro, tipo de veículo, datas e números
- Sessão: cookie `HttpOnly`, `SameSite=Strict`, `Secure` (atrás de proxy HTTPS)
- Apache: `ServerTokens Prod`, sem listagem de diretório, headers `CSP`/`X-Frame-Options`/
  `X-Content-Type-Options`/`Referrer-Policy`, bloqueio de arquivos sensíveis (`.env`, `.sql`, etc.)
- PHP: `expose_php=Off`, `display_errors=Off`, uploads desabilitados, limites de memória/execução
- Container do app: `read_only` filesystem, `cap_drop: ALL` (com apenas as 3 capabilities
  mínimas necessárias), `no-new-privileges`, sem privilégio de root persistente
- MySQL **sem porta exposta ao host** — acessível apenas pela rede Docker interna
- Senhas geradas aleatoriamente (`openssl rand`), armazenadas só em `.env` (gitignored, `chmod 600`)
- Limites de CPU/memória por container (`deploy.resources.limits`)

## Como rodar

```bash
cd pitstop-br
docker compose up -d --build
```

App disponível em `http://127.0.0.1:8033` (atrás de proxy reverso Nginx + TLS em produção).

## Histórico de Versões

| Versão | Data       | Descrição                                                                 |
|--------|------------|-----------------------------------------------------------------------------|
| 1.0.0  | 2026-06-30 | Versão inicial: CRUD de veículos/registros, cálculo de km/l, hardening completo, deploy em produção com Nginx + Let's Encrypt em pitstop.morenadoaco.com.br |
