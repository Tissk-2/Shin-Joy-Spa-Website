import { rmSync } from "node:fs";
import path from "node:path";

export const projectRoot = path.resolve(import.meta.dirname, "../..");
const testingRoot = path.join(projectRoot, "storage/framework/testing");
export const testingDatabase = path.join(
  projectRoot,
  "storage/framework/testing.sqlite",
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
  vite: path.join(serverRuntimeDirectory, "vite.stop"),
} as const;

export const testingEnvironment = {
  APP_ENV: "testing",
  APP_DEBUG: "false",
  APP_KEY: "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
  APP_CONFIG_CACHE: "storage/framework/testing/playwright-config.php",
  APP_URL: "http://127.0.0.1:8000",
  BCRYPT_ROUNDS: "4",
  CACHE_STORE: "array",
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
  TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED: "false",
  TELEGRAM_BOT_TOKEN: "",
  TELEGRAM_STAFF_CHAT_ID: "",
};

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
