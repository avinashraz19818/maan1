const RESET_KEY = 'maanwin_runtime_reset_v7';
const OLD_API_HOST_KEY = 'ar_g_api_url';
const STALE_ACTIVITY_KEYS = [
  'activityList',
  'activityNewList',
  'activityCategoryList',
  'activityGroupList',
];
const ORIGINAL_RUNTIME = './index-B9u3BH9U.js';

function workerScriptUrls(registration) {
  return [registration?.active, registration?.waiting, registration?.installing]
    .map((worker) => worker?.scriptURL)
    .filter(Boolean);
}

function isMaanWinRootWorker(registration) {
  return workerScriptUrls(registration).some((scriptURL) => {
    try {
      const pathname = new URL(scriptURL, globalThis.location?.href).pathname;
      return pathname.endsWith('/ar-sw.js') || pathname.endsWith('/pwa-sw.js');
    } catch (_error) {
      return false;
    }
  });
}

export async function resetLegacyRuntime({ cacheStorage, serviceWorkerContainer }) {
  if (cacheStorage?.keys) {
    const keys = await cacheStorage.keys();
    await Promise.all(
      keys
        .filter((key) => key.startsWith('ar-pwa') || key === 'online-page')
        .map((key) => cacheStorage.delete(key)),
    );
  }

  if (serviceWorkerContainer?.getRegistrations) {
    const registrations = await serviceWorkerContainer.getRegistrations();
    await Promise.all(
      registrations
        .filter(isMaanWinRootWorker)
        .map((registration) => registration.unregister()),
    );
  }
}

export async function clearActivityIndexedDb(indexedDBFactory = globalThis.indexedDB) {
  if (!indexedDBFactory?.open) return;
  await new Promise((resolve) => {
    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      resolve();
    };
    let request;
    try {
      request = indexedDBFactory.open('_astorage');
    } catch (_error) {
      finish();
      return;
    }
    request.onerror = finish;
    request.onblocked = finish;
    request.onsuccess = () => {
      const database = request.result;
      if (!database?.objectStoreNames?.contains('renkv')) {
        database?.close?.();
        finish();
        return;
      }
      try {
        const transaction = database.transaction('renkv', 'readwrite');
        const store = transaction.objectStore('renkv');
        for (const key of STALE_ACTIVITY_KEYS) store.delete(key);
        transaction.oncomplete = () => {
          database.close();
          finish();
        };
        transaction.onerror = transaction.onabort = () => {
          database.close();
          finish();
        };
      } catch (_error) {
        database.close();
        finish();
      }
    };
  });
}

function resetCompleted(storage) {
  try {
    return storage?.getItem(RESET_KEY) === 'complete';
  } catch (_error) {
    return false;
  }
}

function clearStaleRuntimeData(storage) {
  try {
    storage?.removeItem(OLD_API_HOST_KEY);
    for (const key of STALE_ACTIVITY_KEYS) storage?.removeItem(key);
  } catch (_error) {
    // Same-origin routing and fresh API data still work when storage is unavailable.
  }
}

function markResetCompleted(storage) {
  try {
    storage?.setItem(RESET_KEY, 'complete');
  } catch (_error) {
    // Storage can be unavailable in privacy mode; startup can still continue.
  }
}

export async function loadMaanWinRuntime({
  cacheStorage = globalThis.caches,
  serviceWorkerContainer = globalThis.navigator?.serviceWorker,
  storage = globalThis.localStorage,
  indexedDBFactory = globalThis.indexedDB,
  fetchImpl = globalThis.fetch?.bind(globalThis),
  importModule = (url) => import(url),
} = {}) {
  const runtimeUrl = new URL(ORIGINAL_RUNTIME, import.meta.url).href;
  const firstV7Boot = !resetCompleted(storage);

  if (firstV7Boot) {
    clearStaleRuntimeData(storage);
    await Promise.all([
      resetLegacyRuntime({ cacheStorage, serviceWorkerContainer }),
      clearActivityIndexedDb(indexedDBFactory),
    ]);
    if (fetchImpl) {
      const response = await fetchImpl(runtimeUrl, {
        cache: 'reload',
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error(`Maan Win runtime refresh failed (${response.status})`);
    }
  }

  const runtime = await importModule(runtimeUrl);
  if (firstV7Boot) markResetCompleted(storage);
  return runtime;
}

function showStartupError(error) {
  console.error('[Maan Win] startup failed:', error);
  const app = document.getElementById('app');
  if (!app) return;
  app.innerHTML = '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#24262b;color:#fff;font:16px sans-serif;text-align:center">Maan Win could not start. Please refresh the page once.</div>';
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  loadMaanWinRuntime().catch(showStartupError);
}
