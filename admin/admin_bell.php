<?php
// admin_bell.php - bell icon + dropdown for new weekly updates.
// Include this file inside the top bar of any admin page (include __DIR__ . '/admin_bell.php').
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['an_csrf'])) { $_SESSION['an_csrf'] = bin2hex(random_bytes(16)); }
?>
<style>
.an-wrap{position:relative;display:inline-block}
.an-bell{position:relative;background:#fff;border:1px solid #e2e8f0;border-radius:12px;width:44px;height:44px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
.an-bell svg{width:22px;height:22px;stroke:#0b5a6b;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.an-badge{position:absolute;top:-7px;right:-7px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:#dc2626;color:#fff;font:700 11px/20px sans-serif;text-align:center;display:none;box-sizing:border-box}
.an-bell.ring svg{animation:anRing .7s ease}
@keyframes anRing{0%,100%{transform:rotate(0)}20%{transform:rotate(16deg)}40%{transform:rotate(-14deg)}60%{transform:rotate(10deg)}80%{transform:rotate(-6deg)}}
.an-drop{display:none;position:absolute;right:0;top:54px;width:370px;max-width:92vw;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.18);z-index:1000;overflow:hidden;text-align:left}
.an-drop.open{display:block}
.an-head{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid #f1f5f9;font:700 14px sans-serif;color:#0f172a}
.an-head button{background:none;border:0;color:#0d9488;font:600 12px sans-serif;cursor:pointer}
.an-list{max-height:380px;overflow-y:auto}
.an-item{display:flex;gap:10px;padding:11px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;align-items:center}
.an-item:hover{background:#f8fafc}
.an-item img,.an-item .an-ph{width:44px;height:44px;border-radius:8px;object-fit:cover;flex:0 0 44px;background:#e2e8f0}
.an-item b{display:block;font:600 13px sans-serif;color:#0f172a}
.an-item small{display:block;font:12px sans-serif;color:#64748b;margin-top:2px}
.an-flag{display:inline-block;font:700 10px sans-serif;background:#fee2e2;color:#991b1b;border-radius:999px;padding:2px 8px;margin-top:4px}
.an-empty{padding:22px;text-align:center;font:13px sans-serif;color:#64748b}
.an-foot{display:block;text-align:center;padding:11px;font:600 13px sans-serif;color:#0d9488;text-decoration:none;border-top:1px solid #f1f5f9}
.an-nav-badge{display:none;margin-left:auto;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#dc2626;color:#fff;font:700 11px/20px sans-serif;text-align:center;box-sizing:border-box}
</style>

<div class="an-wrap" id="anWrap">
    <button type="button" class="an-bell" id="anBell" aria-label="New weekly updates">
        <svg viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>
        <span class="an-badge" id="anBadge">0</span>
    </button>
    <div class="an-drop" id="anDrop">
        <div class="an-head"><span>New weekly updates</span><button type="button" id="anReadAll">Mark all as read</button></div>
        <div class="an-list" id="anList"><div class="an-empty">Loading...</div></div>
        <a class="an-foot" href="admin_updates.php">View all weekly updates</a>
    </div>
</div>

<script>
(function () {
    var bell = document.getElementById('anBell');
    var badge = document.getElementById('anBadge');
    var drop = document.getElementById('anDrop');
    var list = document.getElementById('anList');
    var navBadge = document.getElementById('anNavBadge');
    var CSRF = <?php echo json_encode($_SESSION['an_csrf']); ?>;
    var baseTitle = document.title;
    var lastCount = null;

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function render(d) {
        if (!d || !d.ok) return;
        var n = d.count || 0;

        badge.textContent = n > 99 ? '99+' : n;
        badge.style.display = n > 0 ? 'block' : 'none';
        if (navBadge) {
            navBadge.textContent = n > 99 ? '99+' : n;
            navBadge.style.display = n > 0 ? 'block' : 'none';
        }
        document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle;

        if (lastCount !== null && n > lastCount) {
            bell.classList.remove('ring');
            void bell.offsetWidth;
            bell.classList.add('ring');
        }
        lastCount = n;

        list.textContent = '';
        if (!d.items || !d.items.length) {
            list.appendChild(el('div', 'an-empty', 'No new updates.'));
            return;
        }
        d.items.forEach(function (it) {
            var row = el('div', 'an-item');
            if (it.photo) {
                var img = document.createElement('img');
                img.src = it.photo;
                img.alt = '';
                row.appendChild(img);
            } else {
                row.appendChild(el('div', 'an-ph'));
            }
            var box = el('div');
            box.appendChild(el('b', '', it.adopter + ' sent an update for ' + it.pet));
            box.appendChild(el('small', '', it.ago + (it.health ? ' \u00b7 ' + it.health : '')));
            if (it.needs_review) {
                box.appendChild(el('span', 'an-flag', 'Needs review' + (it.reason ? ': ' + it.reason : '')));
            }
            row.appendChild(box);
            row.addEventListener('click', function () {
                window.location.href = 'admin_updates.php?id=' + it.id;
            });
            list.appendChild(row);
        });
    }

    function load() {
        fetch('admin_notifications.php?action=list', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {});
    }

    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        drop.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
        if (!document.getElementById('anWrap').contains(e.target)) drop.classList.remove('open');
    });

    document.getElementById('anReadAll').addEventListener('click', function () {
        var body = new URLSearchParams();
        body.set('action', 'read_all');
        body.set('csrf', CSRF);
        fetch('admin_notifications.php', { method: 'POST', credentials: 'same-origin', body: body })
            .then(load)
            .catch(function () {});
    });

    load();
    setInterval(load, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
})();
</script>