# Music — webOS App

Wrapper PWA `music.leszczynowa5.pl` jako natywna app dla LG TV (webOS 3.0+).

## Co to robi

iframe pełnoekranowy wskazujący na `https://music.leszczynowa5.pl/`. Działa jak natywna app na TV — bez browser UI, fullscreen, voice/remote keys obsługiwane.

## Pliki

```
app/webos/
├── appinfo.json     ← metadata aplikacji
├── index.html       ← iframe wrapper + back button handling
├── icon.png         ← 80×80 ikona w app launcher
├── largeIcon.png    ← 130×130 ikona dla focus state
├── build.sh         ← build script (bash, nie webOS CLI)
└── README.md        ← ten plik
```

## Build .ipk (bez webOS CLI)

Na serwerze Linux (Ubuntu, Debian, aaPanel host — gdziekolwiek masz bash + ar + tar):

```bash
cd app/webos
chmod +x build.sh
./build.sh
```

Output: `pl.leszczynowa5.music_1.0.0_all.ipk`

Skrypt używa **standardowych narzędzi** systemu (`tar`, `ar`) zamiast webOS CLI. `.ipk` to po prostu archiwum `ar` zawierające `data.tar.gz` + `control.tar.gz` + `debian-binary`.

## Instalacja na TV bez CLI

### Krok 1 — włącz Developer Mode na TV

1. **Settings → All Settings → General → About this TV → Mobile TV On**
2. Wyszukaj w **LG Content Store** → "Developer Mode" → zainstaluj
3. Załóż konto na <https://webostv.developer.lge.com> (darmowe)
4. W appce Developer Mode na TV → zaloguj się → włącz **Dev Mode Status: ON** + **Key Server: ON**
5. Notuj **IP adres TV** wyświetlany w app (np. `192.168.1.42`)

### Krok 2 — wybierz metodę instalacji

#### Metoda A — USB (najprostsze, zero PC config)

1. Skopiuj `pl.leszczynowa5.music_1.0.0_all.ipk` na pendrive USB
2. Wsadź USB do TV
3. W Developer Mode app → **Install from USB** → wybierz plik .ipk
4. App pojawi się w **My Apps**

#### Metoda B — przez przeglądarkę TV

1. Wgraj .ipk na publiczny URL np. `https://music.leszczynowa5.pl/downloads/app.ipk`
2. Plus utwórz prostą stronę `install.html` (template poniżej)
3. W przeglądarce na TV otwórz tę stronę
4. Kliknij "Install" — TV pobierze i zainstaluje

`install.html` template (postaw gdziekolwiek):
```html
<!DOCTYPE html>
<html>
<head><title>Install Music App</title></head>
<body style="font-family:sans-serif;background:#0f0f0f;color:#fff;padding:60px;text-align:center">
    <h1>Install Music for webOS</h1>
    <p>Click below to download the .ipk:</p>
    <a href="https://music.leszczynowa5.pl/downloads/pl.leszczynowa5.music_1.0.0_all.ipk"
       download
       style="display:inline-block;padding:20px 40px;background:#6366f1;color:#fff;border-radius:12px;font-size:24px;text-decoration:none">
        Download .ipk
    </a>
    <p style="margin-top:40px;color:#6b7280">After download, open Developer Mode app → Install from USB / file</p>
</body>
</html>
```

#### Metoda C — SCP/SSH (jeśli umiesz)

Bez CLI ale z SSH. PuTTY lub WSL:

```bash
scp -P 9922 pl.leszczynowa5.music_1.0.0_all.ipk prisoner@192.168.1.42:/tmp/
ssh -p 9922 prisoner@192.168.1.42 'cd /tmp && opkg install pl.leszczynowa5.music_1.0.0_all.ipk'
```

(Hasło SSH/SCP widnieje w Developer Mode app, zazwyczaj `alpine` — to legacy default.)

## Test po instalacji

1. Wróć do home screen TV
2. **My Apps** lub **All Apps** → znajdź "Music"
3. Uruchom — powinno załadować iframe z twoją PWA
4. Pilotem: D-pad nawiguje, ENTER kliknij, BACK wraca

## Update aplikacji

Jeśli zmieniasz UI w PWA (`music.leszczynowa5.pl`) — **NIE musisz przebudowywać** `.ipk`. App ładuje URL przy każdym uruchomieniu, więc aktualizacje są **instant**.

Tylko jeśli zmieniasz `appinfo.json` (np. version bump) lub `index.html` (wrapper logic) — rebuild + reinstall.

## Limitacje

- **Wymaga internetu** — app łączy się z `music.leszczynowa5.pl` przez WiFi/Ethernet
- **WebOS 3.0+** — starsze TV (przed 2016) nie wspierają iframe wrappers w `.ipk`
- **Developer Mode wygasa co 50 godzin użycia** — trzeba odświeżyć w app na TV (przycisk "Renew")
- Po wygaśnięciu trial Developer Mode (1000h = ~41 dni) — trzeba ponownie zalogować

## Troubleshooting

### App się otwiera ale czarny ekran

W przeglądarce normalnej (na PC) otwórz `https://music.leszczynowa5.pl/` — sprawdź czy PWA chodzi. Jeśli nie, fix backend (PHP-FPM, nginx).

### "Cannot install — invalid signature"

webOS sprawdza `Architecture: all` w control file. Jeśli build.sh pominął ten field, edytuj `appinfo.json` i upewnij się że id jest prawidłowy.

### App się zawiesza / nie reaguje na pilot

webOS limit pamięci na app to ~100MB. Twoja PWA z iframe + Spotify covers + audio może to przekroczyć. Sprawdź **Settings → General → Reset memory** na TV i restart app.

### Magic Remote — pointer nie działa

W iframe default events D-pad mapuje na Tab key. Jeśli twoje PWA ma własną remote handling (jam sessions, voice search), trzeba postMessage między iframe a wrapper. Patrz `index.html` keyboard handler dla wzorca.

## Customizacja

### Zmiana URL backendu

W `index.html` zmień:
```html
<iframe src="https://music.leszczynowa5.pl/" ...>
```

Na swoje (np. lokalny dev `http://192.168.1.10/`).

### Dark/light theme

W `appinfo.json` zmień `bgColor` i `iconColor`. Wpływa to na launcher i splash screen.

### Większe ikony

webOS 5.0+ wspiera ikony 1280×720 dla focus state. Jeśli `largeIcon.png` jest tej wielkości, TV użyje pełnoekranowego tła przy focusie.

## Licencja

Internal tool, use as-is.
