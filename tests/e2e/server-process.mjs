import { spawn } from "node:child_process";
import { existsSync, mkdirSync, rmSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const projectRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../..",
);
const runtimeDirectory = path.join(
  projectRoot,
  "storage/framework/testing/playwright-servers",
);

const serverConfigurations = Object.freeze({
  laravel: Object.freeze({
    command: "php",
    args: Object.freeze([
      "-S",
      "127.0.0.1:8000",
      "../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php",
    ]),
    cwd: path.join(projectRoot, "public"),
  }),
});

const serverName = process.argv[2];
const configuration = serverConfigurations[serverName];

if (!configuration) {
  console.error('Expected the server name "laravel".');
  process.exitCode = 1;
} else {
  mkdirSync(runtimeDirectory, { recursive: true });

  const shutdownMarker = path.join(runtimeDirectory, `${serverName}.stop`);
  rmSync(shutdownMarker, { force: true });

  const child = spawn(configuration.command, configuration.args, {
    cwd: configuration.cwd,
    env: process.env,
    shell: false,
    stdio: "inherit",
  });

  let shutdownRequested = false;
  let shutdownReason;
  let childExited = false;

  const cleanupRuntime = () => {
    clearInterval(watcher);
    rmSync(shutdownMarker, { force: true });
  };

  const requestShutdown = (reason) => {
    if (shutdownRequested || childExited) {
      return;
    }

    shutdownRequested = true;
    shutdownReason = reason;
    clearInterval(watcher);

    if (!child.kill() && child.exitCode === null && child.signalCode === null) {
      console.error(
        `Failed to stop the ${serverName} child process ${child.pid ?? "unknown"}.`,
      );
      process.exitCode = 1;
    }
  };

  const watcher = setInterval(() => {
    if (!existsSync(shutdownMarker) || shutdownRequested) {
      return;
    }

    requestShutdown("marker");
  }, 100);

  process.once("SIGINT", () => requestShutdown("SIGINT"));
  process.once("SIGTERM", () => requestShutdown("SIGTERM"));
  process.once("exit", () => {
    cleanupRuntime();

    if (!childExited) {
      child.kill();
    }
  });

  child.once("error", (error) => {
    childExited = true;
    cleanupRuntime();
    console.error(`Failed to start the ${serverName} server: ${error.message}`);
    process.exitCode = 1;
  });

  child.once("exit", (code) => {
    childExited = true;
    cleanupRuntime();

    if (shutdownReason === "marker") {
      process.exitCode = 0;
    } else if (shutdownReason === "SIGINT") {
      process.exitCode = 130;
    } else if (shutdownReason === "SIGTERM") {
      process.exitCode = 143;
    } else {
      process.exitCode = code ?? 1;
    }
  });
}
