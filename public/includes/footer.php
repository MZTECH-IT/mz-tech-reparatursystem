    </main><!-- /main-content -->
  </div><!-- /main-wrap -->
</div><!-- /app-shell -->

<!-- ── Sidebar Overlay (Mobile) ── -->
<div id="sidebar-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:150;" onclick="closeSidebar()"></div>

<!-- ── Lightbox ── -->
<div class="lb-overlay" id="lb-overlay" onclick="if(event.target===this)closeLightbox()">
  <button class="lb-close" onclick="closeLightbox()">✕</button>
  <img id="lb-img" src="" alt="">
</div>

<!-- ── Scripts ── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= url('assets/js/app.js') ?>"></script>
<?php if (!empty($extra_js)) echo $extra_js; ?>

<script>
// ── Sidebar Toggle ──
const sidebar  = document.getElementById('sidebar');
const hamburger = document.getElementById('hamburger');
const overlay  = document.getElementById('sidebar-overlay');

function openSidebar()  { sidebar.classList.add('open'); overlay.style.display='block'; }
function closeSidebar() { sidebar.classList.remove('open'); overlay.style.display='none'; }

if (hamburger) hamburger.addEventListener('click', () => {
  sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
});

// ── Dropdown schließen bei Klick außerhalb ──
document.addEventListener('click', e => {
  document.querySelectorAll('.dropdown-menu.open').forEach(m => {
    if (!m.closest('.dropdown').contains(e.target)) m.classList.remove('open');
  });
});

// ── Lightbox ──
function openLightbox(src) {
  document.getElementById('lb-img').src = src;
  document.getElementById('lb-overlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closeLightbox() {
  document.getElementById('lb-overlay').classList.remove('active');
  document.getElementById('lb-img').src = '';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
</script>
</body>
</html>
