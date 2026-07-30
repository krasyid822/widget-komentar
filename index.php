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
    return is_array($data) ? $data : [];
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

// --- POST: tambah komentar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim(htmlspecialchars($_POST['nama'] ?? '', ENT_QUOTES, 'UTF-8'));
    $isi  = trim(htmlspecialchars($_POST['komentar'] ?? '', ENT_QUOTES, 'UTF-8'));

    if ($nama === '' || $isi === '') {
        $pesan = 'Nama dan komentar tidak boleh kosong.';
        $tipepesan = 'error';
    } else {
        $entry = [
            'nama'   => $nama,
            'isi'    => $isi,
            'waktu'  => date('Y-m-d H:i:s'),
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

        /* --- Komentar list --- */
        .komentar-list {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .komentar-item {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.9rem 1rem;
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .komentar-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }

        .komentar-nama {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--accent);
        }

        .komentar-waktu {
            font-size: 0.75rem;
            color: var(--text-muted);
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

        .storage-bar-fill.warn  { background: #f5a623; }
        .storage-bar-fill.full  { background: var(--error); }
    </style>
</head>
<body>

<div class="widget">
    <div class="widget-header">
        <h1>Komentar<?= $roomLabel ?></h1>
        <span class="badge"><?= $jumlah ?></span>
    </div>

    <div class="widget-body">

        <?php if ($pesan): ?>
            <div class="notif <?= htmlspecialchars($tipepesan) ?>"><?= $pesan ?></div>
        <?php endif; ?>

        <!-- Daftar komentar -->
        <div id="komentar-list">
            <?php if ($jumlah === 0): ?>
                <p class="empty-state">Belum ada komentar. Jadilah yang pertama!</p>
            <?php else: ?>
                <div class="komentar-list">
                    <?php foreach (array_reverse($komentar) as $k): ?>
                        <div class="komentar-item">
                            <div class="komentar-meta">
                                <span class="komentar-nama"><?= $k['nama'] ?></span>
                                <span class="komentar-waktu">· <?= $k['waktu'] ?></span>
                            </div>
                            <div class="komentar-isi"><?= $k['isi'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Form kirim komentar -->
        <form method="POST" action="<?= $room !== 'default' ? '?room=' . urlencode($room) : '' ?>">
            <div class="form-group">
                <label for="nama">Nama</label>
                <input type="text" id="nama" name="nama" placeholder="Nama kamu..." maxlength="80" required
                       value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="komentar">Komentar</label>
                <textarea id="komentar" name="komentar" placeholder="Tulis komentar..." maxlength="1000" required><?= htmlspecialchars($_POST['komentar'] ?? '') ?></textarea>
            </div>
            <button type="submit">Kirim Komentar</button>
        </form>

        <?php if ($room !== 'default'): ?>
            <div class="room-info">
                Room: <code><?= htmlspecialchars($room) ?></code>
                · <a href="?" style="color: var(--text-muted); font-size: 0.78rem;">Kembali ke default</a>
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

    </div>
</div>

</body>
</html>
