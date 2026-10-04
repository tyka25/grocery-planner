import type { Api } from "./api.js";
import type { AvailabilitySnapshot, Lookup, RunStatus, SearchResult } from "./types.js";

/** A logged-in storefront session. */
export interface StoreBrowser {
  lookup(storeSlug: string, instacartProductId: string, description: string): Promise<SearchResult>;
  search(storeSlug: string, query: string): Promise<SearchResult>;
  close(): Promise<void>;
}

/** No saved Instacart session on disk -- a human needs to run `npm run login`. */
export class SessionMissingError extends Error {}

export interface RunDeps {
  api: Pick<Api, "getWork" | "postSnapshots" | "finish">;
  openBrowser: () => Promise<StoreBrowser>;
  sleep: (ms: number) => Promise<void>;
  /** Pause between page loads, so a run looks like a person browsing, not a burst. */
  paceMs: () => number;
  log: (msg: string) => void;
}

/**
 * If this many product pages in a row carry no product data before anything
 * has been captured at all, stop: it's a sign-in wall we didn't recognise, or
 * Instacart changed its page. Posting an empty "ok" run would look like
 * "everything checked, nothing found" -- the silent failure to avoid.
 */
export const EMPTY_CAPTURE_LIMIT = 3;

const RELOGIN = "Run `npm run login` in the sidecar folder on the home computer to sign in again.";

/**
 * Ask the web app for work and do it. Every claimed run is finished --
 * ok, session_expired or error -- so the web app never shows a run as
 * silently "running" forever.
 */
export async function runOnce(deps: RunDeps): Promise<{ runId: number | null; status?: string }> {
  const { api, log } = deps;
  const work = await api.getWork();
  if (work.runId === null) {
    return { runId: null };
  }

  const runId = work.runId;
  log(`Run ${runId}: ${work.lookups.length} products to check.`);
  let searches = 0; // page loads; reported to the web app as `searches`
  let browser: StoreBrowser | undefined;

  const finish = async (status: RunStatus, error: string | null = null) => {
    const result = await api.finish(runId, status, searches, error);
    log(`Run ${runId} finished: ${result.status}${result.error ? ` (${result.error})` : ""}`);
    return { runId, status: result.status };
  };

  try {
    browser = await deps.openBrowser();
    let capturedAnything = false;

    for (const [storeSlug, storeLookups] of groupByStore(work.lookups)) {
      const batch: AvailabilitySnapshot[] = [];
      const flush = async () => {
        if (batch.length) {
          const r = await api.postSnapshots(runId, batch.splice(0));
          log(`  ${storeSlug}: saved ${r.saved}, ${r.unknown} not tracked.`);
        }
      };

      for (const l of storeLookups) {
        if (searches > 0) await deps.sleep(deps.paceMs());
        const result = await browser.lookup(storeSlug, l.instacartProductId, l.description);
        searches++;

        if (result.signedOut) {
          await flush();
          return await finish("session_expired", `Instacart showed a sign-in page while checking ${storeSlug}. ${RELOGIN}`);
        }

        const s = result.snapshots[0];
        log(`  ${storeSlug} ${l.instacartProductId} "${l.description}": ${s ? `${s.available ? "available" : "UNAVAILABLE"} (${s.stockLevel ?? "no level"}), $${s.price ?? "?"}` : "no product data on its page"}`);

        if (result.snapshots.length > 0) {
          capturedAnything = true;
        } else if (!capturedAnything && searches >= EMPTY_CAPTURE_LIMIT) {
          return await finish(
            "error",
            `The first ${searches} product pages carried no product data. Either the Instacart session expired in a way the sidecar didn't recognise, or Instacart changed its page. ${RELOGIN} If that doesn't fix it, the capture code needs updating.`
          );
        }
        batch.push(...result.snapshots);
      }
      await flush();
    }

    return await finish("ok");
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    const status: RunStatus = err instanceof SessionMissingError ? "session_expired" : "error";
    try {
      return await finish(status, status === "session_expired" ? `${message} ${RELOGIN}` : message);
    } catch (finishErr) {
      log(`Run ${runId}: could not report failure (${finishErr}). The web app will mark it stalled.`);
      throw err;
    }
  } finally {
    await browser?.close().catch(() => undefined);
  }
}

function groupByStore(lookups: Lookup[]): Map<string, Lookup[]> {
  const by = new Map<string, Lookup[]>();
  for (const s of lookups) {
    by.set(s.storeSlug, [...(by.get(s.storeSlug) ?? []), s]);
  }
  return by;
}
