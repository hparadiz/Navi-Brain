#!/bin/sh
# Put the brain's own daemons under OpenRC and restart them cleanly.
#
# The sensory daemon has an init script that was never installed, which is why
# it kept being started by hand and why duplicate copies ended up racing each
# other on the same database. A supervised service has one instance by
# construction.
set -eu

SRC_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root" >&2
    exit 1
fi

# Any copies started by hand. The supervisor cannot adopt them, and leaving
# them running means two processes ingesting the same feeds.
pkill -f 'bin/navi-brain-senses' 2>/dev/null || true

install -o root -g root -m 0755 "$SRC_DIR/packaging/openrc/navi-brain-senses" /etc/init.d/navi-brain-senses
rc-update add navi-brain-senses default >/dev/null 2>&1 || true
rc-service navi-brain-senses restart

# Workers probe before claiming, so bring their two-slot local endpoint up
# before either queue consumer starts polling.
install -o root -g root -m 0755 "$SRC_DIR/packaging/openrc/navi-brain-local-model" /etc/init.d/navi-brain-local-model
rc-update add navi-brain-local-model default >/dev/null 2>&1 || true
rc-service navi-brain-local-model restart

install -o root -g root -m 0755 "$SRC_DIR/packaging/openrc/navi-brain-model-worker" /etc/init.d/navi-brain-model-worker
rc-update add navi-brain-model-worker default >/dev/null 2>&1 || true
rc-service navi-brain-model-worker restart

install -o root -g root -m 0755 "$SRC_DIR/packaging/openrc/navi-brain-model-worker-2" /etc/init.d/navi-brain-model-worker-2
rc-update add navi-brain-model-worker-2 default >/dev/null 2>&1 || true
rc-service navi-brain-model-worker-2 restart

# The heartbeat's supervisor holds a lock owned by root, so a restart has to
# come from here too.
rc-service navi-brain-heartbeat restart

echo
echo "--- status ---"
for s in navi-senses navi-brain-senses navi-brain-model-worker navi-brain-model-worker-2 navi-brain-heartbeat navi-brain-local-model; do
    printf '%-24s %s\n' "$s" "$(rc-service "$s" status 2>&1 | sed 's/^ \* status: //' | head -1)"
done
echo
echo "sensory daemon instances: $(pgrep -fc 'bin/navi-brain-senses' 2>/dev/null || echo 0)"
