#!/usr/bin/env bash
set -euo pipefail

# Required env vars:
# APP_NAME, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
# APP_ROOT, UPLOADS_DIR, BACKUP_DIR, OFFSITE_RCLONE_REMOTE
# OFFSITE_PREFIX (e.g. trainerapp/prod)

: "${APP_NAME:?Missing APP_NAME}"
: "${DB_HOST:?Missing DB_HOST}"
: "${DB_PORT:?Missing DB_PORT}"
: "${DB_NAME:?Missing DB_NAME}"
: "${DB_USER:?Missing DB_USER}"
: "${DB_PASS:?Missing DB_PASS}"
: "${APP_ROOT:?Missing APP_ROOT}"
: "${UPLOADS_DIR:?Missing UPLOADS_DIR}"
: "${BACKUP_DIR:?Missing BACKUP_DIR}"
: "${OFFSITE_RCLONE_REMOTE:?Missing OFFSITE_RCLONE_REMOTE}"
: "${OFFSITE_PREFIX:?Missing OFFSITE_PREFIX}"

STAMP="$(date +%F_%H%M%S)"
WORK_DIR="${BACKUP_DIR}/work/${STAMP}"
ARCHIVE_DIR="${BACKUP_DIR}/archives"
LOG_DIR="${BACKUP_DIR}/logs"
mkdir -p "${WORK_DIR}" "${ARCHIVE_DIR}" "${LOG_DIR}"

DB_DUMP="${WORK_DIR}/${APP_NAME}_${DB_NAME}_${STAMP}.sql"
DB_GZ="${ARCHIVE_DIR}/${APP_NAME}_db_${STAMP}.sql.gz"
UPLOADS_TGZ="${ARCHIVE_DIR}/${APP_NAME}_uploads_${STAMP}.tar.gz"

export MYSQL_PWD="${DB_PASS}"
mysqldump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --user="${DB_USER}" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --set-gtid-purged=OFF \
  "${DB_NAME}" > "${DB_DUMP}"
unset MYSQL_PWD

gzip -c "${DB_DUMP}" > "${DB_GZ}"
tar -C "${UPLOADS_DIR}" -czf "${UPLOADS_TGZ}" .

sha256sum "${DB_GZ}" "${UPLOADS_TGZ}" > "${ARCHIVE_DIR}/${APP_NAME}_${STAMP}.sha256"

# Upload to offsite remote (Backblaze/S3/Wasabi/etc.)
rclone copy "${DB_GZ}" "${OFFSITE_RCLONE_REMOTE}:${OFFSITE_PREFIX}/db/"
rclone copy "${UPLOADS_TGZ}" "${OFFSITE_RCLONE_REMOTE}:${OFFSITE_PREFIX}/uploads/"
rclone copy "${ARCHIVE_DIR}/${APP_NAME}_${STAMP}.sha256" "${OFFSITE_RCLONE_REMOTE}:${OFFSITE_PREFIX}/checksums/"

# Keep local 14 days, remote 90 days (adjust as needed)
find "${ARCHIVE_DIR}" -type f -mtime +14 -delete
rclone delete --min-age 90d "${OFFSITE_RCLONE_REMOTE}:${OFFSITE_PREFIX}/db/"
rclone delete --min-age 90d "${OFFSITE_RCLONE_REMOTE}:${OFFSITE_PREFIX}/uploads/"
rclone delete --min-age 90d "${OFFSITE_RCLONE_REMOTE}:${OFFSITE_PREFIX}/checksums/"

echo "[$(date -Is)] backup finished" >> "${LOG_DIR}/backup.log"
rm -rf "${WORK_DIR}"
