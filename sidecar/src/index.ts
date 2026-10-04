import { chromium } from "playwright";
import { searchStore } from "./instacart.js";
import type { StoreTarget, AvailabilitySnapshot } from "./types.js";

/**
 * Entry point stub. Not wired up yet -- the pieces that are proven
 * (searchStore's field extraction) are separated from the pieces that
 * aren't (a persistent logged-in profile, posting results to the Laravel
 * app, a real run loop). Fill in STORAGE_STATE_PATH and API_BASE_URL, then
 * this becomes a runnable one-shot scrape.
 */

const STORAGE_STATE_PATH = process.env.IC_STORAGE_STATE ?? "./storage-state.json";
const API_BASE_URL = process.env.GROCERY_PLANNER_API_URL ?? "http://localhost:8000/api";

const STORES: StoreTarget[] = [
  { slug: "hy-vee", fulfillment: "delivery" },
  { slug: "costco", fulfillment: "delivery" },
  { slug: "sams-club", fulfillment: "delivery" },
  { slug: "fareway-meat-grocery", fulfillment: "pickup" },
];

async function postSnapshots(snapshots: AvailabilitySnapshot[]): Promise<void> {
  if (snapshots.length === 0) return;
  const res = await fetch(`${API_BASE_URL}/availability-snapshots`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ snapshots }),
  });
  if (!res.ok) {
    throw new Error(`Failed to post ${snapshots.length} snapshots: ${res.status} ${await res.text()}`);
  }
}

async function run(queries: string[]): Promise<void> {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ storageState: STORAGE_STATE_PATH });
  const page = await context.newPage();

  // TODO: detect a dead/expired session here (e.g. a redirect to the
  // sign-in page) and surface it loudly -- this will happen periodically
  // and needs a human to re-log-in, not a silent empty result set.

  const all: AvailabilitySnapshot[] = [];
  for (const store of STORES) {
    for (const query of queries) {
      const results = await searchStore(page, store, query);
      all.push(...results);
    }
  }

  await context.close();
  await browser.close();

  await postSnapshots(all);
  console.log(`Captured ${all.length} snapshots across ${STORES.length} stores.`);
}

const queries = process.argv.slice(2);
if (queries.length === 0) {
  console.error("Usage: node dist/index.js <query> [query...]");
  process.exit(1);
}

run(queries).catch((err) => {
  console.error(err);
  process.exit(1);
});
