<?php
require_once __DIR__ . '/auth.php';
redirect(current_user() ? 'dashboard.php' : 'login.php');
