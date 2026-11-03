CREATE TABLE coaches (id INT PRIMARY KEY);
CREATE TABLE athletes (id INT PRIMARY KEY, coach_id INT NOT NULL, first_name VARCHAR(80), last_name VARCHAR(80), birth_date DATE);
CREATE TABLE app_settings (`key` VARCHAR(120) PRIMARY KEY, `value` TEXT);
CREATE TABLE mycoach_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, athlete_id INT NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL, FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
);
CREATE TABLE training_sessions (
    id BIGINT UNSIGNED PRIMARY KEY, athlete_id INT, started_at DATETIME, completed_at DATETIME,
    deleted_by_coach_at DATETIME, duration_centiseconds INT
);
CREATE TABLE mycoach_daily_questionnaires (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
    entry_date DATE, workout_id BIGINT UNSIGNED NULL, sleep_hours DECIMAL(4,2) NULL,
    feeling_score TINYINT UNSIGNED NULL, energy_score TINYINT UNSIGNED NULL,
    motivation_score TINYINT UNSIGNED NULL, muscle_pain_score TINYINT UNSIGNED NULL,
    joint_pain_score TINYINT UNSIGNED NULL, rpe_score TINYINT UNSIGNED NULL,
    training_duration_minutes INT NULL, workout_meta_json JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES mycoach_users(id) ON DELETE CASCADE,
    FOREIGN KEY (workout_id) REFERENCES training_sessions(id) ON DELETE SET NULL
);
CREATE TABLE athlete_weight_logs (id INT PRIMARY KEY, athlete_id INT, measured_at DATE, weight_kg DECIMAL(6,2));
CREATE TABLE food_diary_days (id INT PRIMARY KEY, athlete_id INT, date DATE, hydration_ml INT NULL);
CREATE TABLE food_diary_hydration_entries (id INT PRIMARY KEY, day_id INT, drink_type VARCHAR(30), amount_ml INT);
CREATE TABLE food_diary_custom_activities (id INT PRIMARY KEY, day_id INT, activity_type VARCHAR(30), activity_name VARCHAR(100), duration_minutes INT);
CREATE TABLE coach_calendar_events (
    id INT PRIMARY KEY, athlete_id INT, second_athlete_id INT NULL, approval_status VARCHAR(20),
    title_type VARCHAR(30), starts_at DATETIME, ends_at DATETIME
);
CREATE TABLE online_trainings (
    id INT PRIMARY KEY, athlete_id INT, title VARCHAR(100), status VARCHAR(30),
    started_at DATETIME NULL, completed_at DATETIME NULL, duration_seconds INT NULL
);
CREATE TABLE run_outdoor_sessions (session_id BIGINT UNSIGNED PRIMARY KEY, rpe TINYINT);
CREATE TABLE session_series (
    id INT PRIMARY KEY, session_id BIGINT UNSIGNED, exercise_id INT, weight DECIMAL(7,2),
    equipment_weight DECIMAL(7,2), reps INT, assistance_reps INT NULL
);
CREATE TABLE training_session_exercises (session_id BIGINT UNSIGNED, exercise_id INT, exercise_name VARCHAR(100), is_timed INT);
CREATE TABLE online_training_exercises (id INT PRIMARY KEY, online_training_id INT, exercise_id INT, exercise_name VARCHAR(100), is_timed INT);
CREATE TABLE online_training_series (id INT PRIMARY KEY, online_training_exercise_id INT, prescribed_equipment_weight DECIMAL(7,2));
CREATE TABLE online_training_results (
    id INT PRIMARY KEY, online_training_id INT, online_training_series_id INT,
    actual_weight DECIMAL(7,2), actual_reps INT, actual_equipment_weight DECIMAL(7,2) NULL
);
CREATE TABLE mycoach_recovery_entries (
    id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED, entry_date DATE,
    recovery_type VARCHAR(30), duration_minutes INT, updated_at DATETIME
);
CREATE TABLE mycoach_hyrox_standards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, discipline_key VARCHAR(120), discipline_name VARCHAR(190),
    version_label VARCHAR(60), distance_or_reps VARCHAR(120), is_active TINYINT, valid_from DATE, valid_to DATE
);
CREATE TABLE mycoach_hyrox_standard_weights (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, standard_id BIGINT UNSIGNED, division VARCHAR(30),
    gender_category VARCHAR(30), weight_label VARCHAR(120), weight_kg DECIMAL(6,2), sort_order INT
);
INSERT INTO coaches VALUES (10), (20);
INSERT INTO athletes VALUES (6,10,'Test','Athlete','1990-01-01'), (7,20,'Other','Athlete','1992-01-01'), (8,10,'Junior','Athlete','2020-01-01');
INSERT INTO mycoach_users (id,athlete_id,role) VALUES (60,6,'athlete'), (70,7,'athlete');
INSERT INTO training_sessions VALUES
    (1,6,'2026-02-28 09:00:00','2026-02-28 10:00:00',NULL,360000),
    (2,6,'2026-02-28 11:00:00',NULL,NULL,360000),
    (3,6,'2026-02-28 12:00:00','2026-02-28 13:00:00','2026-02-28 14:00:00',360000),
    (4,7,'2026-02-28 09:00:00','2026-02-28 10:00:00',NULL,360000),
    (5,6,'2026-02-27 09:00:00','2026-02-27 10:00:00',NULL,NULL);
INSERT INTO mycoach_daily_questionnaires (id,user_id,entry_date,workout_id,rpe_score,training_duration_minutes)
    VALUES (1,60,'2026-02-28',1,5,60), (2,60,'2026-02-28',NULL,NULL,NULL);
INSERT INTO mycoach_recovery_entries VALUES (1,60,'2026-02-28','sleep',480,'2026-02-28 08:00:00');
INSERT INTO athlete_weight_logs VALUES (1,6,'2026-02-20',80), (2,6,'2026-03-01',79), (3,7,'2026-02-28',90);
INSERT INTO food_diary_days VALUES (1,6,'2026-02-28',5000), (2,6,'2026-02-27',1200), (3,7,'2026-02-28',3000);
INSERT INTO food_diary_hydration_entries VALUES (1,1,'water',500), (2,1,'beer',400), (3,3,'water',3000);
INSERT INTO food_diary_custom_activities VALUES (1,1,'training','Same workout',60), (2,2,'run','Previous day',20);
INSERT INTO online_trainings VALUES
    (1,6,'Midnight','completed','2026-02-28 23:00:00','2026-03-01 01:00:00',7200),
    (2,6,'Not finished','in_progress','2026-02-28 09:00:00',NULL,3600),
    (3,7,'Other athlete','completed','2026-02-28 09:00:00','2026-02-28 10:00:00',3600);
INSERT INTO run_outdoor_sessions VALUES (1,6);
INSERT INTO coach_calendar_events VALUES (1,6,NULL,'approved','training','2026-03-01 09:00:00','2026-03-01 10:00:00');
INSERT INTO training_session_exercises VALUES (1,1,'Squat',0), (1,2,'Timed',1), (1,3,'Distance',2), (4,1,'Squat',0);
INSERT INTO session_series VALUES
    (1,1,1,80,20,5,0), (2,1,1,80,20,5,1), (3,1,2,80,20,5,0), (4,1,3,80,20,5,0), (5,4,1,80,20,5,0);
INSERT INTO online_training_exercises VALUES (1,1,1,'Squat',0), (2,3,1,'Squat',0);
INSERT INTO online_training_series VALUES (1,1,20), (2,1,20), (3,2,20);
INSERT INTO online_training_results VALUES (1,1,1,80,5,NULL), (2,1,2,80,5,20), (3,3,3,80,5,20);
