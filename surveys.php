<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/surveys.php';
requireLogin();
$surveyUserRole = 'coach';
$surveyUserId = (int)getCurrentCoachId();
$surveyBackUrl = BASE_URL . '/profile.php';
require __DIR__ . '/includes/survey_page.php';