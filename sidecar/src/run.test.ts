import assert from "node:assert/strict";
import { test } from "node:test";
import { EMPTY_CAPTURE_LIMIT, runOnce, SessionMissingError, type RunDeps, type StoreBrowser } from "./run.js";
import type { AvailabilitySnapshot, Lookup, SearchResult, Work } from "./types.js";

const snap = (storeSlug: string, id: string): AvailabilitySnapshot => ({
  storeSlug, instacartProductId: id, observedAt: "2026-10-04T12:00:00Z", available: true, stockLevel: "inStock", price: 1, sizeText: null, name: null, query: "q",
});

function fakes(work: Work, results: (store: string, productId: string) => SearchResult | Error) {
  const calls = { posted: [] as AvailabilitySnapshot[][], finished: [] as { status: string; searches: number; error: string | null }[], closed: 0 };
  const browser: StoreBrowser = {
    lookup: async (store, productId) => {
      const r = results(store, productId);
      if (r instanceof Error) throw r;
      return r;
    },
    search: async () => ({ snapshots: [], signedOut: false }),
    close: async () => void calls.closed++,
  };
  const deps: RunDeps = {
    api: {
      getWork: async () => work,
      postSnapshots: async (_id, s) => (calls.posted.push(s), { saved: s.length, unknown: 0 }),
      finish: async (_id, status, searches, error = null) => (calls.finished.push({ status, searches, error }), { status, error }),
    },
    openBrowser: async () => browser,
    sleep: async () => undefined,
    paceMs: () => 0,
    log: () => undefined,
  };
  return { deps, calls };
}

const lookup = (storeSlug: string, id: string): Lookup => ({ storeSlug, instacartProductId: id, description: `product ${id}` });
const found = (store: string, id: string): SearchResult => ({ snapshots: [snap(store, id)], signedOut: false });
const empty: SearchResult = { snapshots: [], signedOut: false };

test("does nothing when no check is due", async () => {
  const { deps, calls } = fakes({ runId: null, lookups: [] }, () => empty);
  assert.deepEqual(await runOnce(deps), { runId: null });
  assert.equal(calls.finished.length, 0);
});

test("posts one batch per store and finishes ok", async () => {
  const work = { runId: 7, lookups: [lookup("hy-vee", "1"), lookup("hy-vee", "2"), lookup("costco", "3")] };
  const { deps, calls } = fakes(work, found);

  assert.deepEqual(await runOnce(deps), { runId: 7, status: "ok" });
  assert.deepEqual(calls.posted.map((b) => b.map((s) => s.instacartProductId)), [["1", "2"], ["3"]]);
  assert.deepEqual(calls.finished, [{ status: "ok", searches: 3, error: null }]);
  assert.equal(calls.closed, 1);
});

test("a sign-in page ends the run as session_expired, keeping what was already captured", async () => {
  const work = { runId: 8, lookups: [lookup("hy-vee", "1"), lookup("hy-vee", "2")] };
  const { deps, calls } = fakes(work, (store, id) => (id === "2" ? { snapshots: [], signedOut: true } : found(store, id)));

  await runOnce(deps);
  assert.equal(calls.posted.flat().length, 1);
  assert.equal(calls.finished[0].status, "session_expired");
  assert.match(calls.finished[0].error ?? "", /npm run login/);
});

test(`${EMPTY_CAPTURE_LIMIT} empty product pages in a row at the start is a loud error, not an empty ok`, async () => {
  const work = { runId: 9, lookups: Array.from({ length: 6 }, (_, i) => lookup("costco", String(i))) };
  const { deps, calls } = fakes(work, () => empty);

  await runOnce(deps);
  assert.equal(calls.finished[0].status, "error");
  assert.equal(calls.finished[0].searches, EMPTY_CAPTURE_LIMIT);
  assert.match(calls.finished[0].error ?? "", /carried no product data/);
});

test("an empty page after something was captured is fine (that product's just gone)", async () => {
  const work = { runId: 10, lookups: ["a", "b", "c", "d"].map((id) => lookup("costco", id)) };
  const { deps, calls } = fakes(work, (store, id) => (id === "a" ? found(store, id) : empty));

  await runOnce(deps);
  assert.equal(calls.finished[0].status, "ok");
});

test("a missing saved session and unexpected errors are both reported, never left running", async () => {
  const work = { runId: 11, lookups: [lookup("costco", "a")] };
  const missing = fakes(work, () => empty);
  missing.deps.openBrowser = async () => { throw new SessionMissingError("No saved Instacart session."); };
  await runOnce(missing.deps);
  assert.equal(missing.calls.finished[0].status, "session_expired");

  const crash = fakes(work, () => new Error("page.goto: net::ERR_INTERNET_DISCONNECTED"));
  await runOnce(crash.deps);
  assert.equal(crash.calls.finished[0].status, "error");
  assert.match(crash.calls.finished[0].error ?? "", /ERR_INTERNET_DISCONNECTED/);
  assert.equal(crash.calls.closed, 1);
});
