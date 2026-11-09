import { existsSync, readdirSync, statSync } from "node:fs";
import path from "node:path";

export const buildManifest = "public/build/manifest.json";

export const buildInputs = [
  "resources/js",
  "resources/css",
  "resources/views",
  "vite.config.ts",
  "tailwind.config.js",
  "tsconfig.json",
  "package-lock.json",
  // Tailwind scans vendor pagination views, so a Composer update can change CSS.
  "composer.lock",
] as const;
// ponytail: VITE_* values in .env are baked into the build but deliberately not
// tracked here; rebuild after changing them. Symlinked inputs are skipped.

function inputFiles(root: string, relative: string): string[] {
  const absolute = path.join(root, relative);

  if (!existsSync(absolute)) {
    return [];
  }

  if (statSync(absolute).isFile()) {
    return [relative];
  }

  const files: string[] = [];

  for (const entry of readdirSync(absolute, { withFileTypes: true })) {
    const child = `${relative}/${entry.name}`;

    if (entry.isDirectory()) {
      files.push(...inputFiles(root, child));
    } else if (entry.isFile()) {
      files.push(child);
    }
  }

  return files;
}

export function assertBuildIsFresh(
  root: string,
  manifest: string = buildManifest,
  inputs: readonly string[] = buildInputs,
): void {
  const manifestPath = path.join(root, manifest);

  if (!existsSync(manifestPath)) {
    throw new Error(
      `E2E needs built assets: ${manifest} is missing. Run \`npm run build\` before \`npm run test:e2e\`.`,
    );
  }

  const builtAt = statSync(manifestPath).mtimeMs;
  let newest: { mtimeMs: number; path: string } | undefined;
  let newerCount = 0;

  for (const input of inputs) {
    for (const file of inputFiles(root, input)) {
      const mtimeMs = statSync(path.join(root, file)).mtimeMs;

      if (mtimeMs > builtAt) {
        newerCount += 1;

        if (newest === undefined || mtimeMs > newest.mtimeMs) {
          newest = { mtimeMs, path: file };
        }
      }
    }
  }

  if (newest === undefined) {
    return;
  }

  const more = newerCount > 1 ? ` (and ${newerCount - 1} more)` : "";

  throw new Error(
    `E2E assets are stale: ${newest.path} changed after ${manifest} was built${more}. Run \`npm run build\` before \`npm run test:e2e\`.`,
  );
}
