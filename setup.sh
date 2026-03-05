#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

RESET="${1:-}"

echo "==> Starting DDEV…"
ddev start

echo "==> Installing drush…"
ddev composer require drush/drush

if [[ "$RESET" == "--reset" ]]; then
  echo "==> Dropping DB (reset)…"
  ddev drush sql:drop -y || true
  echo "==> Installing Drupal…"
  ddev drush site:install -y --account-name=admin --account-pass=admin
elif ! ddev drush core:status --field=db-status 2>/dev/null | grep -q "Connected"; then
  echo "==> Installing Drupal…"
  ddev drush site:install -y --account-name=admin --account-pass=admin
fi

echo "==> Matching site UUID to config/sync…"
UUID="$(grep -E '^uuid:' config/sync/system.site.yml | awk '{print $2}')"
ddev drush cset system.site uuid "$UUID" -y
ddev drush cr

echo "==> Removing shortcut entities blocking import…"
ddev drush php:eval '\Drupal::entityTypeManager()->getStorage("shortcut")->delete(\Drupal::entityTypeManager()->getStorage("shortcut")->loadMultiple());'
ddev drush php:eval '$s=\Drupal::entityTypeManager()->getStorage("shortcut_set")->load("default"); if ($s) { $s->delete(); }'

echo "==> Importing config…"
ddev drush cim -y

echo "==> Done."
echo "Login: admin / admin"
