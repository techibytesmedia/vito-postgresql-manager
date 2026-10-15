#!/usr/bin/env bash
# Only for the disposable chroot created by lab.sh.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
if [ ! -e /.vpgm-provisioned ]; then
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg sudo python3 openssh-server locales mariadb-server
  install -d /usr/share/postgresql-common/pgdg
  curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
  echo 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt noble-pgdg main' > /etc/apt/sources.list.d/pgdg.list
  apt-get update -qq
  apt-get install -y -qq postgresql-18
  pg_conftool 18 main set port 55487
  useradd -m -s /bin/bash vito
  echo 'vito ALL=(ALL) NOPASSWD: ALL' > /etc/sudoers.d/vpgm-lab
  chmod 440 /etc/sudoers.d/vpgm-lab
  touch /.vpgm-provisioned
fi
install -d -m 700 -o vito -g vito /home/vito/.ssh
install -m 600 -o vito -g vito /workspace/.work/lab-key.pub /home/vito/.ssh/authorized_keys
mkdir -p /run/sshd /run/mysqld /var/run/postgresql
chown mysql:mysql /run/mysqld
chown postgres:postgres /var/run/postgresql
pg_ctlcluster --skip-systemctl-redirect 18 main start
su -s /bin/sh mysql -c 'mariadbd --skip-networking --socket=/run/mysqld/mysqld.sock --pid-file=/run/mysqld/mysqld.pid --log-error=/var/lib/mysql/lab-error.log &'
ssh-keygen -A
/usr/sbin/sshd -p 22287 -o PasswordAuthentication=no -o PermitRootLogin=no -o AllowUsers=vito -o PidFile=/run/sshd-vpgm.pid
for i in $(seq 1 30); do mariadb-admin ping --silent && break; sleep 1; done
pg_lsclusters
mariadb -N -e 'SELECT VERSION()'
