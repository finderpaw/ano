<?php
require_once __DIR__ . '/admin_guard.php';

/* config.php: look in this folder first, then one level up (pawfinder/) */
$config_file = file_exists(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/../config.php';
require_once $config_file;

$species = ['dog' => 'Dogs', 'cat' => 'Cats', 'bird' => 'Birds'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM pets WHERE id = $del_id");
    }
    header('Location: adopt.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sp     = isset($species[$_GET['sp'] ?? '']) ? $_GET['sp'] : '';
$like   = '%' . mysqli_real_escape_string($conn, $search) . '%';
$sp_esc = mysqli_real_escape_string($conn, $sp);

$query = "SELECT * FROM pets WHERE (pet_name LIKE '$like' OR breed LIKE '$like' OR barangay LIKE '$like')";
if ($sp_esc !== '') {
    $query .= " AND LOWER(pet_species) LIKE '%$sp_esc%'";
}
$query .= " ORDER BY id DESC";

$res = mysqli_query($conn, $query);

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
<link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="app" style="display:block; padding:20px;">
<p><a href="admin.php">← Back to Dashboard</a></p>

<div class="grid g4" style="display:flex; gap:15px; margin-bottom:20px;">
    <div class="card stat" style="background:#fff; padding:15px; border-radius:8px; flex:1;"><h2>All pets</h2><div class="num"><strong><?php echo count_rows_safe($conn, 'SELECT COUNT(*) FROM pets'); ?></strong></div></div>
    <?php foreach ($species as $k => $label): ?>
    <div class="card stat" style="background:#fff; padding:15px; border-radius:8px; flex:1;"><h2><?php echo $label; ?></h2><div class="num"><strong><?php echo count_rows_safe($conn, "SELECT COUNT(*) FROM pets WHERE LOWER(pet_species) LIKE '%$k%'"); ?></strong></div></div>
    <?php endforeach; ?>
</div>

<section class="panel" style="background:#fff; padding:20px; border-radius:8px;">
    <form class="toolbar" method="get" style="display:flex; gap:10px; margin-bottom:15px;">
        <input class="input search" type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, breed or barangay..." style="padding:8px; flex:1;">
        <select class="input" name="sp" onchange="this.form.submit()" style="padding:8px;">
            <option value="">All species</option>
            <?php foreach ($species as $k => $label) echo '<option value="' . $k . '"' . ($k === ($_GET['sp'] ?? '') ? ' selected' : '') . '>' . $label . '</option>'; ?>
        </select>
        <button class="btn" type="submit" style="padding:8px 15px;">Search</button>
        <a class="btn" href="add.php" style="margin-left:auto; padding:8px 15px; background:#0b5a6b; color:#fff; text-decoration:none; border-radius:4px;">+ Add pet</a>
    </form>
    <div class="table-wrap">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="text-align:left; border-bottom:2px solid #eee;">
                    <th style="padding:10px;">Pet</th>
                    <th style="padding:10px;">Species</th>
                    <th style="padding:10px;">Breed &amp; age</th>
                    <th style="padding:10px;">Barangay</th>
                    <th style="padding:10px; text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($res && mysqli_num_rows($res) > 0): while ($p = mysqli_fetch_assoc($res)): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <img class="thumb" src="<?php echo htmlspecialchars(asset_url($p['pet_photo'], $root)); ?>" alt="" style="width:40px; height:40px; object-fit:cover; border-radius:50%;" onerror="this.style.visibility='hidden'">
                            <b><?php echo htmlspecialchars($p['pet_name']); ?></b>
                        </div>
                    </td>
                    <td style="padding:10px;"><span class="badge"><?php echo htmlspecialchars($p['pet_species']); ?></span></td>
                    <td style="padding:10px;"><?php echo htmlspecialchars($p['breed']); ?><br><small><?php echo htmlspecialchars($p['age']); ?></small></td>
                    <td style="padding:10px;"><?php echo htmlspecialchars($p['barangay']); ?></td>
                    <td style="padding:10px; text-align:center;">
                        <a class="mini" href="pet_details.php?id=<?php echo (int)$p['id']; ?>">View</a> |
                        <a class="mini" href="edit.php?id=<?php echo (int)$p['id']; ?>">Edit</a> |
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this pet listing?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                            <button class="mini danger" type="submit" style="background:none; border:none; color:red; cursor:pointer;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="5" class="empty" style="text-align:center; padding:20px;">No pets found. Use “Add pet” to list one.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
</div>
</body>
</html>