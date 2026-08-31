#!/bin/sh
set -eu

# EPPITNIC_CONFIG_DIR and EPPITNIC_VAR_DIR are fixed by the image (see the
# Dockerfile's ENV) -- what varies per instance is the host side of the /data
# bind mount, not these two paths. Neither directory exists on a mount's
# first use, and the www-data user everything below runs as cannot create
# them itself if Docker handed the volume over root-owned; done once, here,
# as whatever user started the container (root, ordinarily).
mkdir -p "$EPPITNIC_CONFIG_DIR" "$EPPITNIC_VAR_DIR"
chown -R www-data:www-data "$EPPITNIC_CONFIG_DIR" "$EPPITNIC_VAR_DIR"

exec "$@"
