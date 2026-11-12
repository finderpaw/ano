<?php
// Database settings
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // default XAMPP/WAMP
define('DB_PASS', '');            // leave empty for XAMPP
define('DB_NAME', 'pawfinder_db');

// Connect to database
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>