-- Run as privileged DBA account, then remove broad privileges from app account.
-- Replace placeholders before execution.

-- 1) Create dedicated app user (if not exists)
CREATE USER IF NOT EXISTS 'trainerapp_app'@'%' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

-- 2) Grant ONLY required privileges on application DB
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
ON `trainerapp_v2_dev`.* TO 'trainerapp_app'@'%';

-- Optional: if app never creates/drops tables in runtime, remove CREATE/ALTER/DROP after migrations:
-- REVOKE CREATE, ALTER, DROP ON `trainerapp_v2_dev`.* FROM 'trainerapp_app'@'%';

-- 3) Remove dangerous global privileges (if any were granted before)
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'trainerapp_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
ON `trainerapp_v2_dev`.* TO 'trainerapp_app'@'%';

FLUSH PRIVILEGES;

-- 4) Verify
SHOW GRANTS FOR 'trainerapp_app'@'%';
