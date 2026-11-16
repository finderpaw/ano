<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($conn)) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notif_count.php';

if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])):
    $pmUid  = (int)$_SESSION['user_id'];
    $pmName = $_SESSION['username'] ?? 'User';

    $roleMap = ['visitor'=>'Visitor','local'=>'Local','adopter'=>'Adopter','rescuer'=>'Local Rescuer','admin'=>'Admin'];
    $roleKey = strtolower($_SESSION['role'] ?? '');
    $pmRole  = $roleMap[$roleKey] ?? ucfirst($roleKey);

    $pmPhoto = ''; $pmUnread = 0;
    $pmDue = [];
    try {
        $st = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
        $st->bind_param("i", $pmUid); $st->execute();
        $pmPhoto = $st->get_result()->fetch_assoc()['profile_photo'] ?? '';
        $st->close();

        // Unread: personal notifications + announcements (same as notifications.php)
        $pmUnread = pf_unread_count($conn, $pmUid);

        require_once __DIR__ . '/weekly_helper.php';
        $pmDue = array_values(array_filter(wu_updates_status($conn, $pmUid), fn($w) => $w['due']));
    } catch (Throwable $e) { }

    if (empty($_SESSION['csrf_profile'])) $_SESSION['csrf_profile'] = bin2hex(random_bytes(16));
    $initial   = strtoupper(mb_substr($pmName, 0, 1));
    $pmTotal   = (int)$pmUnread + count($pmDue);   // pang-red dot lang
?>
<style>
.pm{display:flex;align-items:center;gap:6px;font-family:inherit}
.pm-item{position:relative}
.pm button{font:inherit}

.pm-user{display:flex;align-items:center;gap:8px;background:none;border:0;border-radius:999px;padding:4px 10px 4px 4px;cursor:pointer;color:#1f2937}
.pm-user:hover{background:#f1f5f9}
.pm-av{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#0b5a6b;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 auto}
.pm-name{font-size:14px;font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.pm-icon{position:relative;display:flex;align-items:center;justify-content:center;width:40px;height:40px;background:none;border:0;border-radius:50%;cursor:pointer;color:#4b5563}
.pm-icon:hover{background:#f1f5f9;color:#111827}
.pm-icon svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}

/* RED DOT sa gear */
.pm-dot{position:absolute;top:4px;right:4px;width:12px;height:12px;background:#e11d48;border-radius:50%;box-shadow:0 0 0 2px #fff;pointer-events:none}

.pm-user:focus-visible,.pm-icon:focus-visible{outline:2px solid #0b5a6b;outline-offset:2px}

.pm-drop{display:none;position:absolute;right:0;top:calc(100% + 8px);width:310px;background:#fff;border:1px solid #d5e3e7;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:1000;overflow:hidden}
.pm-drop.pm-small{width:240px}
.pm-item.open .pm-drop{display:block}
.pm-head{display:flex;gap:12px;align-items:center;padding:14px;background:#eef6f8}
.pm-head .pm-av{width:48px;height:48px;font-size:20px}
.pm-head b{display:block;color:#0b3f4b}
.pm-role{display:inline-block;margin-top:2px;font-size:12px;background:#0b5a6b;color:#fff;border-radius:999px;padding:2px 10px}
.pm-sec{padding:10px 14px;font-size:13.5px}
.pm-sec + .pm-sec{border-top:1px solid #eef2f4}
.pm-sec h4{margin:0 0 6px;font-size:13px;color:#4a6670}
.pm-file{font-size:12.5px;width:100%;margin-bottom:6px}
.pm-btn{background:#0b5a6b;color:#fff;border:0;border-radius:8px;padding:6px 12px;font-size:13px;cursor:pointer}
.pm-link{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 14px;color:#1f2937;text-decoration:none;font-size:14px}
.pm-link:hover{background:#f1f5f9}
.pm-link + .pm-link{border-top:1px solid #eef2f4}
.pm-link.logout{color:#b42318;font-weight:600}
.pm-link.logout:hover{background:#fef3f2}
.pm-count{background:#e11d48;color:#fff;font-size:11px;font-weight:700;line-height:1;border-radius:9px;padding:3px 7px}

.pm-warn{display:block;text-decoration:none;background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;color:#991b1b;border-radius:8px;padding:8px 10px;margin:0 0 8px;font-size:13px}
.pm-warn:last-child{margin-bottom:0}
.pm-warn b{display:block;color:#b91c1c}
.pm-warn:hover{background:#fee2e2}

body.dark-mode .pm-user{color:#e2e8f0}
body.dark-mode .pm-user:hover,
body.dark-mode .pm-icon:hover{background:#1e293b;color:#f8fafc}
body.dark-mode .pm-icon{color:#cbd5e1}
body.dark-mode .pm-dot{box-shadow:0 0 0 2px #111c33}
body.dark-mode .pm-drop{background:#1e293b;border-color:#334155;box-shadow:0 8px 24px rgba(0,0,0,.5)}
body.dark-mode .pm-head{background:#14303a}
body.dark-mode .pm-head b{color:#e2f7fb}
body.dark-mode .pm-sec + .pm-sec,
body.dark-mode .pm-link + .pm-link{border-color:#334155}
body.dark-mode .pm-sec h4{color:#94a3b8}
body.dark-mode .pm-link{color:#e2e8f0}
body.dark-mode .pm-link:hover{background:#26344f}
body.dark-mode .pm-link.logout{color:#f87171}
body.dark-mode .pm-link.logout:hover{background:#3b1c1c}
body.dark-mode .pm-file{color:#e2e8f0}
body.dark-mode .pm-warn{background:#3b1c1c;border-color:#7f1d1d;border-left-color:#ef4444;color:#fca5a5}
body.dark-mode .pm-warn b{color:#fecaca}
body.dark-mode .pm-warn:hover{background:#4a2222}

@media (max-width:700px){
  .pm-name{display:none}
  .pm-user{padding-right:4px}
  .pm-drop{position:fixed;left:10px;right:10px;top:70px;width:auto}
  .pm-drop.pm-small{width:auto}
}
</style>

<div class="pm" id="pm">

  <div class="pm-item">
    <button type="button" class="pm-user" data-pm aria-haspopup="true" aria-expanded="false" aria-label="Open profile">
      <?php if ($pmPhoto !== ''): ?>
        <img class="pm-av" src="<?= htmlspecialchars($pmPhoto) ?>" alt="">
      <?php else: ?>
        <span class="pm-av"><?= htmlspecialchars($initial) ?></span>
      <?php endif; ?>
      <span class="pm-name"><?= htmlspecialchars($pmName) ?></span>
    </button>
    <div class="pm-drop" role="menu">
      <div class="pm-head">
        <?php if ($pmPhoto !== ''): ?>
          <img class="pm-av" src="<?= htmlspecialchars($pmPhoto) ?>" alt="">
        <?php else: ?>
          <span class="pm-av"><?= htmlspecialchars($initial) ?></span>
        <?php endif; ?>
        <div><b><?= htmlspecialchars($pmName) ?></b><span class="pm-role"><?= htmlspecialchars($pmRole) ?></span></div>
      </div>
      <div class="pm-sec">
        <h4>Profile picture</h4>
        <form action="upload_avatar.php" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_profile']) ?>">
          <input class="pm-file" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
          <button class="pm-btn" type="submit">Upload</button>
        </form>
      </div>
    </div>
  </div>

  <div class="pm-item">
    <button type="button" class="pm-icon" data-pm aria-haspopup="true" aria-expanded="false"
            aria-label="Settings<?= $pmTotal > 0 ? ', may bagong notification' : '' ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
      <?php if ($pmTotal > 0): ?><span class="pm-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
    <div class="pm-drop pm-small" role="menu">
      <?php if ($pmDue): ?>
        <div class="pm-sec">
          <?php foreach ($pmDue as $d): ?>
            <a class="pm-warn" href="weekly_update.php?request_id=<?= (int)$d['request_id'] ?>">
              <b>⚠ Weekly update needed</b>
              Send a photo update of <?= htmlspecialchars($d['pet_name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <a class="pm-link" href="settings.php">Settings</a>
      <?php if ($roleKey === 'rescuer'): ?>
        <a class="pm-link" href="rescue/overview.php">Rescuer Dashboard</a>
      <?php endif; ?>
      <a class="pm-link" href="notifications.php">
        My Notifications
        <?php if ((int)$pmUnread > 0): ?><span class="pm-count"><?= (int)$pmUnread > 99 ? '99+' : (int)$pmUnread ?></span><?php endif; ?>
      </a>
      <a class="pm-link logout" href="logout.php" onclick="return confirm('Are you sure you want to log out?');">Log Out</a>
    </div>
  </div>

</div>

<script>
(function () {
  var items = document.querySelectorAll('#pm .pm-item');
  function closeAll(except) {
    items.forEach(function (it) {
      if (it !== except) {
        it.classList.remove('open');
        var b = it.querySelector('[data-pm]');
        if (b) b.setAttribute('aria-expanded', 'false');
      }
    });
  }
  items.forEach(function (it) {
    var btn = it.querySelector('[data-pm]');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      closeAll(it);
      var open = it.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function (e) {
    if (!document.getElementById('pm').contains(e.target)) closeAll(null);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
})();
</script>
<?php endif; ?>