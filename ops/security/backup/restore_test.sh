#!/usr/bin/env bash
set -euo pipefail

# Required env vars:
# DB_HOST, DB_PORT, DB_USER, DB_PASS
# TEST_DB_NAME, SOURCE_DB_DUMP_GZ, SOURCE_UPLOADS_TGZ, RESTORE_TMP_DIR

: "${DB_HOST:?Missing DB_HOST}"
: "${DB_PORT:?Missing DB_PORT}"
: "${DB_USER:?Missing DB_USER}"
: "${DB_PASS:?Missing DB_PASS}"
: "${TEST_DB_NAME:?Missing TEST_DB_NAME}"
: "${SOURCE_DB_DUMP_GZ:?Missing SOURCE_DB_DUMP_GZ}"
: "${SOURCE_UPLOADS_TGZ:?Missing SOURCE_UPLOADS_TGZ}"
: "${RESTORE_TMP_DIR:?Missing RESTORE_TMP_DIR}"

mkdir -p "${RESTORE_TMP_DIR}"

echo "[INFO] Creating clean test database ${TEST_DB_NAME}"
export MYSQL_PWD="${DB_PASS}"
mysql --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" -e "DROP DATABASE IF EXISTS \\`${TEST_DB_NAME}\\`; CREATE DATABASE \\`${TEST_DB_NAME}\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "[INFO] Restoring DB dump"
gunzip -c "${SOURCE_DB_DUMP_GZ}" | mysql --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" "${TEST_DB_NAME}"

echo "[INFO] Verifying key tables"
mysql --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" "${TEST_DB_NAME}" -e "SELECT COUNT(*) AS c FROM superadmins; SELECT COUNT(*) AS c FROM coaches; SELECT COUNT(*) AS c FROM athletes;"
unset MYSQL_PWD

echo "[INFO] Verifying uploads archive"
mkdir -p "${RESTORE_TMP_DIR}/uploads_test"
tar -xzf "${SOURCE_UPLOADS_TGZ}" -C "${RESTORE_TMP_DIR}/uploads_test"
find "${RESTORE_TMP_DIR}/uploads_test" -maxdepth 3 -type f | head -n 10

echo "[INFO] Restore test succeeded"
