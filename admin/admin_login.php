<?php
session_start();

$error = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_error']);

if (!empty($_GET['timeout']) && $error === '') {
    $error = 'Your session expired. Please sign in again.';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pawfinder - Admin Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/index.css">
</head>

<body>

<div class="login-box">
    <div class="card">
        <div class="card-body p-4 p-sm-5">

            <div class="text-center mb-4">
                <div class="d-inline-block p-2 px-3 rounded-pill bg-danger bg-opacity-10 text-danger fw-bold mb-2 small">
                     Administrator Access
                </div>

                <h2 class="fw-bold text-dark mb-1">Pawfinder Admin</h2>

                <p class="text-muted small mb-0">
                    Authorized personnel only
                </p>
            </div>

            <div class="mb-3">
                <h5 class="fw-bold text-dark mb-1">Admin Sign In</h5>
                <p class="text-muted small mb-0">
                    Enter your administrator credentials.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger small py-2">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="admin_login_process.php" method="POST">

                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold small text-secondary">
                        Admin Username or Email
                    </label>
                    <input type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Enter admin username or email"
                        required
                        autocomplete="username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold small text-secondary">
                        Password
                    </label>
                    <input type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password">
                </div>

                <button class="btn btn-primary w-100 shadow-sm mb-3" type="submit">
                    Sign In as Admin
                </button>

            </form>

            <div class="text-center mt-3">
                <a href="../login.php" class="small text-decoration-none text-secondary">
                    ← Back to User Login
                </a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>