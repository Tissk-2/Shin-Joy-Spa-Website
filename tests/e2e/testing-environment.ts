import { copyFileSync, renameSync, rmSync } from "node:fs";
import path from "node:path";

export const projectRoot = path.resolve(import.meta.dirname, "../..");
const testingRoot = path.join(projectRoot, "storage/framework/testing");
export const testingDatabase = path.join(
  projectRoot,
  "storage/framework/testing.sqlite",
);
// Global setup copies the freshly seeded database here once per run.
export const seededTestingDatabase = path.join(
  testingRoot,
  "playwright-seeded.sqlite",
);
export const resetStagingDatabase = path.join(
  testingRoot,
  "playwright-reset.sqlite",
);
export const testingConfigCache = path.join(
  projectRoot,
  "storage/framework/testing/playwright-config.php",
);
export const developerViteHotFile = path.join(projectRoot, "public/hot");
export const viteHotFile = path.join(testingRoot, "playwright-vite.hot");
export const serverRuntimeDirectory = path.join(
  projectRoot,
  "storage/framework/testing/playwright-servers",
);

export const serverShutdownMarkers = {
  laravel: path.join(serverRuntimeDirectory, "laravel.stop"),
} as const;

export const testingEnvironment = {
  APP_ENV: "testing",
  APP_DEBUG: "false",
  APP_KEY: "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
  APP_CONFIG_CACHE: "storage/framework/testing/playwright-config.php",
  APP_URL: "http://127.0.0.1:8000",
  BCRYPT_ROUNDS: "4",
  CACHE_STORE: "array",
  // Pinned so a developer's .env can't switch them on: Laravel fills unset variables from .env.
  BOOKING_CUSTOMER_NOTICES_ENABLED: "false",
  BOOKING_SCHEDULING_ENABLED:
    process.env.E2E_BOOKING_SCHEDULING_ENABLED === "true" ? "true" : "false",
  DB_CONNECTION: "sqlite",
  DB_URL: "",
  DB_HOST: "127.0.0.1",
  DB_PORT: "1",
  DB_USERNAME: "",
  DB_PASSWORD: "",
  DB_SOCKET: "",
  DB_DATABASE: testingDatabase,
  LARAVEL_VITE_HOT_FILE: viteHotFile,
  MAIL_MAILER: "array",
  PHP_CLI_SERVER_WORKERS: "1",
  QUEUE_CONNECTION: "sync",
  SESSION_DRIVER: "database",
  SESSION_SECURE_COOKIE: "false",
  TELEGRAM_ACTIONS_ENABLED: "false",
  TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED: "false",
  TELEGRAM_BOT_TOKEN: "",
  TELEGRAM_STAFF_CHAT_ID: "",
};

// Each spec file calls this in its first beforeAll, so it starts from the seeded
// database and never sees data another spec file left behind. It assumes the
// config's single worker: a second worker's reset would wipe this one's data.
// Copy, then rename over the database: Windows lets a plain copy overwrite a
// database that a request still has open, and that request's write then lands
// in the fresh copy. The rename fails until PHP lets go of the file, so retry.
export async function resetTestingDatabase(): Promise<void> {
  copyFileSync(seededTestingDatabase, resetStagingDatabase);

  for (let attempt = 1; ; attempt += 1) {
    try {
      renameSync(resetStagingDatabase, testingDatabase);
      return;
    } catch (error) {
      const code = (error as NodeJS.ErrnoException).code ?? "";

      if (attempt >= 100 || !["EPERM", "EBUSY", "EACCES"].includes(code)) {
        throw error;
      }

      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
}

export function removeTestOwnedPath(
  target: string,
  options: { recursive?: boolean } = {},
): void {
  const resolved = path.resolve(target);
  const relative = path.relative(testingRoot, resolved);
  const insideTestingRoot =
    relative !== "" && !relative.startsWith("..") && !path.isAbsolute(relative);

  if (resolved !== testingDatabase && !insideTestingRoot) {
    throw new Error(
      `Refusing to delete a path that is not test-owned: ${resolved}`,
    );
  }

  rmSync(resolved, { force: true, recursive: options.recursive ?? false });
}
