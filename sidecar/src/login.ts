import { existsSync } from "node:fs";
import { createInterface } from "node:readline/promises";
import { chromium } from "playwright";

/**
 * Opens a visible browser so a person can sign in to Instacart, then saves
 * the session for headless runs. Needed once, and again whenever the list
 * page says the sign-in has expired.
 */
export async function login(storageStatePath: string): Promise<void> {
  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext(existsSync(storageStatePath) ? { storageState: storageStatePath } : {});
  const page = await context.newPage();
  await page.goto("https://www.instacart.com/store");

  const rl = createInterface({ input: process.stdin, output: process.stdout });
  await rl.question(
    [
      "",
      "In the browser window that just opened:",
      "  1. Sign in to Instacart.",
      "  2. Check the delivery address is home, and that Fareway is set to your pickup store",
      "     (stock and prices are per-location).",
      "Then come back here and press Enter to save the session... ",
    ].join("\n")
  );
  rl.close();

  await context.storageState({ path: storageStatePath });
  await browser.close();
  console.log(`Saved session to ${storageStatePath}.`);
}
