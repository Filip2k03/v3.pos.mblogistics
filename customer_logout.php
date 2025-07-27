<?php
// customer_logout.php - Handles customer logout.

session_start();
require_once 'includes/functions.php'; // For customer_redirect and customer_flash_message

// Unset all customer-specific session variables
unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
// You might want to keep other session data if it's shared, or destroy the whole session if not.
// For a clean customer logout, it's often best to destroy the session if it's ONLY for the customer portal.
// If staff and customer sessions can coexist, only unset customer-specific vars.
// For simplicity, let's assume separate logins mean separate session contexts, so we'll destroy.
session_destroy(); // Destroys the entire session

customer_flash_message('info', 'You have been logged out from the customer portal.');
customer_redirect('index.php?page=customer_login');
?>