<?php
// Ang landing page ng site ay ang login.php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'homepage.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target, true, 302);
exit;