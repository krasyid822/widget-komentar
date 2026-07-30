<?php
// ============================
// WIDGET KOMENTAR — index.php
// ============================

// --- Load config.ini (konfigurasi aplikasi, ikut di-deploy) ---
$config = [];
$configFile = __DIR__ . '/config.ini';
if (file_exists($configFile)) {
    $config = parse_ini_file($configFile);
}

$storLimitMB = isset($config['STOR_LIMIT_MB']) ? (float)$config['STOR_LIMIT_MB'] : 1;
$storLimitBytes = $storLimitMB * 1024 * 1024;

// --- Room ---
$room = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['room'] ?? 'default');
if ($room === '') $room = 'default';

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
$dataFile = $dataDir . '/' . $room . '.json';

// --- Load komentar ---
function loadKomentar(string $file): array {
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    // Migrasi otomatis data lama (ID, parent_id, dan format timestamp UTC)
    $updated = false;
    foreach ($data as $idx => &$item) {
        if (!isset($item['id']) || empty($item['id'])) {
            $item['id'] = 'k_legacy_' . $idx . '_' . substr(md5($item['waktu'] ?? $idx), 0, 8);
            $updated = true;
        }
        if (!array_key_exists('parent_id', $item)) {
            $item['parent_id'] = null;
            $updated = true;
        }
        // Migrasi format timestamp lama "YYYY-MM-DD HH:II:SS" -> "YYYY-MM-DDTHH:II:SSZ" (ISO 8601 UTC)
        if (isset($item['waktu']) && !str_contains($item['waktu'], 'T')) {
            $ts = strtotime($item['waktu']);
            if ($ts !== false) {
                $item['waktu'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
                $updated = true;
            }
        }
    }
    unset($item);

    if ($updated) {
        saveKomentar($file, $data);
    }

    return $data;
}

function saveKomentar(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --- Auto-purge: hapus komentar paling lama saat limit tercapai ---
function purgeIfNeeded(string $file, array &$data, float $limitBytes): void {
    while (!empty($data)) {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) <= $limitBytes) break;
        array_shift($data); // hapus komentar paling lama
    }
}

$komentar = loadKomentar($dataFile);
$pesan = '';
$tipepesan = '';

// --- Helper fungsi untuk menghapus komentar dan anak-anaknya secara rekursif ---
function deleteKomentarRecursive(array &$list, string $targetId, string $userToken): bool {
    $found = false;
    foreach ($list as $idx => $k) {
        if (isset($k['id']) && $k['id'] === $targetId) {
            if (isset($k['user_token']) && $k['user_token'] === $userToken) {
                // Hapus komentar beserta balasan-balasannya
                array_splice($list, $idx, 1);
                // Juga hapus segenap komentar yang memiliki parent_id = targetId
                $list = array_values(array_filter($list, function($item) use ($targetId) {
                    return ($item['parent_id'] ?? null) !== $targetId;
                }));
                return true;
            }
            return false;
        }
    }
    return false;
}

// --- POST: Hapus komentar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $deleteId = $_POST['id'] ?? '';
    $userToken = $_POST['user_token'] ?? '';

    if (!empty($deleteId) && !empty($userToken)) {
        $deleted = deleteKomentarRecursive($komentar, $deleteId, $userToken);
        if ($deleted) {
            saveKomentar($dataFile, $komentar);
            $pesan = 'Komentar berhasil dihapus.';
            $tipepesan = 'sukses';
        } else {
            $pesan = 'Tidak dapat menghapus komentar (bukan pemilik atau komentar tidak ditemukan).';
            $tipepesan = 'error';
        }
    }
    
    $redirectRoom = $room !== 'default' ? '?room=' . urlencode($room) : '';
    header('Location: ' . $redirectRoom . '#komentar-list');
    exit;
}

// --- POST: Tambah komentar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'add')) {
    $nama = trim(htmlspecialchars($_POST['nama'] ?? '', ENT_QUOTES, 'UTF-8'));
    $isi  = trim(htmlspecialchars($_POST['komentar'] ?? '', ENT_QUOTES, 'UTF-8'));
    $parentId = trim($_POST['parent_id'] ?? '');
    $userToken = trim($_POST['user_token'] ?? '');

    if ($nama === '' || $isi === '') {
        $pesan = 'Nama dan komentar tidak boleh kosong.';
        $tipepesan = 'error';
    } else {
        $entry = [
            'id'         => uniqid('k_', true),
            'parent_id'  => $parentId !== '' ? $parentId : null,
            'nama'       => $nama,
            'isi'        => $isi,
            'waktu'      => gmdate('Y-m-d\TH:i:s\Z'),
            'user_token' => $userToken,
        ];
        $komentar[] = $entry;
        purgeIfNeeded($dataFile, $komentar, $storLimitBytes);
        saveKomentar($dataFile, $komentar);
        $pesan = 'Komentar berhasil dikirim!';
        $tipepesan = 'sukses';

        // Redirect to avoid re-POST on refresh
        $redirectRoom = $room !== 'default' ? '?room=' . urlencode($room) : '';
        header('Location: ' . $redirectRoom . '#komentar-list');
        exit;
    }
}

// Organisasi Tree Komentar (Hierarki Balasan)
function buildTree(array $items, $parentId = null): array {
    $branch = [];
    foreach ($items as $item) {
        $itemParent = $item['parent_id'] ?? null;
        if ($itemParent === $parentId) {
            $itemId = $item['id'] ?? null;
            if ($itemId !== null) {
                $children = buildTree($items, $itemId);
                $item['children'] = $children;
            } else {
                $item['children'] = [];
            }
            $branch[] = $item;
        }
    }
    return $branch;
}

$treeKomentar = buildTree($komentar);
$jumlah = count($komentar);
$roomLabel = $room !== 'default' ? ' — ' . htmlspecialchars($room) : '';

// --- Info sisa storage ---
$currentBytes = file_exists($dataFile) ? filesize($dataFile) : 0;
$usedPercent  = $storLimitBytes > 0 ? min(100, round($currentBytes / $storLimitBytes * 100)) : 0;
$sisaKB       = max(0, round(($storLimitBytes - $currentBytes) / 1024, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Komentar<?= $roomLabel ?></title>
    <meta name="description" content="Widget komentar sederhana<?= $roomLabel ?>. Tulis komentar dan diskusi bersama.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #16181d;
            --surface: #1e2028;
            --surface2: #252830;
            --border: #2e3140;
            --text: #e2e4ea;
            --text-muted: #7a7f94;
            --accent: #4da6ff;
            --accent-hover: #2e8fe0;
            --accent-active: #1a75c2;
            --error: #ff6b6b;
            --success: #4ecb71;
            --radius: 10px;
            --tree-line: #3a3f52;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 1.5rem 1rem;
            line-height: 1.6;
        }

        .widget {
            max-width: 680px;
            margin: 0 auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .widget-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .widget-header h1 {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--text);
        }

        .badge {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
        }

        .widget-body {
            padding: 1.25rem 1.5rem;
        }

        /* --- Komentar List & Tree Indent (Garis Level) --- */
        .komentar-tree {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .komentar-branch {
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* Sub-komentar (Balasan menjorok / indented tree) */
        .komentar-children {
            margin-left: 1.1rem;
            padding-left: 1.1rem;
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            margin-top: 0.5rem;
            position: relative;
        }

        /* Garis siku horizontal penghubung ke kartu anak */
        .komentar-children > .komentar-branch::before {
            content: "";
            position: absolute;
            top: 1.25rem;
            left: -1.1rem;
            width: 1.1rem;
            height: 2px;
            background-color: var(--tree-line);
            z-index: 1;
        }

        /* Garis vertikal pembuka dari atas */
        .komentar-children > .komentar-branch::after {
            content: "";
            position: absolute;
            top: -0.5rem;
            bottom: -0.65rem;
            left: -1.1rem;
            width: 2px;
            background-color: var(--tree-line);
            z-index: 0;
        }

        /* Potong garis vertikal pada balasan terakhir agar TIDAK tembus mentok ke bawah */
        .komentar-children > .komentar-branch:last-child::after {
            bottom: auto;
            height: 1.8rem;
        }

        .komentar-item {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem 0.85rem;
            animation: fadeIn 0.25s ease;
            position: relative;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .komentar-meta {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
            flex-wrap: wrap;
        }

        .komentar-meta-left {
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            flex-wrap: wrap;
            min-width: 0;
        }

        .komentar-nama {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--accent);
            word-break: break-word;
        }

        /* Tanggal & Jam 2 baris otomatis */
        .komentar-waktu-box {
            display: inline-flex;
            flex-direction: column;
            line-height: 1.1;
            font-size: 0.68rem;
            color: var(--text-muted);
            opacity: 0.85;
        }

        .komentar-tgl, .komentar-jam {
            white-space: nowrap;
        }

        .komentar-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            flex-shrink: 0;
            margin-left: auto;
        }

        .form-delete-inline {
            display: inline-flex;
            margin: 0;
            padding: 0;
            background: transparent !important;
            border: none !important;
        }

        .btn-action {
            background: transparent !important;
            background-color: transparent !important;
            border: none !important;
            outline: none !important;
            box-shadow: none !important;
            -webkit-appearance: none;
            appearance: none;
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            transition: color 0.2s, background 0.2s;
            white-space: nowrap;
        }

        .btn-action:hover {
            color: var(--text);
            background: rgba(255, 255, 255, 0.05) !important;
        }

        .komentar-isi {
            font-size: 0.875rem;
            color: var(--text);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .empty-state {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            padding: 0.5rem 0;
        }

        /* --- Indikator Komentar Aktif Yang Sedang Dibalas --- */
        .komentar-item.active-reply {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(77, 166, 255, 0.2);
            background: rgba(77, 166, 255, 0.05);
        }

        /* --- Pesan notif --- */
        .notif {
            padding: 0.7rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
            border: 1px solid transparent;
        }
        .notif.error   { background: rgba(255,107,107,0.1); border-color: rgba(255,107,107,0.3); color: var(--error); }
        .notif.sukses  { background: rgba(78,203,113,0.1); border-color: rgba(78,203,113,0.3); color: var(--success); }

        /* --- Form --- */
        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
        }

        input[type="text"],
        textarea {
            width: 100%;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-family: inherit;
            font-size: 0.9rem;
            padding: 0.65rem 0.85rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
            resize: vertical;
        }

        input[type="text"]:focus,
        textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(77,166,255,0.12);
        }

        textarea {
            min-height: 90px;
        }

        button[type="submit"] {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: var(--accent);
            color: #fff;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.65rem 1.35rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }

        button[type="submit"]:hover  { background: var(--accent-hover); }
        button[type="submit"]:active { background: var(--accent-active); transform: scale(0.98); }

        /* --- Room info (jika bukan default) --- */
        .room-info {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .room-info code {
            background: var(--surface2);
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
            font-size: 0.75rem;
            color: var(--accent);
        }

        /* --- Storage info --- */
        .storage-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 0.85rem;
            border-top: 1px solid var(--border);
        }

        .storage-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .storage-bar-wrap {
            flex: 1;
            height: 3px;
            background: var(--surface2);
            border-radius: 999px;
            overflow: hidden;
        }

        .storage-bar-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--accent);
            transition: width 0.4s ease, background 0.4s ease;
        }

        /* --- Mobile Responsive Adjustment --- */
        @media (max-width: 480px) {
            body {
                padding: 0.5rem;
            }
            .widget-header, .widget-body {
                padding: 1rem;
            }
            .komentar-children {
                margin-left: 0.65rem;
                padding-left: 0.65rem;
            }
            .komentar-children > .komentar-branch::before {
                left: -0.65rem;
                width: 0.65rem;
            }
            .komentar-children > .komentar-branch::after {
                left: -0.65rem;
            }
            .komentar-item {
                padding: 0.65rem 0.75rem;
            }
        }
    </style>
</head>
<body>

<div class="widget">
    <div class="widget-header">
        <h1>Komentar</h1>
        <span class="badge"><?= $jumlah ?></span>
    </div>

    <div class="widget-body">

        <?php if ($pesan): ?>
            <div class="notif <?= htmlspecialchars($tipepesan) ?>"><?= $pesan ?></div>
        <?php endif; ?>

        <!-- Render Komentar Rekursif dengan Indentasi -->
        <?php
        function renderTree(array $nodes, string $room) {
            foreach ($nodes as $k) {
                ?>
                <div class="komentar-branch">
                    <div class="komentar-item" id="k-<?= htmlspecialchars($k['id'] ?? '') ?>">
                        <div class="komentar-meta">
                            <div class="komentar-meta-left">
                                <span class="komentar-nama"><?= htmlspecialchars($k['nama']) ?></span>
                                <?php
                                    $waktuRaw = $k['waktu'] ?? '';
                                    // Fallback jika format lama (bukan ISO T/Z)
                                    if (!str_contains($waktuRaw, 'T')) {
                                        $parts = explode(' ', $waktuRaw, 2);
                                        $tglFallback = $parts[0] ?? '';
                                        $jamFallback = $parts[1] ?? '';
                                    } else {
                                        $tglFallback = '';
                                        $jamFallback = '';
                                    }
                                ?>
                                <div class="komentar-waktu-box" data-utc="<?= htmlspecialchars($waktuRaw) ?>">
                                    <span class="komentar-tgl"><?= htmlspecialchars($tglFallback) ?></span>
                                    <span class="komentar-jam"><?= htmlspecialchars($jamFallback) ?></span>
                                </div>
                            </div>
                            <div class="komentar-actions">
                                <button type="button" class="btn-action btn-reply" onclick="setReplyTo('<?= htmlspecialchars($k['id']) ?>', '<?= addslashes(htmlspecialchars($k['nama'])) ?>', this)">Balas</button>
                                <?php if (isset($k['id'])): ?>
                                    <button type="button" class="btn-action btn-delete" data-token="<?= htmlspecialchars($k['user_token'] ?? '') ?>" onclick="triggerHapus('<?= htmlspecialchars($k['id']) ?>')" style="display:none;">Hapus</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="komentar-isi"><?= htmlspecialchars($k['isi']) ?></div>
                    </div>

                    <?php if (!empty($k['children'])): ?>
                        <div class="komentar-children">
                            <?php renderTree($k['children'], $room); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
            }
        }
        ?>

        <!-- Daftar komentar -->
        <div id="komentar-list">
            <?php if ($jumlah === 0): ?>
                <p class="empty-state">Belum ada komentar. Jadilah yang pertama!</p>
            <?php else: ?>
                <div class="komentar-tree">
                    <?php renderTree($treeKomentar, $room); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Form kirim komentar -->
        <form method="POST" id="form-komentar" action="<?= $room !== 'default' ? '?room=' . urlencode($room) : '' ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="user_token" id="form-user-token" value="">
            <input type="hidden" name="parent_id" id="form-parent-id" value="">

            <div class="form-group">
                <label for="nama">Nama</label>
                <input type="text" id="nama" name="nama" placeholder="Nama kamu..." maxlength="80" required
                       value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="komentar">Komentar</label>
                <textarea id="komentar" name="komentar" placeholder="Tulis komentar..." maxlength="1000" required><?= htmlspecialchars($_POST['komentar'] ?? '') ?></textarea>
            </div>
            <button type="submit" id="btn-submit">Kirim Komentar</button>
        </form>

        <?php if ($room !== 'default'): ?>
            <div class="room-info">
                Room: <code><?= htmlspecialchars($room) ?></code>
                · <a href="?" style="color: var(--text-muted); font-size: 0.78rem;">Room Global</a>
            </div>
        <?php endif; ?>

        <!-- Storage info -->
        <div class="storage-info" title="Penyimpanan room ini: <?= $usedPercent ?>% terpakai">
            <span class="storage-label">Sisa <?= $sisaKB ?> KB</span>
            <div class="storage-bar-wrap">
                <div class="storage-bar-fill<?= $usedPercent >= 90 ? ' full' : ($usedPercent >= 70 ? ' warn' : '') ?>"
                     style="width: <?= $usedPercent ?>%"></div>
            </div>
            <span class="storage-label"><?= $usedPercent ?>%</span>
        </div>

        <!-- Form Hapus Tersembunyi Global -->
        <form method="POST" id="form-delete-global" action="<?= $room !== 'default' ? '?room=' . urlencode($room) : '' ?>" style="display:none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-global-id" value="">
            <input type="hidden" name="user_token" id="delete-global-user-token" value="">
        </form>

    </div>
</div>

<script>
    // Inisialisasi User Token Unik Per Browser Profil
    function getUserToken() {
        let token = localStorage.getItem('comment_user_token');
        if (!token) {
            token = 'u_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
            localStorage.setItem('comment_user_token', token);
        }
        return token;
    }

    const userToken = getUserToken();
    document.getElementById('form-user-token').value = userToken;

    // Tampilkan tombol hapus HANYA jika token di komentar sesuai token browser saat ini
    document.querySelectorAll('.btn-delete').forEach(btn => {
        const tokenKomentar = btn.getAttribute('data-token');
        if (tokenKomentar && tokenKomentar === userToken) {
            btn.style.display = 'inline-block';
        }
    });

    // Set Mode Balas (Nested Tree)
    function setReplyTo(id, nama, btn) {
        const currentParent = document.getElementById('form-parent-id').value;

        // Reset semua tombol reply ke 'Balas' dan hapus kelas active-reply
        document.querySelectorAll('.btn-reply').forEach(b => {
            b.innerText = 'Balas';
        });
        document.querySelectorAll('.komentar-item').forEach(el => {
            el.classList.remove('active-reply');
        });

        // Jika mengklik komentar yang sedang dalam status dibalas, batalkan
        if (currentParent === id) {
            batalBalas();
            return;
        }

        document.getElementById('form-parent-id').value = id;
        document.getElementById('btn-submit').innerText = 'Kirim Balasan';
        if (btn) {
            btn.innerText = 'Batal Balas';
            const item = btn.closest('.komentar-item');
            if (item) item.classList.add('active-reply');
        }

        const textarea = document.getElementById('komentar');
        textarea.focus();
        document.getElementById('form-komentar').scrollIntoView({ behavior: 'smooth' });
    }

    function batalBalas() {
        document.getElementById('form-parent-id').value = '';
        document.getElementById('btn-submit').innerText = 'Kirim Komentar';
        document.querySelectorAll('.btn-reply').forEach(b => {
            b.innerText = 'Balas';
        });
        document.querySelectorAll('.komentar-item').forEach(el => {
            el.classList.remove('active-reply');
        });
    }

    // Format waktu UTC ke zona waktu lokal pengguna
    document.querySelectorAll('.komentar-waktu-box').forEach(box => {
        const utcStr = box.getAttribute('data-utc');
        if (!utcStr) return;
        
        let dateObj;
        if (utcStr.includes('T')) {
            dateObj = new Date(utcStr);
        } else {
            // Untuk data lama tanpa T/Z (format Y-m-d H:i:s)
            dateObj = new Date(utcStr.replace(' ', 'T') + 'Z');
        }

        if (!isNaN(dateObj.getTime())) {
            const year = dateObj.getFullYear();
            const month = String(dateObj.getMonth() + 1).padStart(2, '0');
            const day = String(dateObj.getDate()).padStart(2, '0');
            
            const hours = String(dateObj.getHours()).padStart(2, '0');
            const minutes = String(dateObj.getMinutes()).padStart(2, '0');
            const seconds = String(dateObj.getSeconds()).padStart(2, '0');

            const tglEl = box.querySelector('.komentar-tgl');
            const jamEl = box.querySelector('.komentar-jam');
            
            if (tglEl) tglEl.innerText = `${year}-${month}-${day}`;
            if (jamEl) jamEl.innerText = `${hours}:${minutes}:${seconds}`;
        }
    });

    // Trigger Hapus via Form Global Tersembunyi
    function triggerHapus(id) {
        if (confirm('Yakin ingin menghapus komentar ini beserta alasannya?')) {
            document.getElementById('delete-global-id').value = id;
            document.getElementById('delete-global-user-token').value = userToken;
            document.getElementById('form-delete-global').submit();
        }
    }
</script>

</body>
</html>
