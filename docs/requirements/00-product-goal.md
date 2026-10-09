# Product goal

Status: **draft**. Every other requirements doc should be checked against
this one.

## The problem

Tyler built this to reduce Katie's **mental load** from juggling grocery
orders across several stores. Katie's reaction to "the app tells you how to
divide everything up": helpful... *ish*.

That's the bar the app has to clear: it's only worth using if it takes
**less** thinking and effort than doing it by hand. A tool that makes good
decisions but adds setup or copying work can easily come out behind.

## How Katie actually shops

**Spontaneous, not planned weekly.** Katie:

> I usually see a recipe or think of something that sounds good, and build
> my orders based on that. I don't make a plan for all of the week's meals
> or anything, because by the time it comes to make whatever I had planned
> on, it doesn't sound good anymore.

Orders start from **a craving or a recipe, plus whatever staple anchors are
due**. They're small and frequent (Walmart: about one a week, $30–84), and
some are needed the same day.

What that means for the app:

- **Adding a few items on the fly has to be fast**, ideally from her phone,
  with a plan she can see right away. A weekly planning session is not the
  main way she'll use it.
- **A "build this week's list" flow is secondary.** The current "Add
  staples" button (which builds a weekly list) is useful for staples, but
  the app shouldn't assume a week of meals is planned up front.
- **Waiting fits this well.** Spontaneous items for a store she isn't
  ordering from today can sit in that store's cart until its next order
  (S3 in [delivery-speed.md](delivery-speed.md)).
- **Recipes** (there's a recipes table in the database) should help with
  "I saw this recipe, add what I need", not with meal planning.

## Where the mental load comes from

From the other docs so far:

- **Re-planning when an anchor is out of stock.** Sam's has no milk, so the
  whole order moves to Costco: find equivalents, re-check the minimum, pad
  it out ([anchor-items-and-store-fallback.md](anchor-items-and-store-fallback.md)).
- **Keeping track of minimums and fees** at every store, including which
  orders to wait on so she isn't paying two sets of fees in one day.
- **Speed vs. cost** when something is needed soon
  ([delivery-speed.md](delivery-speed.md)).
- **Many open carts** across stores, most of which never get placed.

**Deciding is the harder part** (Katie, 2026-10-09). Doing (filling carts)
would be nice to have, but she knows the options there are limited. So the
app is aimed at the right problem. Priorities follow from that:

1. **Must:** good decisions she can trust: anchors, fallbacks, minimums,
   holding, speed.
2. **Should:** make the doing cheap where it's easy. For example, link each
   planned item to its product page so adding it is one tap.
3. **Could:** fill carts automatically, only if it can be done safely.
   Nothing touches carts today (the sidecar only reads pages). If that ever
   changes, including checking real fees and minimums with items in a
   cart:
   - **Only ever use Katie's personal Instacart cart.** Her Instacart is a
     family plan, and her sister-in-law orders from the shared family
     cart.
   - If the app can't tell which cart is the personal one, it stops and
     says so.
   - It never places an order. Checking out is always Katie's step.

## What the app does today

It **decides**: given a list, it picks a store for each item using her
usual stores, live stock and minimums. It doesn't **do**: Katie still adds
every item to each store's cart herself, and the list page doesn't link to
the products on Instacart.

## Ways it could add work instead of removing it

These are the risks to watch:

1. **Setup.** Most of the 499 products imported from her order history
   still need linking to items by hand on the Matching page before the
   planner can use them.
2. **Copying.** Moving the plan into Instacart and Walmart by hand, item by
   item.
3. **Trust.** If the plan is wrong often enough (stale stock, wrong
   quantity, an anchor moved away), she has to double-check everything,
   and then the app saves nothing.

## Success, in Katie's terms

**OPEN:** to fill in with Katie. Draft:

- When Sam's is out of milk, she can see the new plan without re-planning
  it in her head.
- Getting from "here's my list" to "carts are filled" takes less time than
  it does today.
- She trusts the plan enough not to second-guess every line.
