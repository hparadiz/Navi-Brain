#!/bin/sh
# Install the looking service. Everything here needs root; nothing else does.
#
#   kdesu -c "sh /home/akujin/Sources/Navi-Brain/packaging/install-navi-senses.sh"
#
# What this sets up, and why it is shaped this way:
#
# Navi can look at the machine but cannot change it. That is not enforced by a
# list of forbidden commands in application code — such a list has to anticipate
# every spelling of a destructive command and is wrong the first time it misses
# one. It is enforced by the kernel: the process that runs commands is a
# separate daemon owned by an account with no write permission, reached over a
# unix socket. The brain cannot execute anything even if it decided to, because
# the code that executes lives in another process with less authority.
#
# This is the ordinary Unix arrangement, the same one OpenSSH, Postfix and
# Postgres use. Granting Navi a capability later is a permissions change on one
# account rather than an edit to a regular expression.
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

# 1. The account. A system user with no login, no home to write into, and one
#    supplementary group: `users`, which is read and traverse on akujin's home
#    because that home is mode 0755. Read access, no write bit.
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
