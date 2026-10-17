
#!/bin/sh
set -eu

# Require credentials without printing their values.
: "${FLUX_USERNAME:?Missing FLUX_USERNAME}"
: "${FLUX_LICENSE_KEY:?Missing FLUX_LICENSE_KEY}"

# Authenticate Composer before installing private packages.
composer config --global http-basic.composer.fluxui.dev \
  "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"

# Install PHP dependencies.
composer install --optimize-autoloader --no-scripts --no-interaction

# Build frontend assets.
npm install
npm run build
