<?php
require_once __DIR__ . '/layout.php';

/* ---------- update status (POST) ---------- */
$statuses = ['Pending', 'On the way', 'Rescued'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['status']) && in_array($_POST['status'], $statuses, true)) {
    $stmt = mysqli_prepare($conn, "UPDATE rescue_reports SET status = ? WHERE id = ?");
    $id = (int)$_POST['id'];
    mysqli_stmt_bind_param($stmt, 'si', $_POST['status'], $id);
    mysqli_stmt_execute($stmt);
    header("Location: reports.php?" . http_build_query($_GET));
    exit();
}

/* ---------- filters (whitelisted / escaped) ---------- */
$species = ['Dog', 'Cat', 'Bird'];
$genders = ['Male', 'Female', 'Unknown'];
$urgs    = ['High', 'Medium', 'Low'];

$fSp = in_array($_GET['species'] ?? '', $species, true)  ? $_GET['species'] : '';
$fGe = in_array($_GET['gender']  ?? '', $genders, true)  ? $_GET['gender']  : '';
$fUr = in_array($_GET['urgency'] ?? '', $urgs, true)     ? $_GET['urgency'] : '';
$fSt = in_array($_GET['status']  ?? '', $statuses, true) ? $_GET['status']  : '';
$q   = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'urgency';

$cond = [];
if ($fSp) $cond[] = "LOWER(pet_type) LIKE '%" . strtolower($fSp) . "%'";
if ($fGe) $cond[] = "gender = '" . mysqli_real_escape_string($conn, $fGe) . "'";
if ($fUr) $cond[] = "($URG_SQL) = '" . mysqli_real_escape_string($conn, $fUr) . "'";
if ($fSt) $cond[] = "status = '" . mysqli_real_escape_string($conn, $fSt) . "'";
if ($q !== '') {
    $s = mysqli_real_escape_string($conn, $q);
    $cond[] = "(location LIKE '%$s%' OR reporter_name LIKE '%$s%')";
}
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

$orders = [
    'urgency' => "FIELD(urgency,'High','Medium','Low'), report_date DESC",
    'newest'  => "report_date DESC",
    'species' => "pet_type ASC, report_date DESC",
    'gender'  => "gender ASC, report_date DESC",
];
$order = $orders[$sort] ?? $orders['urgency'];

$res = @mysqli_query($conn, "SELECT *, ($URG_SQL) AS urgency FROM rescue_reports $where ORDER BY $order");
$count = $res ? mysqli_num_rows($res) : 0;

rescuer_head('reports', 'Rescue Reports');
rescuer_top('Rescue Reports', 'Pets that need to be rescued, with their locations');

function opts($list, $sel, $all = 'All') {
    $h = "<option value=\"\">$all</option>";
    foreach ($list as $o) $h .= '<option value="' . e($o) . '"' . ($sel === $o ? ' selected' : '') . '>' . e($o) . '</option>';
    return $h;
}
?>
        <section class="panel">
            <div class="panel-head"><h2>Reports (<?php echo $count; ?>)</h2></div>

            <form method="get" class="filter-bar">
                <label>Search location or reporter<input type="search" name="q" value="<?php echo e($q); ?>" placeholder="Brgy. Lakandula..."></label>
                <label>Species<select name="species" onchange="this.form.submit()"><?php echo opts($species, $fSp); ?></select></label>
                <label>Gender<select name="gender" onchange="this.form.submit()"><?php echo opts($genders, $fGe); ?></select></label>
                <label>Urgency<select name="urgency" onchange="this.form.submit()"><?php echo opts($urgs, $fUr); ?></select></label>
                <label>Status<select name="status" onchange="this.form.submit()"><?php echo opts($statuses, $fSt); ?></select></label>
                <label>Sort by
                    <select name="sort" onchange="this.form.submit()">
                        <?php foreach (['urgency' => 'Most urgent', 'newest' => 'Newest', 'species' => 'Species', 'gender' => 'Gender'] as $k => $lbl): ?>
                            <option value="<?php echo $k; ?>" <?php echo $sort === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn" type="submit">Search</button>
                <a class="btn btn-soft" href="reports.php">Clear</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Pet</th><th>Gender</th><th>Location to rescue</th><th>Reporter</th><th>Urgency</th><th>Status</th><th class="tcenter">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($res && $count > 0): while ($r = mysqli_fetch_assoc($res)): ?>
                        <tr>
                            <td><b><?php echo e($r['pet_type']); ?></b><br><small><?php echo e($r['pet_condition']); ?></small>
                                <?php if (!empty($r['description'])): ?><br><small><?php echo e($r['description']); ?></small><?php endif; ?></td>
                            <td><?php echo e($r['gender'] ?? 'Unknown'); ?></td>
                            <td><?php echo e($r['location']); ?><br><small><?php echo e($r['report_date']); ?></small></td>
                            <td><?php echo e($r['reporter_name']); ?><br><small><?php echo e($r['contact_number']); ?></small></td>
                            <td><span class="<?php echo pill_class($r['urgency']); ?>"><?php echo e($r['urgency']); ?></span></td>
                            <td><span class="<?php echo pill_class($r['status']); ?>"><?php echo e($r['status']); ?></span></td>
                            <td class="tcenter">
                                <?php
                                $next = $r['status'] === 'Pending' ? 'On the way' : ($r['status'] === 'On the way' ? 'Rescued' : '');
                                $label = $next === 'On the way' ? 'Accept rescue' : 'Mark rescued';
                                if ($next): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="status" value="<?php echo e($next); ?>">
                                    <button class="mini" style="border:0;cursor:pointer"><?php echo $label; ?></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="7" class="empty" style="text-align:center">No reports match these filters. Clear a filter to see more.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php rescuer_foot(); ?>