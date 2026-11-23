#!/usr/bin/env bash
# Atualiza o VMO a partir do GitHub, direto no servidor do GLPI (nada depende de um Mac).
# Rode NO SERVIDOR:   sudo bash tools/deploy.sh
exec bash "$(dirname "$0")/instalar-no-servidor.sh" "$@"
