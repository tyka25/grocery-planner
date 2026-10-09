# Anchor items and store fallback

Status: **draft**, being gathered from Katie (the person who does the
shopping). Open questions are marked **OPEN**.

## The problem, in Katie's words

> Each time I'm creating a grocery order there's probably at least one thing
> that I'm building the whole thing around. Milk is often that item.

Milk example:

1. First choice is **Sam's Club**: organic vitamin D milk, 3-pack of
   half-gallons. Cheaper than Costco.
2. Sam's is **often out of stock**. When it is, Katie switches to
   **Costco** (also a 3-pack).
3. Switching isn't just about the milk. If a Sam's cart is already started
   and full, she has to:
   - find Costco equivalents for everything else in that cart (some don't
     exist at Costco), and
   - find other things to add at Costco to reach the order minimum
     ("pad the order").
4. If Costco is out too, she falls back to a **third choice (Aldi)**, then a
   **fourth (Walmart or Hy-Vee)**, and the same cycle repeats at each store.

Anchors aren't always milk, and there can be more than one:

> Sometimes there are multiple anchors/non-negotiables in an order. There is
> a specific kind of bread I like that I can only get at Fareway, so
> sometimes that is the anchor.

## Requirements

### R1. Each item has its own ranked list of stores

Milk: Sam's → Costco → Aldi → Walmart / Hy-Vee. Other staples will have
different lists, and not every staple is sold at every store. Some items
are sold at only one store (the Fareway bread).

*Where the app is today:* already supported. Each item has its stores in
preference order (`item_store_prefs.rank`), and the planner uses the first
store that isn't out of stock.

### R2. Anchors are chosen per order, and an order can have several

Whether something is an anchor depends on the week, so it's a choice made
on **this list**, not a permanent setting on the item. Two kinds:

- **Must-have, flexible store:** milk. It has to be in the order, and it
  goes to the best store that has it in stock.
- **Must-have, one store only:** the Fareway bread. If it's on the list,
  that store is in the plan.

Each anchor's store becomes a fixed order that the rest of the list is
built around.

*Where the app is today:* not supported, and possibly working against
this. The planner moves only the out-of-stock item. If Costco ends up with
just the milk, that small Costco order looks like a problem to the "minimum
repair" step, which may move the milk *away* from Costco to some other
store, the opposite of what Katie would do. (To confirm with Tyler; this is
a reading of the code's documented rules, not a tested result.)

Acceptance criteria (draft):

- **Given** milk is marked as an anchor on this week's list and Sam's is
  out of stock,
  **when** the plan is built,
  **then** milk goes to the next store on its list that has it in stock
  (Costco),
  **and** the minimum-repair step never moves an anchor to make another
  order work.
- **Given** two anchors at different stores (milk at Costco, bread at
  Fareway),
  **then** both stores are in the plan, and the rest of the list is
  spread across them before any other store is added.
- **Given** an anchor is out of stock at every store on its list,
  **then** the app says so at the top of the plan, not just on that line,
  since the whole order was built around it.

### R3. Filling an anchor's order to its minimum

When an anchor's store is under its minimum, Katie fills it in one of two
ways, depending on the week:

1. **Move items already on the list** to that store, if the store carries
   them. When there's a lot on the list, this is usually enough.
2. **Pad it** with things she'd buy eventually anyway, when the list
   alone can't reach the minimum.

Acceptance criteria (draft):

- **Given** the anchor's store is under its minimum,
  **then** the app first moves other list items there (only items that
  store carries, and never hand-placed items).
- **Given** it's still under the minimum after that,
  **then** the app shows how far short it is (e.g. "$12 short of Costco's
  $35 minimum").
- **Given** an item on the list has no equivalent at the anchor's store,
  **then** it stays at its own usual store, and the app says so (e.g.
  "Not at Costco, kept at Hy-Vee").

*Where the app is today:* step 1 partly exists (minimum repair can move
lines into stores already in the plan). Padding doesn't exist.

**Padding suggestions are a nice-to-have, not a requirement.** Katie:

> If it was good at this, sure, but I'm skeptical of it actually being good
> at it. A lot of apps try this and it doesn't always end up being useful.

Showing the shortfall is the must-have. Suggestions should only be built if
they can be good, and they should never be added to the order without her
picking them.

### R4. Quantity can depend on the store, and less than usual can be fine

Katie buys milk as a 3-pack of half-gallons at Sam's or Costco. At Aldi,
where half-gallons are sold one at a time with no box, **2 is fine**.
She chooses it for convenience, not because the volume works out.

This answers Tyler's open questions in `CLAUDE.md`:

- **Question 1 (total volume or number of units?):** for milk, it's
  "how many of this product to buy *at this store*", not a total volume
  to hit. The app doesn't need to calculate it.
- **Question 2 (is falling short ever okay?):** **yes**. 2 half-gallons
  (1 gal) is an acceptable stand-in for the usual 3 (1.5 gal). The current
  assumption that the app never buys less than the target is **wrong for
  milk**, and should not be built as stated.

#### The general pattern: bulk amount vs. bridge amount

It isn't just milk. Katie:

> If we're ordering something from a big box store (Sam's or Costco), we're
> not necessarily in need of that same amount from a backup store like Aldi.
> If it's something I need badly, which I probably do if I'm resorting to a
> backup store, I probably won't need the same large amount.

So falling back from a bulk store to a regular store changes what the
purchase *is*: it's no longer stocking up, it's **getting by until the next
bulk order**. Two amounts per item:

- **Bulk amount:** what she buys at Sam's or Costco (milk: one 3-pack).
- **Bridge amount:** what she buys at a regular store when the bulk
  stores are out (milk: 2 half-gallons).

Acceptance criteria (draft):

- **Given** an item's first choice is a bulk store and it falls back to a
  regular store,
  **then** the plan uses the item's bridge amount, and shows that it did
  (e.g. "2 instead of 3-pack, Sam's and Costco out").
- **Given** an item has no bridge amount set,
  **then** the app keeps the same quantity and lets her change it on the
  list. It never guesses a smaller amount on its own.
- **Given** an item falls back from one bulk store to another (Sam's to
  Costco),
  **then** the bulk amount stays the same.

Exceptions are expected, so the amount on the list stays editable.

*Note for Tyler:* this replaces the "combine packs to hit a target volume"
plan. Each item only needs a bulk amount, an optional bridge amount, and a
yes/no on each store for whether it's a bulk store.

### R5. Walmart

Walmart is Katie's first choice for everyday (non-bulk) items. See
[walmart.md](walmart.md).

## Open questions

None right now for this doc. Walmart has its own doc.
