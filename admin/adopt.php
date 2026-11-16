<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

$species = ['dog' => 'Dogs', 'cat' => 'Cats', 'bird' => 'Birds'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM pets WHERE id = ?");
        if ($st) {
            $st->bind_param("i", $del_id);
            $st->execute();
            $st->close();
        }
    }
    header('Location: adopt.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sp     = isset($species[$_GET['sp'] ?? '']) ? $_GET['sp'] : '';
$like   = '%' . $search . '%';

$query = "SELECT * FROM pets WHERE (pet_name LIKE ? OR breed LIKE ? OR barangay LIKE ?)";
$params = [$like, $like, $like];
$types  = "sss";

if ($sp !== '') {
    $query .= " AND LOWER(pet_species) LIKE ?";
    $params[] = '%' . strtolower($sp) . '%';
    $types  .= "s";
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

function count_rows_safe($conn, $sql) {
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_row($r);
    return (int)($row[0] ?? 0);
}

$root = '../';
function asset_url($path, $root) {
    if ($path === '' || preg_match('#^(https?:)?//#', $path) || $path[0] === '/') return $path;
    return $root . ltrim($path, './');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PAWFINDER - Pets Adoption</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="admin.css">
<style>
    aside.side { display: flex; flex-direction: column; justify-content: space-between; }
    aside.side .nav-container { flex: 1; }
    aside.side .logout { border-top: 1px solid var(--line); padding-top: 12px; margin-top: auto; }
    aside.side .logout a { background: var(--red-bg); color: var(--red) !important; border-radius: 8px; transition: background 0.2s; }
    aside.side .logout a:hover { background: #fecaca; }
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
                <a href="adopt.php" class="active">
                    <svg viewBox="0 0 24 24"><path d="M12 21s-7-4.5-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.5-9 9-9 9z"/></svg>
                    Pets
                </a>
                <a href="users.php">
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
                <h1>Pets Adoption Management</h1>
                <p>Monitor, search, and manage pets listed for adoption.</p>
            </div>
            <div class="role">Active role: <b>Admin</b></div>
        </div>

        <!-- STAT CARDS -->
        <div class="grid g4" style="margin-top: 15px;">
            <div class="card stat">
                <h2>All pets</h2>
                <div class="num"><strong><?php echo count_rows_safe($conn, 'SELECT COUNT(*) FROM pets'); ?></strong></div>
                <div class="sub">Total registered pets</div>
            </div>
            <?php foreach ($species as $k => $label): ?>
            <div class="card stat">
                <h2><?php echo $label; ?></h2>
                <div class="num"><strong><?php echo count_rows_safe($conn, "SELECT COUNT(*) FROM pets WHERE LOWER(pet_species) LIKE '%$k%'"); ?></strong></div>
                <div class="sub">Listed <?php echo strtolower($label); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <section class="panel" style="margin-top: 24px;">
            <form class="toolbar" method="get" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
                <input class="input search" type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, breed or barangay..." style="flex:1; min-width:220px;">
                <select class="input" name="sp" onchange="this.form.submit()">
                    <option value="">All species</option>
                    <?php foreach ($species as $k => $label) echo '<option value="' . $k . '"' . ($k === ($_GET['sp'] ?? '') ? ' selected' : '') . '>' . $label . '</option>'; ?>
                </select>
                <button class="btn" type="submit">Search</button>
                <a class="btn" href="add.php" style="margin-left:auto; text-decoration:none;">+ Add pet</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Pet</th>
                            <th>Species</th>
                            <th>Breed &amp; age</th>
                            <th>Barangay</th>
                            <th class="tcenter">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($res && mysqli_num_rows($res) > 0): while ($p = mysqli_fetch_assoc($res)): ?>
                        <tr>
                            <td>
                                <div class="person">
                                    <img class="thumb" src="<?php echo htmlspecialchars(asset_url($p['pet_photo'], $root)); ?>" alt="" onerror="this.style.visibility='hidden'">
                                    <b><?php echo htmlspecialchars($p['pet_name']); ?></b>
                                </div>
                            </td>
                            <td><span class="badge"><?php echo htmlspecialchars($p['pet_species']); ?></span></td>
                            <td><?php echo htmlspecialchars($p['breed']); ?><br><small><?php echo htmlspecialchars($p['age']); ?></small></td>
                            <td><?php echo htmlspecialchars($p['barangay']); ?></td>
                            <td class="tcenter">
                                <a class="mini" href="pet_details.php?id=<?php echo (int)$p['id']; ?>">View</a>
                                <a class="mini" href="edit.php?id=<?php echo (int)$p['id']; ?>">Edit</a>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this pet listing?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button class="mini danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="empty" style="text-align:center">No pets found. Use “+ Add pet” to list one.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>