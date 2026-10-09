# Delivery speed

Status: **draft**, being gathered from Katie. Open questions are marked
**OPEN**.

## Why

Sometimes an order is needed quickly, and that changes which store is the
best choice. Katie:

> Walmart is probably the slowest too. That comes into play if I need an
> order quick. You CAN get stuff quick from Walmart, but you have to pay 5
> or 10 dollars extra. Instacart for Hy-Vee and Aldi are usually faster.
> Faster deliveries are usually an option on Instacart for 1 or 2 dollars
> extra.

What we know so far (Katie's experience, **not measured**):

| Store | Normal speed | Faster delivery |
| --- | --- | --- |
| Walmart (direct) | Slowest | About $5–10 extra |
| Hy-Vee, Aldi (Instacart) | Usually faster | About $1–2 extra |

## Requirements

### S1. Items can be marked "need it soon"

Urgency usually applies to **some items, not the whole order**. Katie:

> I rarely need everything in the need-it-soon order immediately, but that
> might not always be true.

So urgency is marked per item, with a one-tap way to mark everything on
the list.

Order of priority when building the plan (Katie confirmed, 2026-10-09):

1. **Anchors** stay on their store.
2. **Urgent items** go where they can arrive fast for the least extra cost.
3. **Everything else** follows its normal store ranking.

Acceptance criteria (draft):

- **Given** nothing on the list is marked urgent,
  **then** the plan is the same as today (speed is ignored).
- **Given** some items are marked urgent,
  **then** each store's faster-delivery fee counts toward the cost of
  putting those items there, so a slow store (Walmart, +$5–10) can lose to
  a faster one (Hy-Vee, +$1–2) for items both carry.
- **Given** urgent items would end up in their own small order,
  **then** the app shows what that costs (extra order, fees, minimum) next
  to the alternative of waiting, and lets her choose. It never splits the
  order silently.
- **Given** an anchor is on the list,
  **then** it isn't moved off its store just because another store is
  faster.
- **Given** the plan moved items because of speed,
  **then** it says so (e.g. "Hy-Vee instead of Walmart: faster").

### S2. Speed fees are settings, not guesses

Each store's faster-delivery fee is stored as a setting Katie can change
(like the $10 pickup-trip cost in `CLAUDE.md`), since today's numbers are
estimates.

*Note for Tyler:* Instacart shows delivery windows on the storefront. It
may be possible to read the earliest delivery time instead of relying on a
fixed fee. Not checked.

### S3. Non-urgent items can wait instead of starting a second order

The flip side of urgency. Splitting across stores on the same day is
allowed, but Katie avoids it when the second store's items aren't urgent,
so she isn't paying fees on two orders that day. Katie:

> If any other stuff needs added to that second store, it leaves more time
> for that.

She keeps the waiting items in that store's real cart (she starts carts
constantly, and plenty are never placed), so the app doesn't need to track
them.

Acceptance criteria (draft):

- **Given** a non-urgent item whose store isn't otherwise in today's plan,
  **then** the plan suggests it can wait for that store's next order,
  instead of counting it as a second order today.
- **Given** an item is urgent or an anchor,
  **then** it's never suggested to wait.
- **Given** Katie orders from the second store today anyway,
  **then** that's allowed. Splitting is a choice, never a block.

## Open questions

None right now.
