import { execFileSync } from "node:child_process";
import { mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import {
  projectRoot,
  serverShutdownMarkers,
  testingConfigCache,
  testingDatabase,
  testingEnvironment,
  viteHotFile,
} from "./testing-environment";

export default function globalSetup(): void {
  rmSync(viteHotFile, { force: true });
  rmSync(testingConfigCache, { force: true });
  rmSync(testingDatabase, { force: true });

  for (const marker of Object.values(serverShutdownMarkers)) {
    rmSync(marker, { force: true });
  }

  mkdirSync(path.dirname(testingDatabase), { recursive: true });
  writeFileSync(testingDatabase, "");

  execFileSync(
    "php",
    ["artisan", "migrate:fresh", "--force", "--no-interaction"],
    {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      stdio: "inherit",
    },
  );
}
