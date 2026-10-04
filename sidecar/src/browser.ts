import { existsSync } from "node:fs";
import { chromium } from "playwright";
import { lookupProduct, searchStore } from "./instacart.js";
import { SessionMissingError, type StoreBrowser } from "./run.js";

/**
 * Opens Chromium with the saved Instacart session. After a run the
 * refreshed cookies are written back, so regular runs keep the session
 * alive longer than a one-time login would on its own.
 */
export async function openPlaywright(storageStatePath: string, headless = true): Promise<StoreBrowser> {
  if (!existsSync(storageStatePath)) {
    throw new SessionMissingError(`No saved Instacart session at ${storageStatePath}.`);
  }

  const browser = await chromium.launch({ headless });
  const context = await browser.newContext({ storageState: storageStatePath });
  const page = await context.newPage();

  return {
    lookup: (storeSlug, productId, description) => lookupProduct(page, storeSlug, productId, description),
    search: (storeSlug, query) => searchStore(page, storeSlug, query),
    close: async () => {
      await context.storageState({ path: storageStatePath }).catch(() => undefined);
      await context.close();
      await browser.close();
    },
  };
}
