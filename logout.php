<?php
// logout.php

session_start();
require_once 'includes/functions.php'; // For redirect and flash_message

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

flash_message('info', 'You have been logged out.');
redirect('index.php?page=login');
?>