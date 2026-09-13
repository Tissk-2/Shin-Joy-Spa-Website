import { execFileSync } from "node:child_process";
import type { Page } from "@playwright/test";

import { registerCustomer, verifyCustomer } from "./auth";
import { projectRoot, testingEnvironment } from "../testing-environment";

export async function registerVerifiedAdmin(
  page: Page,
  email: string,
): Promise<void> {
  await registerCustomer(page, email);
  await verifyCustomer(page, email);

  execFileSync("php", ["artisan", "admin:grant", email, "--yes"], {
    cwd: projectRoot,
    env: { ...process.env, ...testingEnvironment },
    stdio: "pipe",
  });
}
