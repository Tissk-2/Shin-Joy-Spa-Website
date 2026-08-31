import path from "node:path";

export const projectRoot = path.resolve(import.meta.dirname, "../..");
export const testingDatabase = path.join(
  projectRoot,
  "storage/framework/testing.sqlite",
);
export const testingConfigCache = path.join(
  projectRoot,
  "storage/framework/testing/playwright-config.php",
);
export const viteHotFile = path.join(projectRoot, "public/hot");
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
  DB_CONNECTION: "sqlite",
  DB_DATABASE: testingDatabase,
  MAIL_MAILER: "array",
  PHP_CLI_SERVER_WORKERS: "1",
  QUEUE_CONNECTION: "sync",
  SESSION_DRIVER: "database",
  SESSION_SECURE_COOKIE: "false",
};
