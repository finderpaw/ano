<?php
session_start();

$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';

// Itinatakda ng login_process.php kapag admin account ang nag-login dito
$admin_blocked = !empty($_SESSION['admin_blocked']);

unset($_SESSION['error'], $_SESSION['success'], $_SESSION['admin_blocked']);

$pet_id = filter_input(INPUT_GET, 'pet_id', FILTER_VALIDATE_INT) ?: 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pawfinder - Account Login</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/index.css">
</head>

<body>

<div class="login-box">
    <div class="card">
        <div class="card-body p-4 p-sm-5">

            <div class="text-center mb-4">
                <div class="d-inline-block p-2 px-3 rounded-pill bg-primary bg-opacity-10 text-primary fw-bold mb-2 small">
                    🐾 Mabalacat Community Portal
                </div>

                <h2 class="fw-bold text-dark mb-1">Pawfinder</h2>

                <p class="text-muted small mb-0">
                    Pet Adoption, Emergency Rescue & Medical Services
                </p>
            </div>

            <div class="row text-center mb-4 g-2">

                <div class="col-3">
                    <div class="p-2 service-badge">
                        <span class="d-block fs-5">🐶</span>
                        <span class="text-dark d-block mt-1" style="font-size:11px;font-weight:600;">Adoption</span>
                    </div>
                </div>

                <div class="col-3">
                    <div class="p-2 service-badge">
                        <span class="d-block fs-5">🚨</span>
                        <span class="text-dark d-block mt-1" style="font-size:11px;font-weight:600;">Rescue</span>
                    </div>
                </div>

                <div class="col-3">
                    <div class="p-2 service-badge">
                        <span class="d-block fs-5">🔍</span>
                        <span class="text-dark d-block mt-1" style="font-size:11px;font-weight:600;">Missing</span>
                    </div>
                </div>

                <div class="col-3">
                    <div class="p-2 service-badge">
                        <span class="d-block fs-5">💉</span>
                        <span class="text-dark d-block mt-1" style="font-size:11px;font-weight:600;">Medical</span>
                    </div>
                </div>

            </div>

            <div class="mb-3">
                <h5 class="fw-bold text-dark mb-1">Sign In to Pawfinder</h5>
                <?php if ($error === '' && !$admin_blocked): ?>
                    <p class="text-muted small mb-0">
                        Welcome back! Please enter your credentials.
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success small py-2">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger small py-2">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST">

                <input type="hidden" name="pet_id"
                    value="<?= htmlspecialchars((string)$pet_id) ?>">

                <div class="mb-3">
                    <label for="username" class="form-label fw-semibold small text-secondary">
                        Username or Email
                    </label>

                    <input type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Enter your username or email"
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
                    Sign In
                </button>

            </form>

            <div class="text-center text-muted my-3 small position-relative">
                <span class="bg-white px-3 position-relative z-1">
                    OR
                </span>
                <hr class="position-absolute top-50 start-0 end-0 m-0 z-0 opacity-25">
            </div>

            <div class="mb-2">
                <a href="homepage.php"
                    class="btn btn-outline-secondary w-100 py-2 fw-semibold small text-decoration-none rounded-3">
                    👁️ View as Guest (Public Landing Page)
                </a>
            </div>

            <div class="text-center mt-3">
                <a href="homepage.php" class="small text-decoration-none text-secondary">
                    ← Back to Homepage
                </a>
            </div>

            <div class="text-center mt-4">
                <span class="small text-muted">Don't have an account?</span>

                <a href="register.php"
                    class="small fw-bold text-decoration-none text-primary ms-1">
                    Create Account
                </a>
            </div>

            <div class="text-center mt-3 pt-3 border-top">
                <a href="admin/admin_login.php" class="small text-decoration-none text-secondary">
                    🔒 Admin? Sign in here
                </a>
            </div>

        </div>
    </div>
</div>

<!-- Popup: admin account sa user login -->
<div class="modal fade" id="adminBlockedModal" tabindex="-1" aria-labelledby="adminBlockedLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <div class="fs-1 mb-2">🚫</div>
                <h5 class="fw-bold text-dark mb-2" id="adminBlockedLabel">Access Denied</h5>
                <p class="text-muted small mb-4">
                    You don't have authority to sign in here.
                    This login is for community members only.
                </p>
                <a href="admin/admin_login.php" class="btn btn-primary px-4 me-2">Go to Admin Login</a>
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($admin_blocked): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('adminBlockedModal')).show();
    });
</script>
<?php endif; ?>

</body>
</html>