#!/usr/bin/env bash
#
# Instala ou atualiza o plugin VMO direto do GitHub, no servidor do GLPI (não depende de nenhum computador pessoal).
#
#   curl -fsSL https://raw.githubusercontent.com/evandroevangelista77/VMO-OAK/main/tools/instalar-no-servidor.sh | sudo bash
#   sudo GLPI=/caminho/do/glpi bash tools/instalar-no-servidor.sh        # GLPI fora de /var/www/glpi
#
# O repositório é público: não precisa de token. Faz: baixa a versão mais nova, copia para <GLPI>/plugins/vmo,
# ajusta o dono para o servidor web, roda plugin:install (migrações idempotentes) e plugin:activate, e limpa o cache.
set -euo pipefail

GLPI="${GLPI:-/var/www/glpi}"
REPO="${REPO:-evandroevangelista77/VMO-OAK}"
BRANCH="${BRANCH:-main}"
WEB_USER="${WEB_USER:-www-data}"
GLPI_USER="${GLPI_USER:-glpi}"

[ "$(id -u)" -eq 0 ] || { echo "Rode com sudo." >&2; exit 1; }
[ -f "$GLPI/bin/console" ] || { echo "GLPI não encontrado em $GLPI (use GLPI=/caminho)." >&2; exit 1; }

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
echo "Baixando $REPO@$BRANCH ..."
if command -v git >/dev/null 2>&1; then
  git -c http.version=HTTP/1.1 clone --quiet --depth 1 --branch "$BRANCH" "https://github.com/$REPO.git" "$tmp/src"
else
  mkdir -p "$tmp/src"
  curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" | tar -xz --strip-components=1 -C "$tmp/src"
fi
[ -f "$tmp/src/setup.php" ] || { echo "setup.php não encontrado no download." >&2; exit 1; }

nova="$(grep -oE "PLUGIN_VMO_VERSION', *'[^']+'" "$tmp/src/setup.php" | sed -E "s/.*'([^']+)'$/\1/")"
velha="$(grep -oE "PLUGIN_VMO_VERSION', *'[^']+'" "$GLPI/plugins/vmo/setup.php" 2>/dev/null | sed -E "s/.*'([^']+)'$/\1/" || true)"
echo "Versão instalada: ${velha:-nenhuma} → versão nova: $nova"

dest="$GLPI/plugins/vmo"
rm -rf "$dest.novo"; mkdir -p "$dest.novo"
tar -C "$tmp/src" --exclude=.git -cf - . | tar -C "$dest.novo" -xf -
rm -rf "$dest"; mv "$dest.novo" "$dest"
chown -R "$WEB_USER":"$WEB_USER" "$dest"

cd "$GLPI"
sudo -u "$WEB_USER" php bin/console plugin:install vmo --username="$GLPI_USER" -n || true
sudo -u "$WEB_USER" php bin/console plugin:activate vmo -n || true
sudo -u "$WEB_USER" php bin/console cache:clear -n >/dev/null 2>&1 || true
sudo -u "$WEB_USER" php bin/console plugin:list 2>/dev/null | grep -i vmo || true
echo "Pronto."
