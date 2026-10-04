import assert from "node:assert/strict";
import { test } from "node:test";
import { extractItems, itemsFromApolloState, looksSignedOut, parsePriceString, toSnapshots, waitForSettled } from "./instacart.js";

// Shapes as captured by hand on 2026-10-04 (trimmed to the fields we read).
const item = (productId: string, extra: object = {}) => ({
  productId,
  size: "48 oz",
  price: { viewSection: { priceValueString: "6.49" } },
  availability: { available: true, stockLevel: "highlyInStock" },
  ...extra,
});

test("extracts items from both search operations and ignores everything else", () => {
  assert.equal(extractItems("Items", { data: { items: [item("1"), item("2")] } }).length, 2);
  assert.equal(
    extractItems("SearchResultsPlacements", {
      data: { searchResultsPlacements: { placements: [{ content: { placement: { items: [item("3")] } } }, { content: {} }] } },
    }).length,
    1
  );
  assert.deepEqual(extractItems("CartData", { data: { items: [item("1")] } }), []);
  assert.deepEqual(extractItems(null, {}), []);
  assert.deepEqual(extractItems("Items", { data: { items: [{ name: "no id" }] } }), []);
});

test("snapshots keep product IDs as strings and treat anything but available: true as unavailable", () => {
  const [a, b] = toSnapshots(
    [item("21328956422496480"), item("42", { availability: { stockLevel: "lowStock" } })],
    "costco",
    "greek yogurt",
    "2026-10-04T12:00:00.000Z"
  );
  assert.equal(a.instacartProductId, "21328956422496480");
  assert.equal(a.available, true);
  assert.equal(a.price, 6.49);
  assert.equal(b.available, false);
  assert.equal(b.stockLevel, "lowStock");
});

test("parses price strings defensively", () => {
  assert.equal(parsePriceString("6.49"), 6.49);
  assert.equal(parsePriceString("$1,299.00"), 1299);
  assert.equal(parsePriceString(""), null);
  assert.equal(parsePriceString("about 3"), null);
  assert.equal(parsePriceString(6.49), null);
});

test("waits for results to stop arriving, and gives up on an empty page", async () => {
  let clock = 0;
  const page = { waitForTimeout: async (ms: number) => void (clock += ms) };

  // Results arrive at 1s and 2s, then nothing: settles 2.5s after the last one.
  await waitForSettled(() => (clock >= 2000 ? 12 : clock >= 1000 ? 5 : 0), page);
  assert.ok(clock >= 4500 && clock <= 5000, `settled at ${clock}ms`);

  clock = 0;
  await waitForSettled(() => 0, page);
  assert.equal(clock, 15_000);
});

test("reads products from a product page's embedded, URL-encoded Apollo state", () => {
  // Same nesting as the real Hy-Vee page (2026-10-04): Items -> {variables json} -> items[].
  const state = {
    __META: { type: "DocumentCache" },
    Items: { '{"ids":["items_25533-19036914"],"postalCode":"50000"}': { items: [item("19036914", { name: "Hy-Vee Hy-Vee Half & Half" })] } },
    RecommendedItemsQuery: { x: { items: [item("19037855"), { productId: "no-availability" }] } },
  };
  const html = `<html><script id="node-state">{}</script><script type="application/json" id="node-apollo-state">${encodeURIComponent(JSON.stringify(state))}</script></html>`;

  const items = itemsFromApolloState(html);
  assert.deepEqual(items.map((i) => i.productId), ["19036914", "19037855"]);
  assert.equal(items[0].availability.stockLevel, "highlyInStock");
  assert.deepEqual(itemsFromApolloState("<html>no state</html>"), []);
  assert.deepEqual(itemsFromApolloState('<script id="node-apollo-state">%7Bbroken</script>'), []);
});

test("recognises sign-in pages but not storefront pages", () => {
  assert.equal(looksSignedOut("https://www.instacart.com/login?next=%2Fstore"), true);
  assert.equal(looksSignedOut("https://www.instacart.com/signup"), true);
  assert.equal(looksSignedOut("https://accounts.instacart.com/x"), true);
  assert.equal(looksSignedOut("https://www.instacart.com/store/hy-vee/s?k=login%20bars"), false);
  assert.equal(looksSignedOut("https://www.instacart.com/store/costco/s?k=milk"), false);
});
