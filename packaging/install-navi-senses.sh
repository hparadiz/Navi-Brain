#!/bin/sh
# Install the looking service. Everything here needs root; nothing else does.
#
#   kdesu -c "sh /home/akujin/Sources/Navi-Brain/packaging/install-navi-senses.sh"
#
# What this sets up, and why it is shaped this way:
#
# The service accepts named observations over a Unix socket and reads only
# fixed kernel information files. It never executes caller-supplied commands
# or paths. Its separate account adds containment; account permissions alone
# do not establish a read-only sandbox. New observations require installed code.
set -eu

SRC_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
USER_NAME=navi-senses
OWNER=akujin
OWNER_GROUP=users
LIBEXEC=/usr/local/libexec/navi-senses
INIT_SCRIPT=/etc/init.d/navi-senses

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root, e.g. kdesu -c \"sh $0\"" >&2
    exit 1
fi

# 1. The account. A system user with no login or created home. The users group
#    permits socket access; it is not a filesystem-wide prohibition on writes.
if getent passwd "$USER_NAME" >/dev/null 2>&1; then
    echo "user $USER_NAME already exists"
else
    useradd --system --no-create-home --home-dir /var/empty \
        --shell /sbin/nologin --comment "Navi looking service" \
        --user-group "$USER_NAME"
    echo "created user $USER_NAME"
fi
usermod -G "$OWNER_GROUP" "$USER_NAME"
echo "read access granted via group $OWNER_GROUP"

# 2. The executable, owned by root outside any user-writable directory. If
#    akujin could edit this file, compromising akujin would decide what
#    navi-senses runs and the separation would be decorative.
install -D -o root -g root -m 0755 "$SRC_DIR/bin/navi-senses" "$LIBEXEC"
echo "installed $LIBEXEC"

# 3. Runtime and logs.
install -d -o "$USER_NAME" -g "$OWNER_GROUP" -m 0750 /run/navi-senses
install -o "$USER_NAME" -g root -m 0640 /dev/null /var/log/navi-senses.log 2>/dev/null || true
install -o "$USER_NAME" -g root -m 0640 /dev/null /var/log/navi-senses.err 2>/dev/null || true

# 4. The service.
install -o root -g root -m 0755 "$SRC_DIR/packaging/openrc/navi-senses" "$INIT_SCRIPT"
rc-update add navi-senses default >/dev/null 2>&1 || true
rc-service navi-senses restart

echo
echo "--- checks ---"
echo -n "runs as:      "; ps -o user= -C navi-senses 2>/dev/null | head -1 || echo "(check rc-service navi-senses status)"
echo -n "can read:     "; su -s /bin/sh -c "ls /home/$OWNER >/dev/null 2>&1 && echo yes || echo NO" "$USER_NAME"
echo -n "can write:    "; su -s /bin/sh -c "touch /home/$OWNER/.navi-write-probe 2>/dev/null && echo 'YES - WRONG' || echo 'no (correct)'" "$USER_NAME"
rm -f "/home/$OWNER/.navi-write-probe"
echo -n "socket:       "; ls -l /run/navi-senses/navi-senses.sock 2>/dev/null || echo "(not up yet)"
