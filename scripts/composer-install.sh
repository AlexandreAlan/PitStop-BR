#!/usr/bin/env bash
# Instala as dependências PHP (Composer) em src/vendor/ usando a imagem
# oficial do Composer via Docker — sem exigir Composer instalado no host.
# Rodar uma vez após clonar o repo e sempre que composer.json mudar.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

docker run --rm -v "$DIR/src:/app" -w /app composer:2 install --no-dev --optimize-autoloader --no-interaction
