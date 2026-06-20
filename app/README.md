# Doniixify — build wrapperów

Skrypty Python do generowania:
- **`Doniixify.exe`** (Windows desktop) — `pywebview` + `PyInstaller`
- **`Doniixify.apk`** (Android) — `bubblewrap` (Google official TWA)

## Setup (jednorazowo)

```cmd
cd app
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

Dla Android też zainstaluj:
- **Node.js 18+** — https://nodejs.org/
- **Java JDK 17+** — https://adoptium.net/ (Temurin 17 LTS)

## Build desktop (.exe)

```cmd
python build_desktop.py
```

Output: `app/dist/Doniixify.exe` (~30-50 MB, single file). Klik → otwiera się aplikacja w oknie webview bez UI Chrome.

## Build Android (.apk)

```cmd
python build_android.py
```

Output: `app/android/app-release-signed.apk`. Skopiuj na telefon, włącz "Install unknown apps" w settings, install. APK podpisany kluczem auto-generowanym (zapisz `android/android.keystore` na potem żeby móc updateować).

## Build oba na raz

```cmd
python build_all.py
```

## Test bez budowania (dev mode)

### Desktop

```cmd
python main.py
```

Otwiera aplikację w pywebview oknie. Tryb dev — szybko sprawdzasz czy URL się ładuje, bez 3-minutowego buildu.

### Android

Wymaga `adb` w PATH (Android Platform Tools — https://developer.android.com/tools/releases/platform-tools).

```cmd
python dev_android.py             # otwiera URL w Chrome na podłączonym telefonie/emulatorze
python dev_android.py --apk       # install + run zbudowane APK
python dev_android.py --logcat    # tail logu z urządzenia (debug)
python dev_android.py --uninstall # odinstaluj testowy APK
```

**Telefon przez USB**: włącz "Developer options" (Settings → About → tap "Build number" 7×) → włącz "USB debugging" → podłącz kablem → telefon zapyta "Allow USB debugging?" → Allow.

**Emulator**: Android Studio → Device Manager → Create/Start virtual device.

Pierwszy tryb (`python dev_android.py`) **nie wymaga buildu APK** — szybko otwiera serwis w mobile Chrome żeby sprawdzić jak wygląda na Androidzie. Możesz dodać do home screen z menu Chrome (⋮ → "Add to Home screen") jako PWA — to też installuje aplikację ale bez APK.
