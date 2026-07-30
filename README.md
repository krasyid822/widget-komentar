# Widget Komentar

Widget komentar berbasis PHP dengan penyimpanan JSON. Mendukung multi-room dan auto-deploy ke FTP via GitHub Actions.

## Fitur

- **Multi-room** — buka room baru dengan `?room=[nama]`
- **JSON storage** — komentar disimpan di `data/[room].json` (satu file per room)
- **Auto-purge** — komentar terlama otomatis dihapus saat mendekati batas ukuran storage (`STOR_LIMIT_MB`)
- **Auto-deploy** — push ke `main` → GitHub Actions deploy ke FTP

## Konfigurasi

Salin `.env-example` ke `.env` lalu isi nilainya:

```
FTP_UNAME = 'username-ftp'
FTP_PW    = 'password-ftp'
FTP_HOST  = 'ftpupload.net'
FTP_PORT  = '21'
FTP_DIR   = 'ftp://host/path/htdocs/'

STOR_LIMIT_MB = '1'
```

## GitHub Secrets (untuk deploy otomatis)

Tambahkan secrets berikut di **Settings → Secrets → Actions**:

| Secret | Nilai |
|--------|-------|
| `FTP_HOST` | hostname FTP |
| `FTP_UNAME` | username FTP |
| `FTP_PW` | password FTP |

## Penggunaan

| URL | Keterangan |
|-----|------------|
| `/` | Room default |
| `/?room=diskusi` | Room bernama "diskusi" |
| `/?room=feedback` | Room bernama "feedback" |

https://komen.site.je/index.php?room=a

## GitHub Secrets

Di sini:

https://github.com/krasyid822/widget-komentar/settings/secrets/actions

Atau navigasi manual:

Repo → Settings → Secrets and variables → Actions

Di sana bisa tambah, edit, atau hapus secrets FTP_HOST, FTP_UNAME, dan FTP_PW.