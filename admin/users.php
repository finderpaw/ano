<?php
require_once __DIR__ . '/admin_guard.php';

$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

$me = $_SESSION['username'] ?? '';
$msg = '';
$msg_type = '';
$root = '../';

/* ---------- roles ---------- */
$extra_roles = [];
$roles = [];
$rr = @mysqli_query($conn, "SELECT DISTINCT role FROM users WHERE role <> '' ORDER BY role");
if ($rr) {
    while ($x = mysqli_fetch_row($rr)) $roles[] = $x[0];
}
foreach (array_merge(['adopter'], $extra_roles) as $x) {
    if (!in_array($x, $roles, true)) $roles[] = $x;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    $target = null;
    if ($id > 0) {
        $st = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param("i", $id);
            $st->execute();
            $res_target = $st->get_result();
            if ($res_target && $res_target->num_rows > 0) {
                $target = $res_target->fetch_assoc();
            }
            $st->close();
        }
    }

    if ($act === 'add') {
        $name    = trim($_POST['fullname'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $user    = trim($_POST['username'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $role    = $_POST['role'] ?? '';
        $contact = trim($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '' || $user === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Enter a full name, username and valid email.'; $msg_type = 'err';
        } elseif (strlen($pass) < 8) {
            $msg = 'Password must be at least 8 characters.'; $msg_type = 'err';
        } elseif (!in_array($role, $roles, true)) {
            $msg = 'Choose a valid role.'; $msg_type = 'err';
        } else {
            $pass_hash = password_hash($pass, PASSWORD_DEFAULT);

            $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            if ($check) {
                $check->bind_param("ss", $user, $email);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $msg = 'That username or email is already registered.'; $msg_type = 'err';
                } else {
                    $ins = $conn->prepare("INSERT INTO users (fullname, email, address, contact, role, username, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($ins) {
                        $ins->bind_param("sssssss", $name, $email, $address, $contact, $role, $user, $pass_hash);
                        if ($ins->execute()) {
                            $msg = "Account for $user created."; $msg_type = 'succ';
                        } else {
                            $msg = 'Database error while creating account.'; $msg_type = 'err';
                        }
                        $ins->close();
                    }
                }
                $check->close();
            }
        }
    } elseif ($act === 'edit_contact' && $target) {
        $contact = trim($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $up = $conn->prepare("UPDATE users SET contact = ?, address = ? WHERE id = ?");
        if ($up) {
            $up->bind_param("ssi", $contact, $address, $id);
            if ($up->execute()) {
                $msg = 'Contact details for ' . $target['username'] . ' updated.';
                $msg_type = 'succ';
            } else {
                $msg = 'Database error while updating contact.';
                $msg_type = 'err';
            }
            $up->close();
        }
    } elseif ($act === 'delete' && $target) {
        if ($target['username'] === $me) {
            $msg = 'You cannot delete your own account.'; $msg_type = 'err';
        } else {
            $st = $conn->prepare("DELETE FROM users WHERE id = ?");
            if ($st) {
                $st->bind_param("i", $id);
                $st->execute();
                $st->close();
            }
            $msg = 'Account ' . $target['username'] . ' deleted.'; $msg_type = 'succ';
        }
    }
}

/* ---------- list + search ---------- */
$search = trim($_GET['q'] ?? '');
$role_f = in_array($_GET['role'] ?? '', $roles, true) ? $_GET['role'] : '';

$s_like = '%' . $search . '%';
$query = "SELECT * FROM users WHERE (fullname LIKE ? OR email LIKE ? OR username LIKE ?)";
$params = [$s_like, $s_like, $s_like];
$types = "sss";

if ($role_f !== '') {
    $query .= " AND role = ?";
    $params[] = $role_f;
    $types .= "s";
}
$query .= " ORDER BY id DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
} else {
    $res = false;
}

function count_rows_safe(mysqli $conn, string $sql): int {
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_row($r);
    return (int)($row[0] ?? 0);
}

function count_role(mysqli $conn, string $role): int {
    $st = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
    if (!$st) return 0;
    $st->bind_param("s", $role);
    $st->execute();
    $c = 0;
    $st->bind_result($c);
    $st->fetch();
    $st->close();
    return (int)$c;
}

// IBALIK ANG $total PARA HINDI MAG-WARNING SA LINE 238
$total = count_rows_safe($conn, 'SELECT COUNT(*) FROM users');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Manage Users</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">
<style>
    aside.side { display: flex; flex-direction: column; justify-content: space-between; }
    aside.side .nav-container { flex: 1; }
    aside.side .logout { border-top: 1px solid var(--line); padding-top: 12px; margin-top: auto; }
    aside.side .logout a { background: var(--red-bg); color: var(--red) !important; border-radius: 8px; transition: background 0.2s; }
    aside.side .logout a:hover { background: #fecaca; }

    /* MODAL STYLES */
    .modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.5);
        z-index: 999;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .modal-backdrop.open { display: flex; }
    .modal-box {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        width: 100%;
        max-width: 420px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
    .modal-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    .modal-head h3 { margin: 0; font-size: 18px; }
    .modal-close {
        background: none;
        border: none;
        font-size: 22px;
        cursor: pointer;
        color: var(--muted);
    }
    .mini.edit {
        background: #e0f2fe;
        color: #0369a1;
        border: none;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
    }
    .mini.edit:hover { background: #bae6fd; }
</style>
</head>
<body>
<div class="app">

    <!-- SIDEBAR -->
    <aside class="side">
        <div class="nav-container">
            <div class="brand">
                <img src="<?php echo $root; ?>image/logo2.jpg" alt="PAWFINDER logo" onerror="this.src='https://placehold.co/42x42/0b5a6b/ffffff?text=PF'">
                <div><b>PAWFINDER</b><small>Community Pet System</small></div>
            </div>

            <div class="group">MAIN MENU</div>
            <nav>
                <a href="admin.php">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </a>
                <a href="adopt.php">
                    <svg viewBox="0 0 24 24"><path d="M12 21s-7-4.5-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.5-9 9-9 9z"/></svg>
                    Pets
                </a>
                <a href="users.php" class="active">
                    <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.4c2 .7 3.5 2.500 3.5 5.600"/></svg>
                    Users
                </a>
                <a href="notification.php">
                    <svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.300 21a1.900 1.900 0 0 0 3.400 0"/></svg>
                    Notifications
                </a>
                <a href="admin_updates.php">
                    <svg viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.8-3.4L23 10M1 14l4.700 4.400A9 9 0 0 0 20.500 15"/></svg>
                    Weekly Updates
                    <span class="an-nav-badge" id="anNavBadge"></span>
                </a>
            </nav>

            <hr>
            <div class="group">OTHER</div>
            <nav>
                <a href="settings.php">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.400 15a1.700 1.700 0 0 0 .3 1.800l.1.1a2 2 0 1 1-2.800 2.800l-.1-.1a1.700 1.700 0 0 0-1.800-.3 1.700 1.700 0 0 0-1 1.500V21a2 2 0 1 1-4 0v-.1a1.700 1.700 0 0 0-1.100-1.500 1.700 1.700 0 0 0-1.800.3l-.1.1a2 2 0 1 1-2.800-2.800l.1-.1a1.700 1.700 0 0 0 .3-1.800 1.700 1.700 0 0 0-1.500-1H3a2 2 0 1 1 0-4h.1a1.700 1.700 0 0 0 1.500-1.100 1.700 1.700 0 0 0-.3-1.800l-.1-.1a2 2 0 1 1 2.800-2.800l.1.1a1.700 1.700 0 0 0 1.800.3H9a1.700 1.700 0 0 0 1-1.500V3a2 2 0 1 1 4 0v.1a1.700 1.700 0 0 0 1 1.500 1.700 1.700 0 0 0 1.800-.3l-.1-.1a2 2 0 1 1 2.800 2.800l-.1.1a1.700 1.700 0 0 0-.3 1.800V9a1.700 1.700 0 0 0 1.500 1H21a2 2 0 1 1 0 4h-.1a1.700 1.700 0 0 0-1.500 1z"/></svg>
                    Settings
                </a>
                <a href="contact.php">
                    <svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.200 2 19.800 19.800 0 0 1-8.600-3.100 19.500 19.500 0 0 1-6-6A19.800 19.800 0 0 1 2.100 4.200 2 2 0 0 1 4.100 2h3a2 2 0 0 1 2 1.700c.1 1 .4 1.900.7 2.800a2 2 0 0 1-.5 2.100L8.100 9.900a16 16 0 0 0 6 6l1.300-1.300a2 2 0 0 1 2.100-.4c.9.300 1.800.6 2.800.7a2 2 0 0 1 1.700 2z"/></svg>
                    Contact
                </a>
            </nav>
        </div>

        <nav class="logout">
            <a href="admin_logout.php" onclick="return confirm('Are you sure you want to log out?');">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Log Out
            </a>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">
        <div class="top">
            <div>
                <h1>User Accounts Management</h1>
                <p>Manage roles, permissions, and registered community users.</p>
            </div>
            <div class="role">Active role: <b>Admin</b></div>
        </div>

        <?php if ($msg): ?>
            <div class="flash <?php echo $msg_type === 'err' ? 'err' : 'ok'; ?>" style="margin-top: 15px;"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="grid g4" style="margin-top: 15px;">
            <div class="card stat">
                <h2>All accounts</h2>
                <div class="num"><strong><?php echo $total; ?></strong></div>
                <div class="sub">Total system users</div>
            </div>
            <?php foreach ($roles as $r): ?>
            <div class="card stat">
                <h2><?php echo htmlspecialchars($r); ?> accounts</h2>
                <div class="num"><strong><?php echo count_role($conn, $r); ?></strong></div>
                <div class="sub">Role: <?php echo htmlspecialchars($r); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <details class="panel" style="margin-top: 24px; margin-bottom: 22px;">
            <summary class="btn" style="cursor:pointer; display: inline-block;">+ Add user</summary>
            <form method="post" class="form-grid" style="margin-top:18px;">
                <input type="hidden" name="action" value="add">
                <label class="field">Full name<input class="input" name="fullname" required></label>
                <label class="field">Email<input class="input" type="email" name="email" required></label>
                <label class="field">Username<input class="input" name="username" required></label>
                <label class="field">Password (8+ chars)<input class="input" type="password" name="password" minlength="8" required></label>
                <label class="field">Contact number<input class="input" name="contact"></label>
                <label class="field">Role<select class="input" name="role"><?php foreach ($roles as $r) echo '<option value="' . htmlspecialchars($r) . '">' . htmlspecialchars($r) . '</option>'; ?></select></label>
                <label class="field wide" style="grid-column: 1 / -1;">Address<input class="input" name="address"></label>
                <div class="wide" style="grid-column: 1 / -1;"><button class="btn" type="submit">Create account</button></div>
            </form>
        </details>

        <section class="panel">
            <form class="toolbar" method="get" style="display:flex; gap:10px; margin-bottom:16px;">
                <input class="input search" type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email or username..." style="flex:1;">
                <select class="input" name="role" onchange="this.form.submit()">
                    <option value="">All roles</option>
                    <?php foreach ($roles as $r) echo '<option value="' . htmlspecialchars($r) . '"' . ($r === $role_f ? ' selected' : '') . '>' . htmlspecialchars($r) . '</option>'; ?>
                </select>
                <button class="btn" type="submit">Search</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Contact</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="tcenter">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($res && $res->num_rows > 0): while ($u = $res->fetch_assoc()): $self = $u['username'] === $me; ?>
                        <tr>
                            <td><b><?php echo htmlspecialchars($u['fullname'] ?? ''); ?></b><br><small><?php echo htmlspecialchars($u['email'] ?? ''); ?></small></td>
                            <td><?php echo htmlspecialchars($u['username'] ?? ''); ?><?php if ($self) echo ' <span class="badge">You</span>'; ?></td>
                            <td><?php echo htmlspecialchars($u['contact'] ?? ''); ?><br><small><?php echo htmlspecialchars($u['address'] ?? ''); ?></small></td>
                            <td><?php echo htmlspecialchars(ucfirst($u['role'] ?? '')); ?></td>
                            <td><span class="badge"><?php echo htmlspecialchars($u['account_status'] ?? ''); ?></span></td>
                            <td><?php echo htmlspecialchars(substr($u['created_at'] ?? '', 0, 10)); ?></td>
                            <td class="tcenter" style="white-space:nowrap;">
                                <button type="button" class="mini edit"
                                    onclick="openEditContact(
                                        <?php echo (int)$u['id']; ?>,
                                        <?php echo htmlspecialchars(json_encode($u['fullname'] ?? $u['username']), ENT_QUOTES); ?>,
                                        <?php echo htmlspecialchars(json_encode($u['contact'] ?? ''), ENT_QUOTES); ?>,
                                        <?php echo htmlspecialchars(json_encode($u['address'] ?? ''), ENT_QUOTES); ?>
                                    )">
                                    Edit Contact
                                </button>
                                <?php if (!$self): ?>
                                <form method="post" style="display:inline; margin-left:4px;" onsubmit="return confirm('Delete this account?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                    <button class="mini danger" type="submit">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="7" class="empty" style="text-align:center">No accounts match. Add one above.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- EDIT USER CONTACT MODAL -->
<div class="modal-backdrop" id="editContactModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal-box">
        <div class="modal-head">
            <h3>Edit Contact — <span id="editUserName"></span></h3>
            <button type="button" class="modal-close" onclick="document.getElementById('editContactModal').classList.remove('open')">&times;</button>
        </div>
        <form method="post" class="form-grid one">
            <input type="hidden" name="action" value="edit_contact">
            <input type="hidden" name="id" id="editUserId">

            <label class="field">
                Contact Number
                <input class="input" type="text" name="contact" id="editUserContact" placeholder="e.g. 0912-345-6789">
            </label>

            <label class="field">
                Address
                <input class="input" type="text" name="address" id="editUserAddress" placeholder="Enter address">
            </label>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                <button type="button" class="btn-soft" onclick="document.getElementById('editContactModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn">Update Contact</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditContact(id, name, contact, address) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserName').textContent = name;
    document.getElementById('editUserContact').value = contact;
    document.getElementById('editUserAddress').value = address;
    document.getElementById('editContactModal').classList.add('open');
}
</script>
</body>
</html>