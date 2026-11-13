<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

$roles = ['admin', 'rescuer', 'adopter'];
$me = $_SESSION['username'] ?? '';
$msg = '';
$msg_type = '';

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    // Get target user
    $target = null;
    if ($id > 0) {
        $res_target = mysqli_query($conn, "SELECT username FROM registered WHERE id = $id LIMIT 1");
        if ($res_target && mysqli_num_rows($res_target) > 0) {
            $target = mysqli_fetch_assoc($res_target);
        }
    }

    if ($act === 'add') {
        $name = trim($_POST['fullname'] ?? ''); 
        $email = trim($_POST['email'] ?? '');
        $user = trim($_POST['username'] ?? ''); 
        $pass = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? ''; 
        $contact = trim($_POST['contact'] ?? ''); 
        $address = trim($_POST['address'] ?? '');

        if ($name === '' || $user === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Enter a full name, username and valid email.'; $msg_type = 'err';
        } elseif (strlen($pass) < 8) {
            $msg = 'Password must be at least 8 characters.'; $msg_type = 'err';
        } elseif (!in_array($role, $roles, true)) {
            $msg = 'Choose a valid role.'; $msg_type = 'err';
        } else {
            $name_esc = mysqli_real_escape_string($conn, $name);
            $email_esc = mysqli_real_escape_string($conn, $email);
            $user_esc = mysqli_real_escape_string($conn, $user);
            $pass_hash = password_hash($pass, PASSWORD_DEFAULT);
            $contact_esc = mysqli_real_escape_string($conn, $contact);
            $address_esc = mysqli_real_escape_string($conn, $address);
            $role_esc = mysqli_real_escape_string($conn, $role);

            // Check if username or email exists
            $check = mysqli_query($conn, "SELECT id FROM registered WHERE username='$user_esc' OR email='$email_esc'");
            if ($check && mysqli_num_rows($check) > 0) {
                $msg = 'That username or email is already registered.'; $msg_type = 'err';
            } else {
                $ins = mysqli_query($conn, "INSERT INTO registered (fullname, email, address, contact, role, username, password) VALUES ('$name_esc', '$email_esc', '$address_esc', '$contact_esc', '$role_esc', '$user_esc', '$pass_hash')");
                if ($ins) {
                    $msg = "Account for $user created."; $msg_type = 'succ';
                } else {
                    $msg = 'Database error while creating account.'; $msg_type = 'err';
                }
            }
        }
    } elseif ($act === 'role' && $target) {
        $role = $_POST['role'] ?? '';
        if ($target['username'] === $me) {
            $msg = 'You cannot change your own role.'; $msg_type = 'err';
        } elseif (in_array($role, $roles, true)) {
            $role_esc = mysqli_real_escape_string($conn, $role);
            mysqli_query($conn, "UPDATE registered SET role = '$role_esc' WHERE id = $id");
            $msg = $target['username'] . ' is now ' . $role . '.'; $msg_type = 'succ';
        } else {
            $msg = 'Could not update the role.'; $msg_type = 'err';
        }
    } elseif ($act === 'delete' && $target) {
        if ($target['username'] === $me) {
            $msg = 'You cannot delete your own account.'; $msg_type = 'err';
        } else {
            mysqli_query($conn, "DELETE FROM registered WHERE id = $id");
            $msg = 'Account ' . $target['username'] . ' deleted.'; $msg_type = 'succ';
        }
    }
}

/* ---------- data ---------- */
$search = trim($_GET['q'] ?? '');
$role_f = in_array($_GET['role'] ?? '', $roles, true) ? $_GET['role'] : '';

$s_esc = mysqli_real_escape_string($conn, $search);
$rf_esc = mysqli_real_escape_string($conn, $role_f);

$query = "SELECT * FROM registered WHERE (fullname LIKE '%$s_esc%' OR email LIKE '%$s_esc%' OR username LIKE '%$s_esc%')";
if ($rf_esc !== '') {
    $query .= " AND role = '$rf_esc'";
}
$query .= " ORDER BY id DESC";

$res = mysqli_query($conn, $query);

function count_rows_safe($conn, $sql) {
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_row($r);
    return (int)($row[0] ?? 0);
}

$total = count_rows_safe($conn, 'SELECT COUNT(*) FROM registered');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Manage Users</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="app" style="display:block; padding:20px;">
<p><a href="admin.php">← Back to Dashboard</a></p>

<?php if ($msg): ?>
    <div style="padding: 10px 15px; margin-bottom: 15px; border-radius: 4px; background: <?php echo $msg_type === 'err' ? '#f8d7da' : '#d4edda'; ?>; color: <?php echo $msg_type === 'err' ? '#721c24' : '#155724'; ?>;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<div class="grid g4" style="display:flex; gap:15px; margin-bottom:20px;">
    <div class="card stat" style="background:#fff; padding:15px; border-radius:8px; flex:1;"><h2>All accounts</h2><div class="num"><strong><?php echo $total; ?></strong></div></div>
    <?php foreach ($roles as $r): ?>
    <div class="card stat" style="background:#fff; padding:15px; border-radius:8px; flex:1;"><h2><?php echo ucfirst($r); ?>s</h2><div class="num"><strong><?php echo count_rows_safe($conn, "SELECT COUNT(*) FROM registered WHERE role = '$r'"); ?></strong></div></div>
    <?php endforeach; ?>
</div>

<details class="panel" style="background:#fff; padding:20px; border-radius:8px; margin-bottom:20px;">
    <summary class="btn" style="cursor:pointer; font-weight:600; color:#0b5a6b;">+ Add user</summary>
    <form method="post" class="form-grid" style="margin-top:18px; display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
        <input type="hidden" name="action" value="add">
        <label class="field">Full name<br><input class="input" name="fullname" required style="width:100%; padding:8px; margin-top:5px;"></label>
        <label class="field">Email<br><input class="input" type="email" name="email" required style="width:100%; padding:8px; margin-top:5px;"></label>
        <label class="field">Username<br><input class="input" name="username" required style="width:100%; padding:8px; margin-top:5px;"></label>
        <label class="field">Password (8+ chars)<br><input class="input" type="password" name="password" minlength="8" required style="width:100%; padding:8px; margin-top:5px;"></label>
        <label class="field">Contact number<br><input class="input" name="contact" style="width:100%; padding:8px; margin-top:5px;"></label>
        <label class="field">Role<br><select class="input" name="role" style="width:100%; padding:8px; margin-top:5px;"><?php foreach ($roles as $r) echo "<option value=\"$r\">$r</option>"; ?></select></label>
        <label class="field" style="grid-column: 1 / -1;">Address<br><input class="input" name="address" style="width:100%; padding:8px; margin-top:5px;"></label>
        <div style="grid-column: 1 / -1;"><button class="btn" type="submit" style="padding:8px 15px; background:#0b5a6b; color:#fff; border:none; border-radius:4px; cursor:pointer;">Create account</button></div>
    </form>
</details>

<section class="panel" style="background:#fff; padding:20px; border-radius:8px;">
    <form class="toolbar" method="get" style="display:flex; gap:10px; margin-bottom:15px;">
        <input class="input search" type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email or username..." style="padding:8px; flex:1;">
        <select class="input" name="role" onchange="this.form.submit()" style="padding:8px;">
            <option value="">All roles</option>
            <?php foreach ($roles as $r) echo '<option value="' . $r . '"' . ($r === $role_f ? ' selected' : '') . '>' . ucfirst($r) . '</option>'; ?>
        </select>
        <button class="btn" type="submit" style="padding:8px 15px;">Search</button>
    </form>
    <div class="table-wrap">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="text-align:left; border-bottom:2px solid #eee;">
                    <th style="padding:10px;">Name</th>
                    <th style="padding:10px;">Username</th>
                    <th style="padding:10px;">Contact</th>
                    <th style="padding:10px;">Role</th>
                    <th style="padding:10px;">Joined</th>
                    <th style="padding:10px; text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($res && mysqli_num_rows($res) > 0): while ($u = mysqli_fetch_assoc($res)): $self = $u['username'] === $me; ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><b><?php echo htmlspecialchars($u['fullname']); ?></b><br><small><?php echo htmlspecialchars($u['email']); ?></small></td>
                    <td style="padding:10px;"><?php echo htmlspecialchars($u['username']); ?><?php if ($self) echo ' <span class="badge" style="background:#e2e8f0; padding:2px 6px; border-radius:4px; font-size:11px;">You</span>'; ?></td>
                    <td style="padding:10px;"><?php echo htmlspecialchars($u['contact']); ?><br><small><?php echo htmlspecialchars($u['address']); ?></small></td>
                    <td style="padding:10px;">
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="role">
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <select class="input sel" name="role" onchange="this.form.submit()" <?php echo $self ? 'disabled' : ''; ?> style="padding:4px;">
                                <?php foreach ($roles as $r) echo '<option value="' . $r . '"' . ($r === $u['role'] ? ' selected' : '') . '>' . $r . '</option>'; ?>
                            </select>
                        </form>
                    </td>
                    <td style="padding:10px;"><?php echo htmlspecialchars(substr($u['created_at'], 0, 10)); ?></td>
                    <td style="padding:10px; text-align:center;">
                        <?php if (!$self): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this account?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <button class="mini danger" type="submit" style="background:none; border:none; color:red; cursor:pointer;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="6" class="empty" style="text-align:center; padding:20px;">No accounts match. Add one above.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
</div>
</body>
</html>