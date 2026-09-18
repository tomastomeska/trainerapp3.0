<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/surveys.php';
requireAthleteLogin();
$surveyUserRole = 'athlete';
$surveyUserId = (int)getCurrentAthleteId();
$surveyBackUrl = BASE_URL . '/athlete_dashboard.php';
require __DIR__ . '/includes/survey_page.php';