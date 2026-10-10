#!/bin/sh
# Render (and most PaaS providers) inject the port at runtime, so Apache has to
# be re-bound on every container start rather than baked in at build time.
set -e

PORT="${PORT:-10000}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "ReadPilot starting on port ${PORT}"

exec apache2-foreground