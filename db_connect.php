<?php
// db_connect.php
require_once 'config.php'; // Ensure config is loaded first to get DB credentials

// Establish the database connection
$connection = mysqli_connect($hostname, $username, $password, $database);

// Check connection
if (!$connection) {
    die("Connection failed: " . mysqli_connect_error());
}