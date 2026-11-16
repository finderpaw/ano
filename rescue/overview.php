<?php
require_once __DIR__ . '/layout.php';

/* Homepage of the main site (one level up). Change the file name here if yours is different. */
$homeUrl = '../homepage.php';

/* optional species filter for the cards */
$allowed = ['Dog', 'Cat', 'Bird'];
$sp  = (isset($_GET['species']) && in_array($_GET['species'], $allowed, true)) ? $_GET['species'] : '';
$and = $sp ? "AND LOWER(pet_type) LIKE '%" . strtolower($sp) . "%'" : '';

/* ---------- numbers ---------- */
$total   = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports WHERE 1=1 $and");
$need    = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports WHERE status <> 'Rescued' $and");
$urgent  = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports WHERE status <> 'Rescued' AND ($URG_SQL) = 'High' $and");
$rescued = max(0, $total - $need);
$percent = $total > 0 ? round($need / $total * 100) : 0;

/* pets still needing rescue, by species */
$dogs  = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports WHERE status <> 'Rescued' AND LOWER(pet_type) LIKE '%dog%'");
$cats  = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports WHERE status <> 'Rescued' AND LOWER(pet_type) LIKE '%cat%'");
$birds = count_rows($conn, "SELECT COUNT(*) FROM rescue_reports WHERE status <> 'Rescued' AND LOWER(pet_type) LIKE '%bird%'");
$bubble_max = max(1, $dogs, $cats, $birds);
function bubble($n, $max) { return (int)round(78 + 62 * ($n / $max)); }

/* reports per month, last 6 months */
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i month", strtotime(date('Y-m-01'))));
    $months[$key] = ['label' => date('M', strtotime($key . '-01')), 'n' => 0];
}
$rm = @mysqli_query($conn, "SELECT DATE_FORMAT(report_date,'%Y-%m') ym, COUNT(*) n FROM rescue_reports GROUP BY ym");
if ($rm) while ($m = mysqli_fetch_assoc($rm)) if (isset($months[$m['ym']])) $months[$m['ym']]['n'] = (int)$m['n'];
$max_month = max(1, max(array_column($months, 'n')));

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
$name = e($_SESSION['username'] ?? 'Rescuer');

rescuer_head('overview', 'Rescuer Dashboard');
rescuer_top("$greeting, $name ", 'Welcome to the Pawfinder Rescuer Dashboard');
?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <form method="get" class="filters">
                <select name="species" aria-label="Species filter" onchange="this.form.submit()">
                    <option value="">All pets</option>
                    <?php foreach ($allowed as $a): ?>
                        <option value="<?php echo $a; ?>" <?php echo $sp === $a ? 'selected' : ''; ?>><?php echo $a; ?>s</option>
                    <?php endforeach; ?>
                </select>
            </form>

            <a href="<?php echo e($homeUrl); ?>" class="btn">&larr; Back to Homepage</a>
        </div>

        <div class="grid g4">
            <div class="card stat">
                <h2>Pets needing rescue</h2>
                <div class="num"><strong><?php echo $need; ?></strong><span class="pct"><?php echo $percent; ?>%</span></div>
                <div class="sub">of <?php echo $total; ?> reported pets still waiting</div>
                <div class="meter"><i style="width:<?php echo $percent; ?>%"></i></div>
            </div>
            <div class="card stat">
                <h2>Urgent cases</h2>
                <div class="num"><strong><?php echo $urgent; ?></strong></div>
                <div class="sub">High-urgency, not yet rescued</div>
            </div>
            <div class="card stat">
                <h2>Rescued</h2>
                <div class="num"><strong><?php echo $rescued; ?></strong></div>
                <div class="sub">Pets safely rescued</div>
            </div>
            <div class="card stat">
                <h2>Unread messages</h2>
                <div class="num"><strong><?php echo $unread_count; ?></strong></div>
                <div class="sub">Adopter messages and missing-pet alerts</div>
            </div>
        </div>

        <div class="grid g2">
            <div class="card">
                <h2>Pets needing rescue by species</h2>
                <div class="legend">
                    <span style="--c:var(--teal-2)">Dogs</span>
                    <span style="--c:var(--lav)">Cats</span>
                    <span style="--c:var(--mint)">Birds</span>
                </div>
                <div class="bubbles">
                    <?php $d = bubble($dogs,$bubble_max); $c = bubble($cats,$bubble_max); $b = bubble($birds,$bubble_max); ?>
                    <div class="b1" style="width:<?php echo $d; ?>px;height:<?php echo $d; ?>px"><?php echo $dogs; ?></div>
                    <div class="b2" style="width:<?php echo $c; ?>px;height:<?php echo $c; ?>px"><?php echo $cats; ?></div>
                    <div class="b3" style="width:<?php echo $b; ?>px;height:<?php echo $b; ?>px"><?php echo $birds; ?></div>
                </div>
            </div>
            <div class="card">
                <h2>Rescue reports per month</h2>
                <div class="bars">
                    <?php foreach ($months as $m): ?>
                        <div title="<?php echo $m['n']; ?> report(s)"><i style="height:<?php echo round(($m['n'] / $max_month) * 100); ?>%"></i></div>
                    <?php endforeach; ?>
                </div>
                <div class="bars-x">
                    <?php foreach ($months as $m): ?><span><?php echo $m['label']; ?></span><?php endforeach; ?>
                </div>
            </div>
        </div>

        <section class="panel">
            <div class="panel-head">
                <h2>Most urgent pets to rescue</h2>
                <div class="panel-tools"><a href="reports.php" class="btn">View all reports</a></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Pet</th><th>Gender</th><th>Location</th><th>Urgency</th></tr></thead>
                    <tbody>
                    <?php
                    $res = @mysqli_query($conn, "SELECT *, ($URG_SQL) AS urgency FROM rescue_reports
                        WHERE status <> 'Rescued' $and
                        ORDER BY FIELD(urgency,'High','Medium','Low'), report_date DESC LIMIT 5");
                    if ($res && mysqli_num_rows($res) > 0):
                        while ($r = mysqli_fetch_assoc($res)): ?>
                        <tr>
                            <td><?php echo e($r['pet_type']); ?><br><small><?php echo e($r['pet_condition']); ?></small></td>
                            <td><?php echo e($r['gender'] ?? 'Unknown'); ?></td>
                            <td><?php echo e($r['location']); ?></td>
                            <td><span class="<?php echo pill_class($r['urgency']); ?>"><?php echo e($r['urgency']); ?></span></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="4" class="empty" style="text-align:center">No pets are waiting for rescue right now.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
<?php rescuer_foot(); ?>