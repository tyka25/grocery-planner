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
  query: string; // the search term that surfaced this item
}

export interface StoreTarget {
  /** Instacart storefront slug, e.g. "hy-vee", "costco", "sams-club", "fareway-meat-grocery". */
  slug: string;
  /** "delivery" or "pickup" -- Fareway is pickup-only; confirmed via the storefront's own fulfillment control. */
  fulfillment: "delivery" | "pickup";
}
