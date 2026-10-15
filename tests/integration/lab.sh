#!/usr/bin/env bash
# Run as root in WSL/Linux. Creates a disposable chroot under /tmp only.
# It does not install packages into the host or change the host's services.
set -euo pipefail
workspace="$(cd "$(dirname "$0")/../.." && pwd)"
lab="${VPGM_LAB_ROOT:-/tmp/vpgm-lab}"
case "$lab" in /tmp/vpgm-lab*) ;; *) echo 'Lab must be under /tmp/vpgm-lab*'; exit 1 ;; esac
if [ "${1:-}" != inside ]; then
  mkdir -p "$lab"
  if [ ! -e "$lab/.base" ]; then
    curl -fsSL https://cdimage.ubuntu.com/ubuntu-base/releases/24.04/release/ubuntu-base-24.04.4-base-amd64.tar.gz -o "$lab/base.tar.gz"
    curl -fsSL https://cdimage.ubuntu.com/ubuntu-base/releases/24.04/release/SHA256SUMS -o "$lab/SHA256SUMS"
    (cd "$lab"; grep 'ubuntu-base-24.04.4-base-amd64.tar.gz$' SHA256SUMS | sed 's/ubuntu-base-24.04.4-base-amd64.tar.gz/base.tar.gz/' | sha256sum -c -)
    mkdir -p "$lab/root"
    tar -xzf "$lab/base.tar.gz" -C "$lab/root"
    touch "$lab/.base"
  fi
  exec unshare --mount --pid --fork --mount-proc bash "$0" inside
fi
mount --make-rprivate /
root="$lab/root"
mkdir -p "$root/proc" "$root/dev" "$root/workspace" "$root/run/sshd"
mount -t proc proc "$root/proc"
mount --rbind /dev "$root/dev"
mount --make-rslave "$root/dev"
mount --bind "$workspace" "$root/workspace"
cp /etc/resolv.conf "$root/etc/resolv.conf"
printf '#!/bin/sh\nexit 101\n' > "$root/usr/sbin/policy-rc.d"
chmod +x "$root/usr/sbin/policy-rc.d"
chroot "$root" /bin/bash /workspace/tests/integration/setup-managed.sh
hostname -I | awk '{print $1}' > "$workspace/.work/lab-ip"
echo 'LAB READY: SSH port 22287, PostgreSQL 18/main port 55487, MariaDB local socket.'
echo 'Terminate this process after tests; all lab services then stop with its PID namespace.'
trap 'exit 0' TERM INT
while true; do sleep 30 & wait $!; done
