import type { AvailabilitySnapshot, RunStatus, Work } from "./types.js";

/** Client for the web app's sidecar API (web/routes/api.php). */
export class Api {
  constructor(private baseUrl: string, private token: string) {}

  getWork(): Promise<Work> {
    return this.request("GET", "/sidecar/work");
  }

  postSnapshots(runId: number, snapshots: AvailabilitySnapshot[]): Promise<{ saved: number; unknown: number }> {
    return this.request("POST", `/sidecar/runs/${runId}/snapshots`, { snapshots });
  }

  finish(runId: number, status: RunStatus, searches: number, error: string | null = null): Promise<{ status: string; error: string | null }> {
    return this.request("POST", `/sidecar/runs/${runId}/finish`, { status, searches, error });
  }

  private async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    const res = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${this.token}`,
        ...(body ? { "Content-Type": "application/json" } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) {
      throw new Error(`${method} ${path} failed: ${res.status} ${(await res.text()).slice(0, 500)}`);
    }
    return (await res.json()) as T;
  }
}
