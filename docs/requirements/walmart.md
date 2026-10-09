# Walmart

Status: **draft**, being gathered from Katie. Open questions are marked
**OPEN**.

Priority: **next most important thing** (Katie, 2026-10-09). Without
Walmart, the app can't plan the store she uses most for everyday items.

## Why

> Walmart is my top choice of store for non-bulk items, i.e. stuff that you
> can't get at Costco or Sam's, because I'm pretty sure I can count on it
> being the cheapest for almost everything.

How Katie's stores fit together:

| Role | Stores |
| --- | --- |
| Bulk | Sam's (first choice), Costco |
| Everyday (non-bulk) | **Walmart (first choice)**, then Aldi, Hy-Vee and others |
| Only-here items | Fareway (e.g. the bread) |

## Price: where it fits

> The way I approach this isn't always about where something is the
> cheapest, but I do still take it into account when I can.

This matches the app's current design, and it's worth saying explicitly:
**price is already built into each item's store ranking.** Sam's is first
for milk *because* it's cheaper than Costco. Walmart would be first for
most everyday items *because* it's usually cheapest. So the planner
doesn't need to compare prices on every plan. It follows her ranking,
and anchors and minimums override that ranking when they need to. The 2x
price-outlier guardrail in `CLAUDE.md` is still a good safety net.

## Requirements

### W1. Walmart is a store the planner can assign items to

It works like any other store: a place in each item's ranked list, its own
minimum and fees, and it can hold anchors.

### W2. The plan still works when Walmart's stock can't be checked

Walmart isn't on Instacart, so the existing stock checker can't read it
(see "Feasibility" below). Walmart should be usable even before live stock
checks exist, and should keep working if they break.

Acceptance criteria (draft):

- **Given** Walmart has no stock check (none built yet, or the last check
  failed),
  **then** the planner still assigns items to Walmart by ranking, and the
  plan marks those items "Stock not checked" instead of treating them as
  in stock.
- **Given** a Walmart item's stock is unknown,
  **then** it's never used as an anchor's fallback without saying so
  (e.g. "Costco out, Walmart not checked").

### W3. Live stock and price at Walmart (if it can be done)

Same as the Instacart stores: in stock or not, and current price, for the
products linked to each item.

## Feasibility (for Tyler)

Researched 2026-10-09. Nothing below has been tried.

**1. Official API: exists, almost certainly not open to us.** Walmart's
"Online pickup & delivery" (OPD) APIs do exactly what we'd want:
real-time price and availability for an item **at a specific store**
(up to 20 items, 1 store per call), plus a store locator and an
add-to-cart link. But access is a private partner program: you need an
affiliate account with Impact Radius, then a Walmart business manager
decides "if your company fits the criteria of our private partnership
network", and they say they may not answer every request. A household app
is very unlikely to qualify. It's free to ask, but don't plan around it.
Docs: [OPD introduction](https://www.walmart.io/docs/opd/v1/grocery-introduction),
[realtime price and availability](https://www.walmart.io/docs/opd/v1/catalog-pricing-and-availability-realtime).

**2. Paid third-party services.** Services such as SerpApi sell Walmart
product data and accept a store ID. They cost money per lookup, and they
get their data by scraping, so they carry the same terms-of-service
question as the Instacart sidecar, just handled by someone else.
Worth pricing out if 3 and 4 aren't enough.

**3. The sidecar reads walmart.com in Katie's signed-in browser** (the
same approach as Instacart). Walmart is known for blocking automated
browsers more aggressively than Instacart. Worth one honest try at low
volume. If Walmart shows a bot challenge, stop there. Don't try to get
around it.

**4. No live data (W2).** Always possible. The planner uses Katie's
ranking and marks Walmart items "Stock not checked".

**Order history:** unknown whether Walmart offers an export like
Instacart's CSVs. Without one, Walmart's place in each item's ranking and
its prices are entered by hand.

## How Katie orders from Walmart

- **Almost always delivery**, through Walmart's own site/app. Walmart isn't
  on Instacart (Katie's understanding).
- **Usually the slowest store.** Fast delivery is possible, but costs about
  $5–10 extra. See [delivery-speed.md](delivery-speed.md).

- **Has Walmart+.** Walmart+ includes free delivery on orders of $35 or
  more (general Walmart+ terms; check the exact minimum and the fee for
  smaller orders in her account before setting them in the app).

## What Katie's Walmart history shows

Reviewed with Katie on 2026-10-09 (her purchase history, read only).
About a month of recent grocery orders, roughly one a week.

- **Stock problems were rare and minor:** one substitution and one
  unavailable item across all of those orders. Neither was an anchor.
  So far, **Walmart behaves like the reliable default store**, not a
  place where stock-outs force a re-plan.
- **Everyday staples repeat** (chicken, protein bars, oats, berries), and
  Walmart is the cheapest place for several of them. Organic blueberries
  came from Walmart while Instacart showed them out of stock at Aldi and
  Hy-Vee (`CLAUDE.md`, 2026-10-04).
- **Milk wasn't bought at Walmart.** Walmart orders also include
  non-grocery items, which the app doesn't plan.

**Fees and speed, from real orders:**

- Store delivery is free with Walmart+ (normally $9.95).
- **Express costs $10**, and Katie does use it occasionally when she needs
  something the same day and Walmart is still the best option. That's the
  comparison [delivery-speed.md](delivery-speed.md) asks the app to make.
- Shelf-stable items can **ship** with no order minimum, just slower. Her
  orders are usually spontaneous, so this rarely fits. **Not a
  requirement for now.** At most, a later option the app shows but never
  picks on its own.

**What this suggests:** live Walmart stock data (W3) is a nice-to-have,
not a blocker. Planning Walmart from Katie's rankings and marking items
"Stock not checked" (W2) would have been right for every problem seen in
this history. It's a small sample, so keep an eye on it.

## Open questions

None right now.
