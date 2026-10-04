import type { Page, Response } from "playwright";
import type { AvailabilitySnapshot, SearchResult } from "./types.js";

/**
 * Reads Instacart's own storefront search as JSON, from inside a real,
 * already-logged-in page -- it does not re-implement Instacart's API
 * against a hardcoded URL, because the operation names are Apollo
 * `operationName` values and the persisted-query hashes embedded in
 * Instacart's bundle change across their deploys. Instead this captures the
 * actual responses the loaded page receives.
 *
 * Verified by hand (built-in browser, logged-in session, four stores:
 * Hy-Vee, Costco, Sam's Club, Fareway-pickup) on 2026-10-04:
 *   - A search's `Items` and `SearchResultsPlacements` GraphQL responses
 *     both carry, per product: name, size, productId, price
 *     (`price.viewSection.priceValueString`), and availability
 *     (`availability.available`, `availability.stockLevel`).
 *   - All of this is per-store and per-address -- it reflects whatever
 *     store/location the page is currently showing, not a global catalog.
 *   - NOT verified: exact service-fee amounts and hard order minimums
 *     (the storefront header only shows the free-delivery/pickup
 *     threshold until a cart has items in it), and no out-of-stock
 *     example has been observed yet.
 *   - NOT verified: what an expired session looks like. looksSignedOut()
 *     is a URL heuristic; run.ts also treats "captured nothing" as a
 *     failure so an unrecognised sign-in wall still fails loudly.
 *
 * Search is NOT reliable for checking a specific product (first real run,
 * 2026-10-04): Hy-Vee search for "Hy-Vee Hy-Vee Half & Half" -- the exact
 * name -- returned 46 products, not including that one, though its product
 * page showed it highlyInStock. Runs therefore use lookupProduct(); search
 * stays as a debugging tool (`npm start -- search`).
 */
export async function searchStore(page: Page, storeSlug: string, query: string): Promise<SearchResult> {
  const url = `https://www.instacart.com/store/${storeSlug}/s?k=${encodeURIComponent(query)}`;

  const seen = new Map<string, any>();
  const capture = async (response: Response) => {
    const req = response.url();
    if (!req.includes("/graphql")) return;
    const op = new URL(req).searchParams.get("operationName");
    try {
      for (const it of extractItems(op, await response.json())) {
        seen.set(String(it.productId), it);
      }
    } catch {
      // Non-JSON or shape changed; skip rather than crash the whole run.
    }
  };

  page.on("response", capture);
  try {
    // Not "networkidle": Instacart's analytics/polling traffic never goes
    // quiet, so that wait always hit its 30s timeout (seen on the first
    // real run, 2026-10-04). Load the page, then wait for results to settle.
    await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30_000 });
    await waitForSettled(() => seen.size, page);
  } finally {
    page.off("response", capture);
  }

  return {
    signedOut: looksSignedOut(page.url()),
    snapshots: toSnapshots(Array.from(seen.values()), storeSlug, query, new Date().toISOString()),
  };
}

/**
 * Reads one product's stock and price from its own product page. The page's
 * server-rendered HTML embeds the Apollo cache (`<script id=
 * "node-apollo-state">`, URL-encoded JSON) holding the same `Items` objects
 * search returns -- productId, size, price, availability -- for the store
 * and address the session is set to. Verified 2026-10-04 on Hy-Vee.
 *
 * Reading it from the HTML (not a GraphQL response) means no waiting for
 * client-side requests, and nothing depends on Instacart's query hashes.
 * An empty result means the page carried no data for that ID: discontinued
 * at this store, the ID changed, or the page layout changed (run.ts decides
 * which by whether *anything* is being captured).
 */
export async function lookupProduct(page: Page, storeSlug: string, productId: string, description: string): Promise<SearchResult> {
  const url = `https://www.instacart.com/store/${storeSlug}/products/${encodeURIComponent(productId)}`;
  const response = await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30_000 });
  const html = response ? await response.text() : "";

  const item = itemsFromApolloState(html).find((it) => String(it.productId) === productId);
  return {
    signedOut: looksSignedOut(page.url()),
    snapshots: item ? toSnapshots([item], storeSlug, description, new Date().toISOString()) : [],
  };
}

/** Every product object (has productId + availability) in a page's embedded Apollo cache. */
export function itemsFromApolloState(html: string): any[] {
  const m = html.match(/<script[^>]*id="node-apollo-state"[^>]*>([\s\S]*?)<\/script>/);
  if (!m) return [];

  let state: unknown;
  const raw = m[1].trim();
  for (const decode of [(s: string) => JSON.parse(decodeURIComponent(s)), (s: string) => JSON.parse(s)]) {
    try {
      state = decode(raw);
      break;
    } catch {
      // try the next encoding
    }
  }

  const found = new Map<string, any>();
  const walk = (node: any, depth: number) => {
    if (!node || typeof node !== "object" || depth > 12) return;
    if (node.productId && node.availability && !found.has(String(node.productId))) {
      found.set(String(node.productId), node);
      return;
    }
    for (const v of Object.values(node)) walk(v, depth + 1);
  };
  walk(state, 0);
  return Array.from(found.values());
}

/**
 * Search results stream in over several GraphQL responses after the page
 * loads. Wait until the captured count has stopped changing for `quietMs`
 * (after at least one result), or give up at `maxMs` and keep whatever
 * arrived -- an empty capture is judged by run.ts, not here.
 */
export async function waitForSettled(
  count: () => number,
  page: Pick<Page, "waitForTimeout">,
  { quietMs = 2500, maxMs = 15_000, stepMs = 250 } = {}
): Promise<void> {
  let last = -1;
  let stableFor = 0;
  for (let waited = 0; waited < maxMs; waited += stepMs) {
    await page.waitForTimeout(stepMs);
    const now = count();
    stableFor = now === last ? stableFor + stepMs : 0;
    last = now;
    if (now > 0 && stableFor >= quietMs) return;
  }
}

/** Product objects from one captured GraphQL response, or [] if it isn't a search response. */
export function extractItems(operationName: string | null, json: any): any[] {
  let items: any[];
  if (operationName === "Items") {
    items = json?.data?.items ?? [];
  } else if (operationName === "SearchResultsPlacements") {
    items = (json?.data?.searchResultsPlacements?.placements ?? []).flatMap(
      (p: any) => p?.content?.placement?.items ?? []
    );
  } else {
    return [];
  }
  return items.filter((it) => it?.productId);
}

export function toSnapshots(items: any[], storeSlug: string, query: string, observedAt: string): AvailabilitySnapshot[] {
  return items.map((it) => ({
    storeSlug,
    instacartProductId: String(it.productId), // always a string; never parse IDs as numbers
    observedAt,
    available: it?.availability?.available === true,
    stockLevel: it?.availability?.stockLevel ?? null,
    price: parsePriceString(it?.price?.viewSection?.priceValueString),
    sizeText: it?.size ?? null,
    name: it?.name ?? null,
    query,
  }));
}

export function parsePriceString(v: unknown): number | null {
  if (typeof v !== "string") return null;
  const n = Number(v.replace(/[$,\s]/g, ""));
  return v.trim() !== "" && Number.isFinite(n) ? n : null;
}

/** Did navigation land on a sign-in / sign-up page instead of the storefront? */
export function looksSignedOut(finalUrl: string): boolean {
  try {
    const u = new URL(finalUrl);
    return /(^|\/)(login|log-in|signin|sign-in|signup|sign-up)(\/|$)/i.test(u.pathname) || /^accounts?\./i.test(u.hostname);
  } catch {
    return false;
  }
}
