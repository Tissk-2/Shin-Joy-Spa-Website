import { execFileSync } from "node:child_process";
import {
  existsSync,
  mkdirSync,
  readFileSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";
import {
  projectRoot,
  serverShutdownMarkers,
  testingConfigCache,
  testingDatabase,
  testingEnvironment,
  viteHotFile,
} from "./testing-environment";

const catalogFixtureDirectory = path.join(
  projectRoot,
  "tests/e2e/fixtures/catalog",
);
const catalogFixtures = [
  { name: "category.jpg", mime: "image/jpeg", exifMarker: "Exif\0\0" },
  { name: "cover.png", mime: "image/png", exifMarker: "eXIf" },
  { name: "gallery.webp", mime: "image/webp", exifMarker: "EXIF" },
] as const;
const catalogFixtureMaximumBytes = 50 * 1024;
const catalogFixtureMinimumWidth = 600;
const catalogFixtureMinimumHeight = 400;

type ImageMetadata = Readonly<{
  width: number;
  height: number;
  mime: string;
}>;

function imageMetadata(fixturePath: string): ImageMetadata {
  const inspectImage = [
    "$info = @getimagesize($argv[1]);",
    "if ($info === false) { fwrite(STDERR, 'Invalid image.'); exit(1); }",
    "echo json_encode(['width' => $info[0], 'height' => $info[1], 'mime' => $info['mime']]);",
  ].join(" ");
  const output = execFileSync("php", ["-r", inspectImage, fixturePath], {
    cwd: projectRoot,
    encoding: "utf8",
  });

  return JSON.parse(output) as ImageMetadata;
}

function assertCatalogFixtures(): void {
  for (const fixture of catalogFixtures) {
    const fixturePath = path.join(catalogFixtureDirectory, fixture.name);

    if (!existsSync(fixturePath) || !statSync(fixturePath).isFile()) {
      throw new Error(`Missing catalog E2E fixture: ${fixturePath}`);
    }

    const fixtureStats = statSync(fixturePath);
    const metadata = imageMetadata(fixturePath);

    if (fixtureStats.size >= catalogFixtureMaximumBytes) {
      throw new Error(
        `Catalog E2E fixture must be below 50 KB: ${fixturePath}`,
      );
    }

    if (
      metadata.width < catalogFixtureMinimumWidth ||
      metadata.height < catalogFixtureMinimumHeight
    ) {
      throw new Error(
        `Catalog E2E fixture must be at least 600x400: ${fixturePath}`,
      );
    }

    if (metadata.mime !== fixture.mime) {
      throw new Error(
        `Catalog E2E fixture has unexpected MIME ${metadata.mime}: ${fixturePath}`,
      );
    }

    if (readFileSync(fixturePath).includes(Buffer.from(fixture.exifMarker))) {
      throw new Error(`Catalog E2E fixture contains EXIF data: ${fixturePath}`);
    }
  }
}

export default function globalSetup(): void {
  assertCatalogFixtures();
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
    ["artisan", "migrate:fresh", "--seed", "--force", "--no-interaction"],
    {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      stdio: "inherit",
    },
  );
}
