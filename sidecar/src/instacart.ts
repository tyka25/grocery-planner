import type { Page } from "playwright";
import type { AvailabilitySnapshot, StoreTarget } from "./types.js";

/**
 * Reads Instacart's own storefront search as JSON, from inside a real,
 * already-logged-in page -- it does not re-implement Instacart's API
 * against a hardcoded URL, because the operation names are Apollo
 * `operationName` values and the persisted-query hashes embedded in
 * Instacart's bundle change across their deploys. Instead this captures the
 * actual requests the loaded page makes and replays those exact URLs.
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
 */
export async function searchStore(
  page: Page,
  store: StoreTarget,
  query: string
): Promise<AvailabilitySnapshot[]> {
  const url = `https://www.instacart.com/store/${store.slug}/s?k=${encodeURIComponent(query)}`;

  const seen = new Map<string, any>();
  const capture = async (response: import("playwright").Response) => {
    const req = response.url();
    if (!req.includes("/graphql")) return;
    const op = new URL(req).searchParams.get("operationName");
    if (op !== "Items" && op !== "SearchResultsPlacements") return;
    try {
      const json = await response.json();
      const items: any[] =
        op === "Items"
          ? json?.data?.items ?? []
          : (json?.data?.searchResultsPlacements?.placements ?? []).flatMap(
              (p: any) => p?.content?.placement?.items ?? []
            );
      for (const it of items) {
        if (it?.productId) seen.set(it.productId, it);
      }
    } catch {
      // Non-JSON or shape changed; skip rather than crash the whole run.
    }
  };

  page.on("response", capture);
  try {
    await page.goto(url, { waitUntil: "networkidle" });
    // Search results stream in after initial load; give them a moment.
    await page.waitForTimeout(4000);
  } finally {
    page.off("response", capture);
  }

  const observedAt = new Date().toISOString();
  return Array.from(seen.values()).map((it) => ({
    storeSlug: store.slug,
    instacartProductId: String(it.productId),
    observedAt,
    available: Boolean(it?.availability?.available),
    stockLevel: it?.availability?.stockLevel ?? null,
    price: parsePriceString(it?.price?.viewSection?.priceValueString),
    sizeText: it?.size ?? null,
    query,
  }));
}

function parsePriceString(v: unknown): number | null {
  if (typeof v !== "string") return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
}
