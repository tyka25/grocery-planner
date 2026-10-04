/** Mirrors the `availability_snapshots` table in the Laravel app. */
export interface AvailabilitySnapshot {
  storeSlug: string;
  instacartProductId: string;
  observedAt: string; // ISO 8601
  available: boolean;
  /**
   * Free text, not an enum: confirmed values so far are "highlyInStock" and
   * "inStock" (Hy-Vee, Costco, Sam's Club, Fareway-pickup all use the same
   * shape). No out-of-stock example has been observed yet -- log whatever
   * comes back here rather than assuming a fixed set of values.
   */
  stockLevel: string | null;
  price: number | null;
  sizeText: string | null;
  /** Product name as the storefront shows it (logging/debugging; the web app ignores it). */
  name: string | null;
  query: string; // the search term, or product description for a product-page lookup
}

/** One product the web app wants checked at one store (GET /api/sidecar/work). */
export interface Lookup {
  /** Instacart storefront slug, e.g. "hy-vee", "costco", "sams-club", "fareway-meat-grocery". */
  storeSlug: string;
  instacartProductId: string;
  /** The household's description of it, for logs (stored as the snapshot's `query`). */
  description: string;
}

export interface Work {
  runId: number | null;
  lookups: Lookup[];
}

export type RunStatus = "ok" | "session_expired" | "error";

export interface SearchResult {
  snapshots: AvailabilitySnapshot[];
  /** The page ended up on a sign-in screen instead of the store. */
  signedOut: boolean;
}
