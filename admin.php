<?php
require __DIR__ . '/includes/init.php';
require_admin();

$IMAGES = [
    'assets/img/massage.jpg' => 'Pijat / Massage', 'assets/img/oil.jpg' => 'Minyak / Oil',
    'assets/img/body.jpg' => 'Batu panas / Hot stone', 'assets/img/facial.jpg' => 'Facial 1',
    'assets/img/facial2.jpg' => 'Facial 2', 'assets/img/package.jpg' => 'Paket / Package',
    'assets/img/stilllife.jpg' => 'Produk / Products', 'assets/img/hands.jpg' => 'Tangan / Hands',
    'assets/img/bath.jpg' => 'Berendam / Bath', 'assets/img/about.jpg' => 'Ruangan / Room',
];
$CONTENT_SECTIONS = [
    ['title' => 'ad.ct.secHero', 'fields' => [
        ['key' => 'hero.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'hero.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'hero.sub', 'label' => 'ad.ct.subt', 'type' => 'textarea'],
    ]],
    ['title' => 'ad.ct.secFeatured', 'fields' => [
        ['key' => 'feat.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'feat.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'feat.sub', 'label' => 'ad.ct.subt', 'type' => 'textarea'],
    ]],
    ['title' => 'ad.ct.secCats', 'fields' => [
        ['key' => 'cats.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'cats.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'cats.sub', 'label' => 'ad.ct.subt', 'type' => 'textarea'],
    ]],
    ['title' => 'ad.ct.secAbout', 'fields' => [
        ['key' => 'about.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'about.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'about.body', 'label' => 'ad.ct.body', 'type' => 'textarea'],
        ['key' => 'about.badgen', 'label' => 'ad.ct.badgeNum', 'type' => 'text'],
        ['key' => 'about.badgel', 'label' => 'ad.ct.badgeCap', 'type' => 'text'],
        ['key' => 'about.f1t', 'label' => 'ad.ct.h1t', 'type' => 'text'],
        ['key' => 'about.f1d', 'label' => 'ad.ct.h1d', 'type' => 'textarea'],
        ['key' => 'about.f2t', 'label' => 'ad.ct.h2t', 'type' => 'text'],
        ['key' => 'about.f2d', 'label' => 'ad.ct.h2d', 'type' => 'textarea'],
        ['key' => 'about.f3t', 'label' => 'ad.ct.h3t', 'type' => 'text'],
        ['key' => 'about.f3d', 'label' => 'ad.ct.h3d', 'type' => 'textarea'],
    ]],
    ['title' => 'ad.ct.secGallery', 'fields' => [
        ['key' => 'gallery.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'gallery.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'gallery.sub', 'label' => 'ad.ct.subt', 'type' => 'textarea'],
    ]],
    ['title' => 'ad.ct.secTesti', 'fields' => [
        ['key' => 'tst.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'tst.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
    ]],
    ['title' => 'ad.ct.secContact', 'fields' => [
        ['key' => 'loc.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'loc.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'loc.address', 'label' => 'ad.ct.address', 'type' => 'textarea'],
        ['key' => 'loc.addresst', 'label' => 'ad.ct.addressLabel', 'type' => 'text'],
        ['key' => 'loc.hours', 'label' => 'ad.ct.hours', 'type' => 'text'],
        ['key' => 'loc.hourst', 'label' => 'ad.ct.hoursLabel', 'type' => 'text'],
        ['key' => 'loc.phone', 'label' => 'ad.ct.phone', 'type' => 'text'],
        ['key' => 'loc.phonet', 'label' => 'ad.ct.phoneLabel', 'type' => 'text'],
    ]],
    ['title' => 'ad.ct.secSocial', 'fields' => [
        ['key' => 'social.instagram', 'label' => 'Instagram', 'type' => 'text', 'single' => true],
        ['key' => 'social.whatsapp',  'label' => 'WhatsApp',  'type' => 'text', 'single' => true],
        ['key' => 'social.facebook',  'label' => 'Facebook',  'type' => 'text', 'single' => true],
    ]],
    ['title' => 'ad.ct.secFaq', 'fields' => [
        ['key' => 'faq.eyebrow', 'label' => 'ad.ct.eyebrow', 'type' => 'text'],
        ['key' => 'faq.title', 'label' => 'ad.ct.heading', 'type' => 'text'],
        ['key' => 'faq.q1', 'label' => 'ad.ct.faqQ', 'type' => 'text'],
        ['key' => 'faq.a1', 'label' => 'ad.ct.faqA', 'type' => 'textarea'],
        ['key' => 'faq.q2', 'label' => 'ad.ct.faqQ', 'type' => 'text'],
        ['key' => 'faq.a2', 'label' => 'ad.ct.faqA', 'type' => 'textarea'],
        ['key' => 'faq.q3', 'label' => 'ad.ct.faqQ', 'type' => 'text'],
        ['key' => 'faq.a3', 'label' => 'ad.ct.faqA', 'type' => 'textarea'],
        ['key' => 'faq.q4', 'label' => 'ad.ct.faqQ', 'type' => 'text'],
        ['key' => 'faq.a4', 'label' => 'ad.ct.faqA', 'type' => 'textarea'],
        ['key' => 'faq.q5', 'label' => 'ad.ct.faqQ', 'type' => 'text'],
        ['key' => 'faq.a5', 'label' => 'ad.ct.faqA', 'type' => 'textarea'],
        ['key' => 'faq.q6', 'label' => 'ad.ct.faqQ', 'type' => 'text'],
        ['key' => 'faq.a6', 'label' => 'ad.ct.faqA', 'type' => 'textarea'],
    ]],
];

$view = $_GET['view'] ?? 'overview';
if (!in_array($view, ['overview', 'services', 'categories', 'gallery', 'bookings', 'content'], true)) $view = 'overview';
$formError = '';
$svcForm = null; // populated when showing the service add/edit form
$catForm = null; // populated when showing the category add/edit form

/* ---------- POST actions (PRG) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete-service') {
        $svcGone = get_service_by_id((int)$_POST['id']);
        delete_service((int)$_POST['id']);
        if ($svcGone) delete_gallery_uploads($svcGone['gallery']);
        set_flash(t('ad.toast.deleted'));
        header('Location: admin.php?view=services'); exit;
    }
    if ($action === 'save-service') {
        $d = [
            'name_id' => trim($_POST['name_id'] ?? ''), 'name_en' => trim($_POST['name_en'] ?? ''),
            'desc_id' => trim($_POST['desc_id'] ?? ''), 'desc_en' => trim($_POST['desc_en'] ?? ''),
            'long_id' => trim($_POST['long_id'] ?? ''), 'long_en' => trim($_POST['long_en'] ?? ''),
            'good_id' => trim($_POST['good_id'] ?? ''), 'good_en' => trim($_POST['good_en'] ?? ''),
            'category' => $_POST['category'] ?? 'massage', 'image' => $_POST['image'] ?? 'assets/img/massage.jpg',
            'duration' => (int)($_POST['duration'] ?? 0), 'price' => (int)($_POST['price'] ?? 0),
        ];
        $validCats = category_slugs();
        if (!in_array($d['category'], $validCats, true)) $d['category'] = $validCats[0] ?? 'massage';
        // Photos: 3 slots. Slot 1 is the cover (services.image); all three form
        // the detail gallery. Per slot an uploaded file wins; otherwise the
        // selected path (the "current photo" option carries the existing one).
        $files  = $_FILES['slot_file'] ?? null;
        $stocks = $_POST['slot_stock'] ?? [];
        $paths  = [];
        for ($i = 0; $i < 3; $i++) {
            $path = '';
            if ($files && !empty($files['name'][$i])) {
                $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                $up = handle_image_upload($one);
                if ($up) $path = $up; else $formError = t('ad.f.uploadErr');
            }
            if ($path === '') {
                $cand = (string)($stocks[$i] ?? '');
                if (array_key_exists($cand, $IMAGES) || strpos($cand, 'assets/img/svc-') === 0) $path = $cand;
            }
            if ($path === '') $path = 'assets/img/massage.jpg';
            $paths[$i] = $path;
        }
        $d['image']   = $paths[0];
        $d['gallery'] = implode(',', array_map('basename', $paths));
        // Long description falls back to the short one when left blank.
        if ($d['long_id'] === '') $d['long_id'] = $d['desc_id'];
        if ($d['long_en'] === '') $d['long_en'] = $d['desc_en'];
        $id = (int)($_POST['id'] ?? 0);
        if ($formError === '' && ($d['name_id'] === '' || $d['name_en'] === '' || $d['duration'] <= 0 || $d['price'] < 0)) {
            $formError = t('ad.f.err');
        }
        if ($formError !== '') {
            $svcForm = array_merge($d, ['id' => $id]);
            $view = 'services';
        } else {
            if ($id) {
                $prev = get_service_by_id($id);
                update_service($id, $d);
                if ($prev) {
                    $keep = array_map('trim', explode(',', $d['gallery']));
                    delete_gallery_uploads($prev['gallery'], $keep);
                }
                set_flash(t('ad.toast.updated'));
            } else {
                $d['slug'] = unique_slug($d['name_en']); $d['popularity'] = 50; add_service($d); set_flash(t('ad.toast.added'));
            }
            header('Location: admin.php?view=services'); exit;
        }
    }
    if ($action === 'save-category') {
        $c = [
            'name_id' => trim($_POST['name_id'] ?? ''), 'name_en' => trim($_POST['name_en'] ?? ''),
        ];
        $id = (int)($_POST['id'] ?? 0);
        if ($c['name_id'] === '' || $c['name_en'] === '') {
            $formError = t('ad.cat.err');
            $catForm = array_merge($c, ['id' => $id]);
            $view = 'categories';
        } else {
            if ($id) { update_category($id, $c); set_flash(t('ad.cat.toastUpdated')); }
            else {
                $c['slug'] = unique_category_slug($c['name_en']);
                $c['color'] = next_category_color();
                $c['sort_order'] = (int)db()->query('SELECT COUNT(*) FROM categories')->fetchColumn();
                add_category($c);
                set_flash(t('ad.cat.toastAdded'));
            }
            header('Location: admin.php?view=categories'); exit;
        }
    }
    if ($action === 'delete-category') {
        $cat = get_category_by_id((int)$_POST['id']);
        if ($cat && category_in_use($cat['slug'])) {
            set_flash(t('ad.cat.inUse'));
        } else {
            delete_category((int)$_POST['id']);
            set_flash(t('ad.cat.toastDeleted'));
        }
        header('Location: admin.php?view=categories'); exit;
    }
    if ($action === 'add-gallery') {
        $img = '';
        if (!empty($_FILES['image_file']['name'])) {
            $up = handle_image_upload($_FILES['image_file']);
            if ($up) $img = $up; else set_flash(t('ad.f.uploadErr'));
        }
        if ($img === '') {
            $cand = $_POST['image'] ?? '';
            if (array_key_exists($cand, $IMAGES)) $img = $cand;
        }
        if ($img !== '') { add_gallery_image($img); set_flash(t('ad.gal.toastAdded')); }
        header('Location: admin.php?view=gallery'); exit;
    }
    if ($action === 'delete-gallery') {
        $gi = get_gallery_image_by_id((int)$_POST['id']);
        delete_gallery_image((int)$_POST['id']);
        if ($gi) delete_uploaded_image($gi['image']);
        set_flash(t('ad.gal.toastDeleted'));
        header('Location: admin.php?view=gallery'); exit;
    }
    if ($action === 'move-gallery') {
        move_gallery_image((int)$_POST['id'], (($_POST['dir'] ?? 'up') === 'down' ? 'down' : 'up'));
        header('Location: admin.php?view=gallery'); exit;
    }
    if ($action === 'booking-status') {
        update_booking_status((int)$_POST['id'], $_POST['status'] ?? 'pending');
        set_flash(t('ad.toast.status'));
        header('Location: admin.php?view=bookings'); exit;
    }
    if ($action === 'delete-booking') {
        delete_booking((int)$_POST['id']);
        set_flash(t('ad.bk.delete'));
        header('Location: admin.php?view=bookings'); exit;
    }
    if ($action === 'save-content') {
        foreach ($CONTENT_SECTIONS as $sec) {
            foreach ($sec['fields'] as $f) {
                $base = str_replace('.', '-', $f['key']);
                if (!empty($f['single'])) {
                    // One value (e.g. a URL), stored in both columns.
                    $val = trim($_POST['ct-' . $base . '-id'] ?? '');
                    $raw = lang_default($f['key'], 'id');
                    if ($val !== '' && $val !== $raw) save_content_override($f['key'], $val, $val);
                    else delete_content_override($f['key']);
                    continue;
                }
                $rawId = lang_default($f['key'], 'id'); $rawEn = lang_default($f['key'], 'en');
                $idv = trim($_POST['ct-' . $base . '-id'] ?? '') ?: $rawId;
                $env = trim($_POST['ct-' . $base . '-en'] ?? '') ?: $rawEn;
                if ($idv !== $rawId || $env !== $rawEn) save_content_override($f['key'], $idv, $env);
                else delete_content_override($f['key']);
            }
        }
        set_flash(t('ad.ct.toastSaved'));
        header('Location: admin.php?view=content'); exit;
    }
    if ($action === 'reset-content') {
        foreach ($CONTENT_SECTIONS as $sec) foreach ($sec['fields'] as $f) delete_content_override($f['key']);
        set_flash(t('ad.ct.toastReset'));
        header('Location: admin.php?view=content'); exit;
    }
}

/* ---------- Edit form trigger ---------- */
if ($view === 'services' && isset($_GET['edit']) && !$svcForm) {
    if ($_GET['edit'] === 'new') {
        $svcForm = ['id' => 0, 'name_id' => '', 'name_en' => '', 'desc_id' => '', 'desc_en' => '', 'long_id' => '', 'long_en' => '', 'good_id' => '', 'good_en' => '', 'gallery' => '', 'category' => (category_slugs()[0] ?? 'massage'), 'image' => 'assets/img/massage.jpg', 'duration' => 60, 'price' => 0];
    } else {
        $s = get_service_by_id((int)$_GET['edit']);
        if ($s) $svcForm = $s;
    }
}
if ($view === 'categories' && isset($_GET['edit']) && !$catForm) {
    if ($_GET['edit'] === 'new') {
        $catForm = ['id' => 0, 'name_id' => '', 'name_en' => ''];
    } else {
        $c = get_category_by_id((int)$_GET['edit']);
        if ($c) $catForm = $c;
    }
}

$flash = get_flash();
$u = current_user();
$PREFIX = ['overview' => 'ov', 'services' => 'sv', 'categories' => 'cat', 'gallery' => 'gal', 'bookings' => 'bk', 'content' => 'ct'];
$catColors = array_column(get_categories(), 'color', 'slug');
$navLang = current_lang();
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= e(t('ad.' . $PREFIX[$view] . '.title')) ?> · Shin Joy Spa Malang</title>
  <script>document.documentElement.className += " js";</script>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Mulish:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<?php
// Suppress the entrance animation on in-list reloads (search / filter / pagination)
// so it doesn't replay on every keystroke; it still plays on first arrival.
$noEntrance = (in_array($view, ['services', 'bookings'], true) && (isset($_GET['q']) || isset($_GET['cat']) || isset($_GET['status']) || isset($_GET['p'])));
?>
<body class="admin-body view-<?= e($view) ?><?= $noEntrance ? ' no-entrance' : '' ?>">
  <div class="admin">
    <aside class="admin-sidebar">
      <div class="admin-brand"><?= brand_mark() ?><span class="wordmark"><b>Shin Joy</b><span><?= te('ad.panel') ?></span></span></div>
      <nav class="admin-nav">
        <a href="admin.php?view=overview" class="<?= $view === 'overview' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span><?= te('ad.nav.overview') ?></span></a>
        <a href="admin.php?view=services" class="<?= $view === 'services' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3c2 3.2 3.4 5.6 3.4 8.2A3.4 3.4 0 0112 14.6a3.4 3.4 0 01-3.4-3.4C8.6 8.6 10 6.2 12 3Z" stroke-linejoin="round"/><path d="M5 20c2-1 4-1.5 7-1.5s5 .5 7 1.5" stroke-linecap="round"/></svg><span><?= te('ad.nav.services') ?></span></a>
        <a href="admin.php?view=categories" class="<?= $view === 'categories' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.6 13.4 10.6 3.4A2 2 0 0 0 9.2 2.8H4a2 2 0 0 0-2 2v5.2a2 2 0 0 0 .6 1.4l10 10a2 2 0 0 0 2.8 0l5.2-5.2a2 2 0 0 0 0-2.8Z" stroke-linejoin="round"/><circle cx="7" cy="7" r="1.4"/></svg><span><?= te('ad.nav.categories') ?></span></a>
        <a href="admin.php?view=gallery" class="<?= $view === 'gallery' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?= te('ad.nav.gallery') ?></span></a>
        <a href="admin.php?view=bookings" class="<?= $view === 'bookings' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke-linecap="round"/></svg><span><?= te('ad.nav.bookings') ?></span></a>
        <a href="admin.php?view=content" class="<?= $view === 'content' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 4h9l5 5v10a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1Z" stroke-linejoin="round"/><path d="M14 4v5h5M8 13h8M8 17h5" stroke-linecap="round"/></svg><span><?= te('ad.nav.content') ?></span></a>
      </nav>
      <div class="admin-side-footer">
        <div class="lang-toggle" role="group" aria-label="Language">
          <a href="<?= e(lang_url('id')) ?>" aria-pressed="<?= $navLang === 'id' ? 'true' : 'false' ?>">ID</a>
          <a href="<?= e(lang_url('en')) ?>" aria-pressed="<?= $navLang === 'en' ? 'true' : 'false' ?>">EN</a>
        </div>
        <a class="admin-logout" href="logout.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M15 12H5M9 8l-4 4 4 4M14 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?= te('ad.logout') ?></span></a>
      </div>
    </aside>

    <div class="admin-main">
      <header class="admin-topbar">
        <div>
          <h1><?= te('ad.' . $PREFIX[$view] . '.title') ?></h1>
          <p class="sub"><?= te('ad.' . $PREFIX[$view] . '.sub') ?></p>
        </div>
        <div class="admin-topbar-actions">
          <a class="link-arrow" href="index.php" target="_blank" rel="noopener"><span><?= te('ad.viewsite') ?></span></a>
          <span class="admin-user"><span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'] ?? 'A', 0, 1))) ?></span><span><?= e($u['name'] ?? 'Admin') ?></span></span>
        </div>
      </header>

      <div class="admin-content">
        <?php if ($flash): ?><div class="toast show" style="position:static;transform:none;margin-bottom:1.4rem;display:inline-flex"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg><span><?= e($flash) ?></span></div><?php endif; ?>

        <?php if ($view === 'overview'):
            $all = get_bookings();
            $today = date('Y-m-d');
            $svcAll = get_services();
            $revenue = array_sum(array_map(fn($b) => (int)$b['price'], $all));
            $pending = count(array_filter($all, fn($b) => $b['status'] === 'pending'));
            $week = date('Y-m-d', strtotime('+7 day'));
            $within7 = count(array_filter($all, fn($b) => $b['booking_date'] >= $today && $b['booking_date'] < $week));
            $catsWith = count(array_unique(array_map(fn($s) => $s['category'], $svcAll)));
            $todayCount = count(array_filter($all, fn($b) => $b['booking_date'] === $today));
            $segs = []; foreach (get_categories() as $cobj) $segs[] = ['key' => $cobj['slug'], 'count' => count_by_cat($cobj['slug']), 'color' => $cobj['color']];
            $days = []; for ($i = 0; $i < 14; $i++) { $dt = date('Y-m-d', strtotime("+$i day")); $days[] = ['label' => (int)date('j', strtotime($dt)), 'full' => fmt_date($dt), 'count' => count(array_filter($all, fn($b) => $b['booking_date'] === $dt))]; }
            $hasUpcoming = array_sum(array_map(fn($d) => $d['count'], $days)) > 0;
            $recent = array_slice($all, 0, 5);
        ?>
          <div class="admin-greeting">
            <h2><?= e(t('ad.greeting') . ' ' . ($u['name'] ?? 'Admin')) ?></h2>
            <p><?= e(long_date_today()) ?></p>
          </div>
          <div class="stat-grid">
            <div class="stat-card rose"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3c2 3.2 3.4 5.6 3.4 8.2A3.4 3.4 0 0112 14.6a3.4 3.4 0 01-3.4-3.4C8.6 8.6 10 6.2 12 3Z" stroke-linejoin="round"/></svg></span><div class="stat-val"><?= count($svcAll) ?></div><div class="stat-label"><?= te('ad.ov.services') ?></div><div class="stat-sub"><?= e(t('ad.ov.subServices', ['n' => $catsWith])) ?></div></div>
            <div class="stat-card gold"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke-linecap="round"/></svg></span><div class="stat-val"><?= count($all) ?></div><div class="stat-label"><?= te('ad.ov.bookings') ?></div><div class="stat-sub"><?= e(t('ad.ov.subPending', ['n' => $pending])) ?></div></div>
            <div class="stat-card sage"><span class="stat-ic"><?= clock_svg() ?></span><div class="stat-val"><?= $todayCount ?></div><div class="stat-label"><?= te('ad.ov.today') ?></div><div class="stat-sub"><?= e(t('ad.ov.subWeek', ['n' => $within7])) ?></div></div>
            <div class="stat-card plum"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v6M18 9v6" stroke-linecap="round"/></svg></span><div class="stat-val"><?= e(rupiah($revenue)) ?></div><div class="stat-label"><?= te('ad.ov.revenue') ?></div><div class="stat-sub"><?= e(t('ad.ov.subFrom', ['n' => count($all)])) ?></div></div>
          </div>
          <div class="chart-row">
            <div class="chart-card">
              <div class="chart-card-head"><h2><?= te('ad.ov.chartBookings') ?></h2><span class="sub"><?= te('ad.ov.chartBookingsSub') ?></span></div>
              <?= $hasUpcoming ? bar_chart_svg($days) : '<div class="chart-empty">' . e(t('ad.ov.chartEmpty')) . '</div>' ?>
            </div>
            <div class="chart-card">
              <div class="chart-card-head"><h2><?= te('ad.ov.chartCats') ?></h2></div>
              <div class="donut-wrap"><?= donut_svg($segs, count($svcAll)) ?><div class="donut-legend"><?php foreach ($segs as $s): ?><div class="legend-item"><span class="legend-dot" style="background:<?= $s['color'] ?>"></span><span class="legend-name"><?= e(cat_label($s['key'])) ?></span><span class="legend-val"><?= $s['count'] ?></span></div><?php endforeach; ?></div></div>
            </div>
          </div>
          <div class="panel">
            <div class="panel-head"><h2><?= te('ad.ov.recent') ?></h2></div>
            <?php if ($recent): ?>
            <div class="adm-table-wrap"><table class="adm-table"><thead><tr><th><?= te('ad.bk.colCustomer') ?></th><th><?= te('ad.bk.colService') ?></th><th><?= te('ad.bk.colSchedule') ?></th><th><?= te('ad.bk.colStatus') ?></th></tr></thead><tbody>
              <?php foreach ($recent as $b): $sname = current_lang() === 'en' ? $b['service_name_en'] : $b['service_name_id']; ?>
              <tr>
                <td><div class="cust"><span class="cust-avatar"><?= e(initials($b['customer_name'])) ?></span><div class="cust-meta"><b><?= e($b['customer_name']) ?></b><small><?= e($b['phone']) ?></small></div></div></td>
                <td><?= e($sname) ?></td>
                <td><?= e(fmt_date($b['booking_date'])) ?> · <?= e($b['booking_time']) ?></td>
                <td><span class="status-badge status-<?= e($b['status']) ?>"><?= e(t('ad.bk.status' . ucfirst($b['status']))) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php else: ?><div class="adm-empty"><?= te('ad.ov.empty') ?></div><?php endif; ?>
          </div>

        <?php elseif ($view === 'services' && $svcForm): ?>
          <div class="panel">
            <div class="panel-head"><div><h2><?= te($svcForm['id'] ? 'ad.f.editTitle' : 'ad.f.addTitle') ?></h2></div></div>
            <form class="content-form" method="post" action="admin.php?view=services" enctype="multipart/form-data">
              <input type="hidden" name="action" value="save-service">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$svcForm['id'] ?>">
              <?php if ($formError): ?><div class="auth-notice" style="margin-top:0;margin-bottom:1.2rem;background:var(--rose-soft);color:var(--rose-deep)"><?= e($formError) ?></div><?php endif; ?>
              <?php
                $imgDefaults = array_keys($IMAGES);
                $galBn = array_values(array_filter(array_map('trim', explode(',', $svcForm['gallery'] ?? ''))));
                $slotPaths = [];
                for ($i = 0; $i < 3; $i++) {
                    if (isset($galBn[$i]) && $galBn[$i] !== '') $slotPaths[$i] = 'assets/img/' . $galBn[$i];
                    else $slotPaths[$i] = $imgDefaults[$i] ?? ($imgDefaults[0] ?? 'assets/img/massage.jpg');
                }
                if (empty($galBn) && !empty($svcForm['image'])) $slotPaths[0] = $svcForm['image'];
              ?>
              <div class="content-grid">
                <div class="field full"><label><?= te('ad.f.photos') ?></label>
                  <div class="svc-slots">
                    <?php for ($i = 0; $i < 3; $i++): $sp = $slotPaths[$i]; ?>
                    <div class="svc-slot">
                      <span class="svc-slot__tag"><?= e(t('ad.f.photoN', ['n' => $i + 1])) ?><?= $i === 0 ? ' · ' . e(t('ad.f.cover')) : '' ?></span>
                      <img class="svc-slot__img" id="slot-prev-<?= $i ?>" src="<?= e($sp) ?>" alt="">
                      <input type="file" name="slot_file[]" accept="image/jpeg,image/png,image/webp" onchange="var f=this.files[0]; if(f){var r=new FileReader(); r.onload=function(e){document.getElementById('slot-prev-<?= $i ?>').src=e.target.result;}; r.readAsDataURL(f);}">
                      <input type="hidden" name="slot_stock[]" value="<?= e($sp) ?>"><!-- ponytail: carries current photo so no-upload keeps it; dropdown removed -->
                    </div>
                    <?php endfor; ?>
                  </div>
                  <p class="form-hint"><?= te('ad.f.photosHint') ?></p>
                </div>
                <div class="field"><label for="name_id"><?= te('ad.f.nameId') ?> <span class="req" aria-hidden="true">*</span></label><input type="text" id="name_id" name="name_id" value="<?= e($svcForm['name_id']) ?>" required></div>
                <div class="field"><label for="name_en"><?= te('ad.f.nameEn') ?> <span class="req" aria-hidden="true">*</span></label><input type="text" id="name_en" name="name_en" value="<?= e($svcForm['name_en']) ?>" required></div>
                <div class="field full"><label for="category"><?= te('ad.f.cat') ?></label><select id="category" name="category"><?php foreach (get_categories() as $cobj): ?><option value="<?= e($cobj['slug']) ?>"<?= $svcForm['category'] === $cobj['slug'] ? ' selected' : '' ?>><?= e(cat_label($cobj['slug'])) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="duration"><?= te('ad.f.dur') ?> <span class="req" aria-hidden="true">*</span></label><input type="number" id="duration" name="duration" min="5" max="600" step="5" value="<?= (int)$svcForm['duration'] ?>" required></div>
                <div class="field"><label for="price"><?= te('ad.f.price') ?> <span class="req" aria-hidden="true">*</span></label><input type="number" id="price" name="price" min="0" step="1000" value="<?= (int)$svcForm['price'] ?>" required></div>
                <div class="field full"><label for="desc_id"><?= te('ad.f.descId') ?></label><textarea id="desc_id" name="desc_id" rows="2"><?= e($svcForm['desc_id']) ?></textarea></div>
                <div class="field full"><label for="desc_en"><?= te('ad.f.descEn') ?></label><textarea id="desc_en" name="desc_en" rows="2"><?= e($svcForm['desc_en']) ?></textarea></div>
                <div class="field full"><label for="long_id"><?= te('ad.f.longId') ?></label><textarea id="long_id" name="long_id" rows="3"><?= e($svcForm['long_id'] ?? '') ?></textarea></div>
                <div class="field full"><label for="long_en"><?= te('ad.f.longEn') ?></label><textarea id="long_en" name="long_en" rows="3"><?= e($svcForm['long_en'] ?? '') ?></textarea></div>
                <div class="field"><label for="good_id"><?= te('ad.f.goodId') ?></label><input type="text" id="good_id" name="good_id" maxlength="120" value="<?= e($svcForm['good_id'] ?? '') ?>"></div>
                <div class="field"><label for="good_en"><?= te('ad.f.goodEn') ?></label><input type="text" id="good_en" name="good_en" maxlength="120" value="<?= e($svcForm['good_en'] ?? '') ?>"></div>
              </div>
              <div class="content-actions">
                <a class="btn btn--ghost" href="admin.php?view=services"><?= te('ad.f.cancel') ?></a>
                <button type="submit" class="btn btn--primary"><?= te('ad.f.save') ?></button>
              </div>
            </form>
          </div>

        <?php elseif ($view === 'services'):
            $q = trim($_GET['q'] ?? '');
            $cat = $_GET['cat'] ?? 'all';
            if ($cat !== 'all' && !in_array($cat, category_slugs(), true)) $cat = 'all';
            $all = get_services(['category' => $cat, 'search' => $q]);
            $total = count($all);
            $perPage = 8;
            $pages = max(1, (int)ceil($total / $perPage));
            $p = max(1, min($pages, (int)($_GET['p'] ?? 1)));
            $list = array_slice($all, ($p - 1) * $perPage, $perPage);
            $qsBase = 'admin.php?view=services' . ($q !== '' ? '&q=' . urlencode($q) : '') . ($cat !== 'all' ? '&cat=' . urlencode($cat) : '');
        ?>
          <div class="panel">
            <div class="panel-head">
              <span class="sub"><?= e(t('cats.count', ['n' => $total])) ?></span>
              <div class="panel-head-actions"><a class="btn btn--primary" href="admin.php?view=services&edit=new"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg><span><?= te('ad.sv.add') ?></span></a></div>
            </div>
            <form class="adm-toolbar" method="get" action="admin.php">
              <input type="hidden" name="view" value="services">
              <div class="adm-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4" stroke-linecap="round"/></svg>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('ad.sv.searchPh')) ?>">
              </div>
              <select name="cat" aria-label="<?= e(t('ad.f.cat')) ?>" onchange="this.form.submit()">
                <option value="all"<?= $cat === 'all' ? ' selected' : '' ?>><?= e(t('ad.sv.allCats')) ?></option>
                <?php foreach (get_categories() as $cobj): ?><option value="<?= e($cobj['slug']) ?>"<?= $cat === $cobj['slug'] ? ' selected' : '' ?>><?= e(cat_label($cobj['slug'])) ?></option><?php endforeach; ?>
              </select>
              <?php if ($q !== '' || $cat !== 'all'): ?><a class="adm-clear" href="admin.php?view=services"><?= te('ad.sv.clear') ?></a><?php endif; ?>
            </form>
            <?php if ($list): ?>
            <div class="adm-table-wrap"><table class="adm-table"><thead><tr><th><?= te('ad.sv.colService') ?></th><th><?= te('ad.sv.colCat') ?></th><th><?= te('ad.sv.colDur') ?></th><th><?= te('ad.sv.colPrice') ?></th><th class="right"><?= te('ad.sv.colActions') ?></th></tr></thead><tbody>
              <?php foreach ($list as $s): ?>
              <tr>
                <td><div class="adm-cell-service"><img src="<?= e($s['image']) ?>" alt="" loading="lazy"><div><b><?= e(svc_name($s)) ?></b><small><?= e(current_lang() === 'id' ? $s['name_en'] : $s['name_id']) ?></small></div></div></td>
                <td><span class="cat-chip"><?= e(cat_label($s['category'])) ?></span></td>
                <td><?= e(dur_label($s['duration'])) ?></td>
                <td class="price-cell"><?= e(rupiah($s['price'])) ?></td>
                <td><div class="row-actions">
                  <a class="icon-btn" href="admin.php?view=services&edit=<?= (int)$s['id'] ?>" aria-label="<?= e(t('ad.sv.edit')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 20h4L18 10l-4-4L4 16v4Z" stroke-linejoin="round"/><path d="M13 6l4 4"/></svg></a>
                  <form method="post" action="admin.php?view=services" onsubmit="return confirm('<?= e(t('ad.sv.deleteConfirm')) ?>')" style="display:inline"><input type="hidden" name="action" value="delete-service"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="icon-btn danger" type="submit" aria-label="<?= e(t('ad.sv.delete')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form>
                </div></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($pages > 1): ?>
            <nav class="adm-pagination" aria-label="Pagination">
              <a class="adm-page<?= $p <= 1 ? ' is-disabled' : '' ?>"<?= $p > 1 ? ' href="' . e($qsBase . '&p=' . ($p - 1)) . '"' : '' ?> aria-label="<?= e(t('ad.sv.prev')) ?>">‹</a>
              <?php for ($i = 1; $i <= $pages; $i++): ?>
              <a class="adm-page<?= $i === $p ? ' is-current' : '' ?>" href="<?= e($qsBase . '&p=' . $i) ?>"><?= $i ?></a>
              <?php endfor; ?>
              <a class="adm-page<?= $p >= $pages ? ' is-disabled' : '' ?>"<?= $p < $pages ? ' href="' . e($qsBase . '&p=' . ($p + 1)) . '"' : '' ?> aria-label="<?= e(t('ad.sv.next')) ?>">›</a>
            </nav>
            <?php endif; ?>
            <?php else: ?>
            <div class="adm-empty"><?= te('ad.sv.noResults') ?></div>
            <?php endif; ?>
          </div>

        <?php elseif ($view === 'categories' && $catForm): ?>
          <div class="panel">
            <div class="panel-head"><div><h2><?= te($catForm['id'] ? 'ad.cat.editTitle' : 'ad.cat.addTitle') ?></h2></div></div>
            <form class="content-form" method="post" action="admin.php?view=categories">
              <input type="hidden" name="action" value="save-category">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$catForm['id'] ?>">
              <?php if ($formError): ?><div class="auth-notice" style="margin-top:0;margin-bottom:1.2rem;background:var(--rose-soft);color:var(--rose-deep)"><?= e($formError) ?></div><?php endif; ?>
              <div class="content-grid">
                <div class="field"><label for="name_id"><?= te('ad.f.nameId') ?> <span class="req" aria-hidden="true">*</span></label><input type="text" id="name_id" name="name_id" value="<?= e($catForm['name_id']) ?>" required></div>
                <div class="field"><label for="name_en"><?= te('ad.f.nameEn') ?> <span class="req" aria-hidden="true">*</span></label><input type="text" id="name_en" name="name_en" value="<?= e($catForm['name_en']) ?>" required></div>
              </div>
              <p class="form-hint"><?= te('ad.cat.imgHint') ?></p>
              <div class="content-actions">
                <a class="btn btn--ghost" href="admin.php?view=categories"><?= te('ad.f.cancel') ?></a>
                <button type="submit" class="btn btn--primary"><?= te('ad.f.save') ?></button>
              </div>
            </form>
          </div>

        <?php elseif ($view === 'categories'): $clist = get_categories(); ?>
          <div class="panel">
            <div class="panel-head">
              <span class="sub"><?= count($clist) ?> <?= e(mb_strtolower(t('ad.nav.categories'))) ?></span>
              <div class="panel-head-actions"><a class="btn btn--primary" href="admin.php?view=categories&edit=new"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg><span><?= te('ad.cat.add') ?></span></a></div>
            </div>
            <?php if ($clist): ?>
            <div class="adm-table-wrap"><table class="adm-table"><thead><tr><th><?= te('ad.cat.colName') ?></th><th><?= te('ad.cat.colCount') ?></th><th class="right"><?= te('ad.cat.colActions') ?></th></tr></thead><tbody>
              <?php foreach ($clist as $cobj): $used = count_by_cat($cobj['slug']); ?>
              <tr>
                <td><div class="adm-cell-service"><img src="<?= e(cat_image($cobj['slug'])) ?>" alt="" loading="lazy"><div><b><span class="cat-swatch" style="background:<?= e($cobj['color']) ?>"></span><?= e(current_lang() === 'en' ? $cobj['name_en'] : $cobj['name_id']) ?></b><small><?= e(current_lang() === 'en' ? $cobj['name_id'] : $cobj['name_en']) ?></small></div></div></td>
                <td><?= (int)$used ?></td>
                <td><div class="row-actions">
                  <a class="icon-btn" href="admin.php?view=categories&edit=<?= (int)$cobj['id'] ?>" aria-label="<?= e(t('ad.cat.edit')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 20h4L18 10l-4-4L4 16v4Z" stroke-linejoin="round"/><path d="M13 6l4 4"/></svg></a>
                  <?php if ($used > 0): ?>
                  <span class="icon-btn" aria-disabled="true" title="<?= e(t('ad.cat.inUse')) ?>" style="opacity:.38;cursor:not-allowed"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <?php else: ?>
                  <form method="post" action="admin.php?view=categories" onsubmit="return confirm('<?= e(t('ad.cat.deleteConfirm')) ?>')" style="display:inline"><input type="hidden" name="action" value="delete-category"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$cobj['id'] ?>"><button class="icon-btn danger" type="submit" aria-label="<?= e(t('ad.cat.delete')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form>
                  <?php endif; ?>
                </div></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php else: ?><div class="adm-empty"><?= te('ad.cat.empty') ?></div><?php endif; ?>
          </div>

        <?php elseif ($view === 'gallery'): $glist = get_gallery_images(); ?>
          <div class="panel">
            <div class="panel-head"><span class="sub"><?= count($glist) ?> <?= e(mb_strtolower(t('ad.nav.gallery'))) ?></span></div>
            <form class="content-form" method="post" action="admin.php?view=gallery" enctype="multipart/form-data">
              <input type="hidden" name="action" value="add-gallery">
              <?= csrf_field() ?>
              <div class="gal-add">
                <input type="file" name="image_file" accept="image/jpeg,image/png,image/webp" required>
                <button type="submit" class="btn btn--primary"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg><span><?= te('ad.gal.add') ?></span></button>
              </div>
            </form>
            <?php if ($glist): ?>
            <div class="gal-grid">
              <?php foreach ($glist as $i => $gi): ?>
              <div class="gal-item">
                <img src="<?= e($gi['image']) ?>" alt="" loading="lazy">
                <div class="gal-item__bar">
                  <form method="post" action="admin.php?view=gallery"><input type="hidden" name="action" value="move-gallery"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$gi['id'] ?>"><input type="hidden" name="dir" value="up"><button class="icon-btn" type="submit" aria-label="<?= e(t('ad.gal.up')) ?>"<?= $i === 0 ? ' disabled' : '' ?>>&uarr;</button></form>
                  <form method="post" action="admin.php?view=gallery"><input type="hidden" name="action" value="move-gallery"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$gi['id'] ?>"><input type="hidden" name="dir" value="down"><button class="icon-btn" type="submit" aria-label="<?= e(t('ad.gal.down')) ?>"<?= $i === count($glist) - 1 ? ' disabled' : '' ?>>&darr;</button></form>
                  <form method="post" action="admin.php?view=gallery" onsubmit="return confirm('<?= e(t('ad.gal.deleteConfirm')) ?>')"><input type="hidden" name="action" value="delete-gallery"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$gi['id'] ?>"><button class="icon-btn danger" type="submit" aria-label="<?= e(t('ad.gal.delete')) ?>">&times;</button></form>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?><div class="adm-empty"><?= te('ad.gal.empty') ?></div><?php endif; ?>
          </div>

        <?php elseif ($view === 'bookings'):
            $statuses = ['pending', 'confirmed', 'done'];
            $q = trim($_GET['q'] ?? '');
            $status = $_GET['status'] ?? 'all';
            if ($status !== 'all' && !in_array($status, $statuses, true)) $status = 'all';
            $allB = get_bookings(['status' => $status, 'search' => $q]);
            $total = count($allB);
            $perPage = 8;
            $pages = max(1, (int)ceil($total / $perPage));
            $p = max(1, min($pages, (int)($_GET['p'] ?? 1)));
            $list = array_slice($allB, ($p - 1) * $perPage, $perPage);
            $qsBase = 'admin.php?view=bookings' . ($q !== '' ? '&q=' . urlencode($q) : '') . ($status !== 'all' ? '&status=' . urlencode($status) : '');
        ?>
          <div class="panel">
            <div class="panel-head"><span class="sub"><?= $total ?> <?= e(mb_strtolower(t('ad.nav.bookings'))) ?></span></div>
            <form class="adm-toolbar" method="get" action="admin.php">
              <input type="hidden" name="view" value="bookings">
              <div class="adm-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4" stroke-linecap="round"/></svg>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('ad.bk.searchPh')) ?>">
              </div>
              <select name="status" aria-label="<?= e(t('ad.bk.colStatus')) ?>" onchange="this.form.submit()">
                <option value="all"<?= $status === 'all' ? ' selected' : '' ?>><?= e(t('ad.bk.allStatus')) ?></option>
                <?php foreach ($statuses as $st): ?><option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= e(t('ad.bk.status' . ucfirst($st))) ?></option><?php endforeach; ?>
              </select>
              <?php if ($q !== '' || $status !== 'all'): ?><a class="adm-clear" href="admin.php?view=bookings"><?= te('ad.sv.clear') ?></a><?php endif; ?>
            </form>
            <?php if ($list): ?>
            <div class="adm-table-wrap"><table class="adm-table"><thead><tr><th><?= te('ad.bk.colCustomer') ?></th><th><?= te('ad.bk.colService') ?></th><th><?= te('ad.bk.colSchedule') ?></th><th><?= te('ad.bk.colGuests') ?></th><th><?= te('ad.bk.colStatus') ?></th><th class="right"><?= te('ad.bk.colActions') ?></th></tr></thead><tbody>
              <?php foreach ($list as $b): $sname = current_lang() === 'en' ? $b['service_name_en'] : $b['service_name_id']; ?>
              <tr>
                <td><div class="cust"><span class="cust-avatar"><?= e(initials($b['customer_name'])) ?></span><div class="cust-meta"><b><?= e($b['customer_name']) ?></b><small><?= e($b['phone']) ?></small></div></div></td>
                <td><?= e($sname) ?></td>
                <td><?= e(fmt_date($b['booking_date'])) ?> · <?= e($b['booking_time']) ?></td>
                <td><?= (int)$b['guests'] ?></td>
                <td><form method="post" action="admin.php?view=bookings" style="margin:0"><input type="hidden" name="action" value="booking-status"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><select name="status" class="status-select status-<?= e($b['status']) ?>" onchange="this.form.submit()"><?php foreach ($statuses as $st): ?><option value="<?= $st ?>"<?= $b['status'] === $st ? ' selected' : '' ?>><?= e(t('ad.bk.status' . ucfirst($st))) ?></option><?php endforeach; ?></select></form></td>
                <td><div class="row-actions"><form method="post" action="admin.php?view=bookings" onsubmit="return confirm('<?= e(t('ad.bk.deleteConfirm')) ?>')" style="display:inline"><input type="hidden" name="action" value="delete-booking"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="icon-btn danger" type="submit" aria-label="<?= e(t('ad.bk.delete')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form></div></td>
              </tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($pages > 1): ?>
            <nav class="adm-pagination" aria-label="Pagination">
              <a class="adm-page<?= $p <= 1 ? ' is-disabled' : '' ?>"<?= $p > 1 ? ' href="' . e($qsBase . '&p=' . ($p - 1)) . '"' : '' ?> aria-label="<?= e(t('ad.sv.prev')) ?>">‹</a>
              <?php for ($i = 1; $i <= $pages; $i++): ?>
              <a class="adm-page<?= $i === $p ? ' is-current' : '' ?>" href="<?= e($qsBase . '&p=' . $i) ?>"><?= $i ?></a>
              <?php endfor; ?>
              <a class="adm-page<?= $p >= $pages ? ' is-disabled' : '' ?>"<?= $p < $pages ? ' href="' . e($qsBase . '&p=' . ($p + 1)) . '"' : '' ?> aria-label="<?= e(t('ad.sv.next')) ?>">›</a>
            </nav>
            <?php endif; ?>
            <?php elseif ($q !== '' || $status !== 'all'): ?>
            <div class="adm-empty"><?= te('ad.bk.noResults') ?></div>
            <?php else: ?>
            <div class="adm-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke-linecap="round"/></svg><?= te('ad.bk.empty') ?></div>
            <?php endif; ?>
          </div>

        <?php elseif ($view === 'content'): ?>
          <form method="post" action="admin.php?view=content">
            <input type="hidden" name="action" value="save-content">
            <?= csrf_field() ?>
            <div id="content-sections">
              <?php foreach ($CONTENT_SECTIONS as $sec): ?>
              <div class="panel">
                <div class="panel-head"><div><h2><?= e(t($sec['title'])) ?></h2></div></div>
                <div class="content-section-body"><div class="content-grid">
                  <?php foreach ($sec['fields'] as $f): $base = str_replace('.', '-', $f['key']); $full = (!empty($f['single']) || $f['type'] === 'textarea') ? ' full' : ''; ?>
                    <?php if (!empty($f['single'])): ?>
                    <div class="field full"><label for="ct-<?= $base ?>-id"><?= e(t($f['label'])) ?></label><input type="url" id="ct-<?= $base ?>-id" name="ct-<?= $base ?>-id" maxlength="200" placeholder="https://…" value="<?= e(content_value($f['key'], 'id')) ?>"></div>
                    <?php else: ?>
                    <div class="field<?= $full ?>"><label for="ct-<?= $base ?>-id"><?= e(t($f['label']) . ' ' . t('ad.ct.langId')) ?></label><?php if ($f['type'] === 'textarea'): ?><textarea id="ct-<?= $base ?>-id" name="ct-<?= $base ?>-id" rows="2" maxlength="220"><?= e(content_value($f['key'], 'id')) ?></textarea><?php else: ?><input type="text" id="ct-<?= $base ?>-id" name="ct-<?= $base ?>-id" maxlength="90" value="<?= e(content_value($f['key'], 'id')) ?>"><?php endif; ?></div>
                    <div class="field<?= $full ?>"><label for="ct-<?= $base ?>-en"><?= e(t($f['label']) . ' ' . t('ad.ct.langEn')) ?></label><?php if ($f['type'] === 'textarea'): ?><textarea id="ct-<?= $base ?>-en" name="ct-<?= $base ?>-en" rows="2" maxlength="220"><?= e(content_value($f['key'], 'en')) ?></textarea><?php else: ?><input type="text" id="ct-<?= $base ?>-en" name="ct-<?= $base ?>-en" maxlength="90" value="<?= e(content_value($f['key'], 'en')) ?>"><?php endif; ?></div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div></div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="content-actions">
              <button type="submit" form="reset-content-form" class="btn btn--ghost" onclick="return confirm('<?= e(t('ad.ct.resetAllConfirm')) ?>')"><?= te('ad.ct.resetAll') ?></button>
              <button type="submit" class="btn btn--primary"><?= te('ad.ct.saveAll') ?></button>
            </div>
          </form>
          <form id="reset-content-form" method="post" action="admin.php?view=content"><input type="hidden" name="action" value="reset-content"><?= csrf_field() ?></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script src="assets/js/main.js"></script>
</body>
</html>
