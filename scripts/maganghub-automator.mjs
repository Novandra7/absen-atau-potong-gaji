import puppeteer from 'puppeteer-core';
import fs from 'fs';
import path from 'path';

// Ensure writable HOME and XDG directories for Chromium in Docker / www-data
if (!process.env.HOME || process.env.HOME === '/var/www') {
  process.env.HOME = '/tmp';
}
if (!process.env.XDG_CONFIG_HOME) {
  process.env.XDG_CONFIG_HOME = '/tmp/.chromium_config';
}
if (!process.env.XDG_CACHE_HOME) {
  process.env.XDG_CACHE_HOME = '/tmp/.chromium_cache';
}

/**
 * Locate Chrome/Chromium executable on the system.
 */
function findChromeExecutable() {
  if (process.env.PUPPETEER_EXECUTABLE_PATH && fs.existsSync(process.env.PUPPETEER_EXECUTABLE_PATH)) {
    return process.env.PUPPETEER_EXECUTABLE_PATH;
  }

  const candidates = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
  ];

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  throw new Error('Chrome/Chromium executable not found. Please set PUPPETEER_EXECUTABLE_PATH.');
}

/**
 * Save cookies and localStorage to session file.
 */
async function saveSession(page, sessionFile) {
  try {
    const dir = path.dirname(sessionFile);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }

    const cookies = await page.cookies();
    const localStorageData = await page.evaluate(() => {
      const data = {};
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        data[key] = localStorage.getItem(key);
      }
      return data;
    });

    fs.writeFileSync(sessionFile, JSON.stringify({ cookies, localStorage: localStorageData }, null, 2));
  } catch (err) {
    console.error('Warning: Failed to save session:', err.message);
  }
}

/**
 * Restore cookies and localStorage from session file.
 */
async function restoreSession(page, sessionFile) {
  if (!sessionFile || !fs.existsSync(sessionFile)) {
    return false;
  }

  try {
    const raw = fs.readFileSync(sessionFile, 'utf-8');
    const data = JSON.parse(raw);

    if (Array.isArray(data.cookies) && data.cookies.length > 0) {
      await page.setCookie(...data.cookies);
    }

    if (data.localStorage && typeof data.localStorage === 'object') {
      await page.evaluateOnNewDocument((savedStorage) => {
        for (const [key, val] of Object.entries(savedStorage)) {
          try {
            localStorage.setItem(key, val);
          } catch (_) {}
        }
      }, data.localStorage);
    }

    return true;
  } catch (err) {
    console.error('Warning: Failed to restore session:', err.message);
    return false;
  }
}

/**
 * Perform automated login on Kemnaker SSO and wait for MagangHub dashboard.
 */
async function performHeadlessLogin(page, email, password) {
  if (!email || !password) {
    throw new Error('Email dan password Kemnaker diperlukan untuk login otomatis.');
  }

  // If not yet on Kemnaker login page, check if we need to navigate or wait
  const current = page.url();
  if (!current.includes('account.kemnaker.go.id') && !current.includes('/login')) {
    const navWaitStart = Date.now();
    while (Date.now() - navWaitStart < 15000) {
      const cur = page.url();
      if (cur.includes('account.kemnaker.go.id') || cur.includes('/login')) {
        break;
      }
      if (cur.includes('/dashboard')) {
        const hasLogin = await page.evaluate(() => {
          return !!document.querySelector('input#username, input#password');
        }).catch(() => false);
        if (!hasLogin) {
          return true; // Already on dashboard!
        }
      }
      await new Promise((r) => setTimeout(r, 1000));
    }
  }

  // If still not on login page, force navigate to login URL
  if (!page.url().includes('account.kemnaker.go.id') && !page.url().includes('/login')) {
    await page.goto('https://account.kemnaker.go.id/auth/login', {
      waitUntil: 'networkidle2',
      timeout: 30000,
    });
  }

  // Wait for #username and #password
  await page.waitForSelector('#username, input[name="username"]', { visible: true, timeout: 25000 });
  await page.waitForSelector('#password, input[type="password"]', { visible: true, timeout: 20000 });

  // Clear and fill username
  const userInput = await page.$('#username, input[name="username"]');
  await userInput.click({ clickCount: 3 });
  await page.keyboard.press('Backspace');
  await page.type('#username, input[name="username"]', email, { delay: 30 });

  // Clear and fill password
  const passInput = await page.$('#password, input[type="password"]');
  await passInput.click({ clickCount: 3 });
  await page.keyboard.press('Backspace');
  await page.type('#password, input[type="password"]', password, { delay: 30 });

  // Submit form
  const submitBtn = await page.$('button[type="submit"]');
  if (!submitBtn) {
    throw new Error('Tombol submit login tidak ditemukan pada halaman Kemnaker.');
  }

  await Promise.all([
    submitBtn.click(),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 35000 }).catch(() => null),
  ]);

  // Wait for redirect to MagangHub dashboard
  const startTime = Date.now();
  while (Date.now() - startTime < 40000) {
    const curUrl = page.url();

    // Did we reach MagangHub dashboard?
    if (curUrl.includes('monev.maganghub.kemnaker.go.id') && curUrl.includes('/dashboard')) {
      const hasLoginInputs = await page.evaluate(() => {
        return !!document.querySelector('input#username, input#password');
      }).catch(() => false);
      if (!hasLoginInputs) {
        return true; // Success!
      }
    }

    // Check if there is an error message on the page
    const errorMsg = await page.evaluate(() => {
      const form = document.querySelector('form');
      if (form) {
        const formText = form.innerText || '';
        if (
          formText.includes('tidak benar') ||
          formText.includes('salah') ||
          formText.includes('tidak ditemukan') ||
          formText.includes('gagal') ||
          formText.includes('tidak terdaftar')
        ) {
          const specific = Array.from(form.querySelectorAll('div, p, span')).find((el) => {
            const t = (el.innerText || '').trim();
            return (
              t.length > 5 &&
              t.length < 200 &&
              (t.includes('tidak benar') || t.includes('salah') || t.includes('tidak ditemukan') || t.includes('tidak terdaftar'))
            );
          });
          if (specific) {
            return specific.innerText.trim();
          }
        }
      }
      const alertEl = document.querySelector('.alert-danger, .invalid-feedback, [role="alert"], .alert');
      if (alertEl && alertEl.innerText && alertEl.innerText.trim().length > 3) {
        return alertEl.innerText.trim();
      }
      return null;
    }).catch(() => null);

    if (errorMsg && curUrl.includes('account.kemnaker.go.id')) {
      throw new Error(`Login Kemnaker gagal: ${errorMsg}`);
    }

    await new Promise((r) => setTimeout(r, 1500));
  }

  // If timeout waiting for dashboard
  const finalUrl = page.url();
  if (finalUrl.includes('monev.maganghub.kemnaker.go.id') && finalUrl.includes('/dashboard')) {
    return true;
  }

  throw new Error(`Login timeout atau tidak berhasil diarahkan ke dashboard MagangHub. URL saat ini: ${finalUrl}`);
}

/**
 * Main automation function.
 */
async function run() {
  const args = process.argv.slice(2);
  let payload = null;

  // Read payload from STDIN or JSON argument
  if (args.length > 0 && args[0].startsWith('{')) {
    payload = JSON.parse(args.join(' '));
  } else if (args.length > 0 && fs.existsSync(args[0])) {
    payload = JSON.parse(fs.readFileSync(args[0], 'utf-8'));
  } else {
    // Read from standard input
    const stdinBuffer = fs.readFileSync(0, 'utf-8').replace(/^\uFEFF/, '').trim();
    payload = JSON.parse(stdinBuffer);
  }

  const {
    sessionFile,
    email,
    password,
    values,
    screenshotPath,
    headless = true,
    submitForm = true,
    loginOnly = false,
    fullPage = true,
    dashboardOnly = false,
  } = payload;

  const executablePath = findChromeExecutable();

  const width = parseInt(payload.viewportWidth || process.env.MAGANGHUB_VIEWPORT_WIDTH || '1920', 10);
  const height = parseInt(payload.viewportHeight || process.env.MAGANGHUB_VIEWPORT_HEIGHT || '1080', 10);

  const launchOptions = {
    executablePath,
    headless: headless ? 'new' : false,
    defaultViewport: { width, height, deviceScaleFactor: 1 },
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-accelerated-2d-canvas',
      '--disable-gpu',
      '--disable-software-rasterizer',
      '--disable-crash-reporter',
      '--no-crashpad',
      '--disable-breakpad',
      `--window-size=${width},${height}`,
    ],
  };

  if (payload.userDataDir) {
    const dir = path.resolve(payload.userDataDir);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    } else {
      // Remove stale lock files if a previous process crashed or terminated abnormally
      const staleLocks = ['SingletonLock', 'SingletonCookie', 'SingletonSocket'];
      for (const lock of staleLocks) {
        const lockFile = path.join(dir, lock);
        try {
          if (fs.existsSync(lockFile)) {
            fs.unlinkSync(lockFile);
          }
        } catch (_) {}
      }
    }
    launchOptions.userDataDir = dir;
  }

  const browser = await puppeteer.launch(launchOptions);

  const pages = await browser.pages();
  const page = pages.length > 0 ? pages[0] : await browser.newPage();
  await page.setViewport({ width, height, deviceScaleFactor: 1 });
  await page.setUserAgent(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
  );

  try {
    // 1. Restore existing session if available
    if (sessionFile) {
      await restoreSession(page, sessionFile);
    }

    // 2. Navigate to Dashboard
    await page.goto('https://monev.maganghub.kemnaker.go.id/dashboard', {
      waitUntil: 'networkidle2',
      timeout: 45000,
    });

    if (loginOnly) {
      if (email && password) {
        console.error('Memulai proses login otomatis (headless) menggunakan akun Kemnaker...');
        await performHeadlessLogin(page, email, password);
      } else if (headless) {
        throw new Error('Email dan password diperlukan untuk login headless tanpa tampilan GUI.');
      } else {
        console.error('Silakan login di jendela browser Chrome yang terbuka...');

        const startTime = Date.now();
        let loggedIn = false;

        while (Date.now() - startTime < 300000) { // 5 minutes max
          const url = page.url();
          if (url.includes('/dashboard') && !url.includes('/login') && !url.includes('account.kemnaker.go.id')) {
            const hasLoginForm = await page.evaluate(() => {
              return !!document.querySelector('input#username, input#password, form[action*="login"]');
            }).catch(() => true);

            if (!hasLoginForm) {
              loggedIn = true;
              break;
            }
          }
          await new Promise((r) => setTimeout(r, 1500));
        }

        if (!loggedIn) {
          throw new Error('Timeout: Login manual tidak selesai dalam 5 menit.');
        }
      }

      // Wait a few seconds for tokens and cookies to settle
      await new Promise((r) => setTimeout(r, 3000));

      // Ensure dashboard is loaded
      if (!page.url().includes('/dashboard')) {
        await page.goto('https://monev.maganghub.kemnaker.go.id/dashboard', {
          waitUntil: 'networkidle2',
          timeout: 30000,
        }).catch(() => null);
      }

      if (screenshotPath) {
        const scDir = path.dirname(screenshotPath);
        if (!fs.existsSync(scDir)) {
          fs.mkdirSync(scDir, { recursive: true });
        }
        await page.screenshot({ path: screenshotPath, fullPage: Boolean(fullPage) });
      }

      if (sessionFile) {
        await saveSession(page, sessionFile);
      }

      console.log(
        JSON.stringify({
          success: true,
          screenshot: screenshotPath || null,
          message: 'Login otomatis berhasil! Sesi MagangHub tersimpan dan aktif.',
        })
      );
      await browser.close();
      return;
    }

    // Wait for any SSO handshake redirect loops to finish (up to 20s)
    const ssoWaitStart = Date.now();
    while (Date.now() - ssoWaitStart < 20000) {
      const url = page.url();
      if (url.includes('/dashboard') && !url.includes('callback') && !url.includes('account.kemnaker.go.id')) {
        break;
      }
      await new Promise((r) => setTimeout(r, 1000));
    }

    // Brief wait for Vue/Nuxt dashboard to stabilize
    await new Promise((r) => setTimeout(r, 2000));

    const currentUrl = page.url();
    // 3. Handle login if redirected to SSO / Login
    if (currentUrl.includes('account.kemnaker.go.id') || currentUrl.includes('/login')) {
      if (email && password) {
        console.error('Sesi MagangHub kedaluwarsa atau belum ada. Mencoba auto-login headless dengan akun tersimpan...');
        await performHeadlessLogin(page, email, password);
        await new Promise((r) => setTimeout(r, 3000));
        if (sessionFile) {
          await saveSession(page, sessionFile);
        }
      } else {
        throw new Error(
          'Sesi login MagangHub belum ada atau telah kedaluwarsa. Silakan hubungkan akun via WhatsApp: LOGIN <email> <password> atau jalankan: php artisan app:maganghub-login-headless'
        );
      }
    }

    // Ensure we are on dashboard
    if (!page.url().includes('/dashboard')) {
      await page.goto('https://monev.maganghub.kemnaker.go.id/dashboard', {
        waitUntil: 'networkidle2',
        timeout: 30000,
      });
    }

    if (dashboardOnly) {
      if (screenshotPath) {
        const scDir = path.dirname(screenshotPath);
        if (!fs.existsSync(scDir)) {
          fs.mkdirSync(scDir, { recursive: true });
        }
        await page.screenshot({ path: screenshotPath, fullPage: Boolean(fullPage) });
      }

      console.log(
        JSON.stringify({
          success: true,
          screenshot: screenshotPath || null,
          message: 'Berhasil membuka dashboard MagangHub dan mengambil screenshot bukti sesi login aktif.',
        })
      );
      await browser.close();
      return;
    }

    // 4. Open the report form/modal if not already open
    let hasTextareas = await page.evaluate(() => document.querySelectorAll('textarea').length >= 3);
    if (!hasTextareas) {
      const opened = await page.evaluate(() => {
        const btns = Array.from(document.querySelectorAll('button, a'));
        const target = btns.find((b) => (b.innerText || '').includes('Isi Laporan'));
        if (target) {
          target.click();
          return true;
        }
        return false;
      });

      if (!opened) {
        const alreadySubmitted = await page.evaluate(() => {
          const txt = document.body.innerText || '';
          return txt.includes('telah dikirim') || txt.includes('Laporan hari ini sudah diisi');
        });
        if (alreadySubmitted) {
          throw new Error('Laporan hari ini sudah terisi dan terkirim sebelumnya di portal MagangHub.');
        }
      }
    }

    // Wait for textareas to be visible
    await page.waitForSelector('textarea', { timeout: 15000 });

    // 5. Fill Form Textareas & Checkbox
    await page.evaluate((vals) => {
      const textareas = document.querySelectorAll('textarea');
      const fields = ['uraianAktivitas', 'pembelajaran', 'kendala'];

      textareas.forEach((textarea, index) => {
        const key = fields[index];
        if (key && vals[key]) {
          textarea.value = vals[key];
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
          textarea.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });

      const confirmCheckbox = document.querySelector('.review-confirmation input, input[type="checkbox"]');
      if (confirmCheckbox && !confirmCheckbox.checked) {
        confirmCheckbox.click();
      }
    }, values);

    // Brief delay to let Vue/Nuxt update internal reactive state
    await new Promise((r) => setTimeout(r, 1000));

    // 6. Submit the form if requested
    if (submitForm) {
      await page.evaluate(() => {
        const candidates = Array.from(document.querySelectorAll('button'));
        const btn = candidates.find((b) => {
          const text = (b.innerText || '').toLowerCase();
          return text.includes('simpan dan kirim') || text.includes('simpan') || text.includes('kirim');
        });

        if (btn && !btn.disabled) {
          btn.click();
          return true;
        }
        return false;
      });

      // Wait for submission response
      await new Promise((r) => setTimeout(r, 4000));
    }

    // 7. Capture screenshot for verification proof
    if (screenshotPath) {
      const scDir = path.dirname(screenshotPath);
      if (!fs.existsSync(scDir)) {
        fs.mkdirSync(scDir, { recursive: true });
      }
      await page.screenshot({ path: screenshotPath, fullPage: Boolean(fullPage) });
    }

    // 8. Update session file with fresh state
    if (sessionFile) {
      await saveSession(page, sessionFile);
    }

    console.log(
      JSON.stringify({
        success: true,
        screenshot: screenshotPath || null,
        message: submitForm
          ? 'Laporan berhasil diisi dan dikirim otomatis ke portal MagangHub.'
          : 'Laporan berhasil diisi (draf) di portal MagangHub.',
      })
    );
  } catch (error) {
    // Attempt error screenshot for debugging
    let errScreenshot = null;
    if (screenshotPath) {
      try {
        const errPath = screenshotPath.replace(/\.png$/i, '_error.png');
        await page.screenshot({ path: errPath });
        errScreenshot = errPath;
      } catch (_) {}
    }

    console.log(
      JSON.stringify({
        success: false,
        error: error.message,
        screenshot: errScreenshot,
      })
    );
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

run();
