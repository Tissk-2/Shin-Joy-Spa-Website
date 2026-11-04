import { connect } from "node:net";
import { mkdirSync, writeFileSync } from "node:fs";
import {
  removeTestOwnedPath,
  serverRuntimeDirectory,
  serverShutdownMarkers,
  testingConfigCache,
  testingDatabase,
  viteHotFile,
} from "./testing-environment";

const shutdownTimeout = 10_000;

function isPortOpen(port: number): Promise<boolean> {
  return new Promise((resolve) => {
    const socket = connect({ host: "127.0.0.1", port });

    socket.once("connect", () => {
      socket.destroy();
      resolve(true);
    });
    socket.once("error", () => resolve(false));
  });
}

async function waitForPortToClose(port: number): Promise<void> {
  const deadline = Date.now() + shutdownTimeout;

  while (await isPortOpen(port)) {
    if (Date.now() >= deadline) {
      throw new Error(
        `Timed out waiting for the Playwright server on port ${port} to stop.`,
      );
    }

    await new Promise((resolve) => setTimeout(resolve, 100));
  }
}

export default async function globalTeardown(): Promise<void> {
  mkdirSync(serverRuntimeDirectory, { recursive: true });

  for (const marker of Object.values(serverShutdownMarkers)) {
    writeFileSync(marker, "stop");
  }

  await Promise.all([waitForPortToClose(8000), waitForPortToClose(5174)]);

  for (const marker of Object.values(serverShutdownMarkers)) {
    removeTestOwnedPath(marker);
  }

  removeTestOwnedPath(viteHotFile);
  removeTestOwnedPath(testingConfigCache);
  removeTestOwnedPath(testingDatabase);
  removeTestOwnedPath(serverRuntimeDirectory, { recursive: true });
}
