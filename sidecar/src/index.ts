import { Api } from "./api.js";
import { openPlaywright } from "./browser.js";
import { login } from "./login.js";
import { runOnce, type RunDeps } from "./run.js";

/**
 * Usage (settings come from the environment, or sidecar/.env):
 *   npm run login                 sign in to Instacart in a visible browser and save the session
 *   npm run loop                  poll the web app for stock checks forever (leave this running)
 *   npm run once                  do at most one stock check, then exit
 *   npm start -- product <store> <product-id>
 *                                 debug: read one product page (what runs do); posts nothing
 *   npm start -- search <store> <query...>
 *                                 debug: run one search and print what was captured; posts nothing
 */

const STORAGE_STATE_PATH = process.env.IC_STORAGE_STATE ?? "./storage-state.json";
const API_BASE_URL = process.env.GROCERY_PLANNER_API_URL ?? "http://localhost:8000/api";
const SIDECAR_TOKEN = process.env.SIDECAR_TOKEN ?? "";
const POLL_SECONDS = Number(process.env.POLL_SECONDS ?? 60);

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));
const log = (msg: string) => console.log(`[${new Date().toISOString()}] ${msg}`);

function deps(): RunDeps {
  if (!SIDECAR_TOKEN) {
    throw new Error("SIDECAR_TOKEN is not set. Copy the value from web/.env into sidecar/.env.");
  }
  return {
    api: new Api(API_BASE_URL, SIDECAR_TOKEN),
    openBrowser: () => openPlaywright(STORAGE_STATE_PATH),
    sleep,
    paceMs: () => 2000 + Math.random() * 3000,
    log,
  };
}

async function loop(): Promise<never> {
  const d = deps();
  log(`Polling ${API_BASE_URL} every ${POLL_SECONDS}s for stock checks.`);
  for (;;) {
    try {
      await runOnce(d);
    } catch (err) {
      // Keep the loop alive (web app down, network blip); the web app marks
      // an unfinished run as stalled, so this still surfaces in the UI.
      log(`Poll failed: ${err instanceof Error ? err.message : err}`);
    }
    await sleep(POLL_SECONDS * 1000);
  }
}

async function debug(kind: "product" | "search", storeSlug: string, arg: string): Promise<void> {
  const browser = await openPlaywright(STORAGE_STATE_PATH);
  try {
    const r = kind === "product" ? await browser.lookup(storeSlug, arg, "(debug)") : await browser.search(storeSlug, arg);
    console.log(r.signedOut ? "Landed on a sign-in page: session expired." : `${r.snapshots.length} products captured.`);
    console.table(r.snapshots.map(({ instacartProductId, name, available, stockLevel, price, sizeText }) => ({ instacartProductId, name: name?.slice(0, 50), available, stockLevel, price, sizeText })));
  } finally {
    await browser.close();
  }
}

async function main(argv: string[]): Promise<void> {
  const [command, ...rest] = argv;
  switch (command) {
    case "login":
      return login(STORAGE_STATE_PATH);
    case "once": {
      const r = await runOnce(deps());
      if (r.runId === null) log("No stock check due.");
      if (r.status && r.status !== "ok") process.exitCode = 1;
      return;
    }
    case "loop":
      return loop();
    case "product":
    case "search":
      if (rest.length < 2) break;
      return debug(command, rest[0], rest.slice(1).join(" "));
  }
  console.error("Usage: node dist/index.js login | once | loop | product <store-slug> <product-id> | search <store-slug> <query...>");
  process.exitCode = 2;
}

main(process.argv.slice(2)).catch((err) => {
  console.error(err);
  process.exit(1);
});
