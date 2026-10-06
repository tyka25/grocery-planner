import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const opts = { preserveScroll: true };

const REASON_STYLE = {
    preferred: 'text-gray-500',
    manual: 'text-indigo-600',
    fallback: 'text-amber-700',
    minimum_repair: 'text-amber-700',
};

const money = (n) => `$${n.toFixed(2)}`;

export default function Show({ list, lines, stores, catalog, stockCheck }) {
    const planned = list.status === 'planned';
    const staplesAvailable = catalog.some(
        (c) =>
            c.is_staple && !lines.some((l) => l.canonical_item_id === c.id),
    );

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Shopping list
                </h2>
            }
        >
            <Head title="Shopping list" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <AddItem catalog={catalog} />

                    {lines.some((l) => l.canonical_item_id) && (
                        <StockCheck status={stockCheck} />
                    )}

                    <div className="flex flex-wrap gap-2">
                        {staplesAvailable && (
                            <SecondaryButton
                                onClick={() =>
                                    router.post(route('list.staples'), {}, opts)
                                }
                            >
                                Add staples
                            </SecondaryButton>
                        )}
                        {lines.length > 0 && (
                            <PrimaryButton
                                onClick={() =>
                                    router.post(route('list.plan'), {}, opts)
                                }
                            >
                                {planned ? 'Re-plan stores' : 'Plan stores'}
                            </PrimaryButton>
                        )}
                        {lines.length > 0 && (
                            <SecondaryButton
                                className="ms-auto"
                                onClick={() =>
                                    confirmFinish() &&
                                    router.post(route('list.finish'), {}, opts)
                                }
                            >
                                Done shopping, start new list
                            </SecondaryButton>
                        )}
                    </div>

                    {lines.length === 0 && (
                        <p className="rounded-lg bg-white p-6 text-center text-sm text-gray-500 shadow-sm">
                            The list is empty. Add items above
                            {catalog.some((c) => c.is_staple)
                                ? ' or add your staples.'
                                : '. Star an item to make it a staple.'}
                        </p>
                    )}

                    {planned ? (
                        <PlannedView lines={lines} stores={stores} />
                    ) : (
                        lines.length > 0 && (
                            <Card>
                                {lines.map((l) => (
                                    <LineRow key={l.id} line={l} />
                                ))}
                            </Card>
                        )
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

// Finishing can't be undone from the UI, so guard against a stray tap.
function confirmFinish() {
    return window.confirm('Mark this list done and start a new one?');
}

function Card({ children, className = '' }) {
    return (
        <div
            className={
                'divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm ' +
                className
            }
        >
            {children}
        </div>
    );
}

// Same rules as CanonicalItem::nameKey(): case, spacing and the iPhone
// keyboard's curly quotes and smart dashes don't count.
const nameKey = (s) =>
    s
        .replace(/[‘’]/g, "'")
        .replace(/[“”]/g, '"')
        .replace(/[–—]/g, '-')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

// A suggestion must contain every typed word, e.g. "milk wh" finds
// "Organic A2 Whole Milk". Names starting with the text come first.
function suggestionsFor(catalog, text) {
    const key = nameKey(text);
    if (!key) return [];
    const words = key.split(' ');
    return catalog
        .filter((c) => words.every((w) => nameKey(c.name).includes(w)))
        .sort(
            (a, b) =>
                nameKey(b.name).startsWith(key) -
                nameKey(a.name).startsWith(key),
        )
        .slice(0, 6);
}

// Not a <datalist>: iOS Safari only shows those in the keyboard's
// suggestion bar, if at all, so on an iPhone nothing appeared.
function AddItem({ catalog }) {
    const [name, setName] = useState('');
    const [qty, setQty] = useState('');
    const [open, setOpen] = useState(false);
    const known = catalog.some((c) => nameKey(c.name) === nameKey(name));
    const suggestions = known ? [] : suggestionsFor(catalog, name);

    const submit = (e) => {
        e.preventDefault();
        if (!name.trim()) return;
        setOpen(false);
        router.post(
            route('list.items.store'),
            { name, qty: qty || null },
            { ...opts, onSuccess: () => (setName(''), setQty('')) },
        );
    };

    const pick = (c) => {
        setName(c.name);
        setOpen(false);
    };

    return (
        <form onSubmit={submit} className="space-y-1">
            <div className="flex gap-2">
                <div className="relative min-w-0 flex-1">
                    <TextInput
                        className="w-full"
                        placeholder="Add an item, e.g. Whole milk"
                        autoComplete="off"
                        autoCorrect="off"
                        spellCheck={false}
                        role="combobox"
                        aria-expanded={open && suggestions.length > 0}
                        aria-controls="item-suggestions"
                        value={name}
                        onChange={(e) => {
                            setName(e.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onBlur={() => setOpen(false)}
                        onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                    />
                    {open && suggestions.length > 0 && (
                        <ul
                            id="item-suggestions"
                            role="listbox"
                            className="absolute inset-x-0 top-full z-10 mt-1 overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black/5"
                        >
                            {suggestions.map((c) => (
                                <li key={c.id} role="option" aria-selected="false">
                                    <button
                                        type="button"
                                        className="block w-full px-3 py-2.5 text-left text-gray-900 hover:bg-gray-50 active:bg-gray-100"
                                        // Keep focus in the field so its blur
                                        // doesn't close the list before the tap.
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => pick(c)}
                                    >
                                        {c.name}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <TextInput
                    className="w-20"
                    type="number"
                    step="any"
                    min="0"
                    placeholder="Qty"
                    value={qty}
                    onChange={(e) => setQty(e.target.value)}
                />
                <PrimaryButton disabled={!name.trim()}>Add</PrimaryButton>
            </div>
            {name.trim() && !known && !(open && suggestions.length > 0) && (
                <p className="text-xs text-gray-500">
                    Not a known item, so it'll be added as a note and won't be
                    assigned a store.{' '}
                    <Link
                        href={route('matching.index', { tab: 'unlinked' })}
                        className="underline"
                    >
                        Set up items
                    </Link>
                </p>
            )}
        </form>
    );
}

function PlannedView({ lines, stores }) {
    const unplanned = lines.filter((l) => !l.assignment);

    return (
        <div className="space-y-6">
            {stores.map((s) => (
                <section key={s.id}>
                    <div className="mb-2 flex items-baseline justify-between">
                        <h3 className="text-lg font-semibold text-gray-900">
                            {s.label}{' '}
                            <span className="text-sm font-normal text-gray-500">
                                {s.fulfillment === 'pickup'
                                    ? 'pickup'
                                    : 'delivery'}
                            </span>
                        </h3>
                        <span className="text-sm text-gray-600">
                            ~{money(s.subtotal)}
                            {s.free_delivery_threshold !== null &&
                                ` / ${money(s.free_delivery_threshold)} free delivery`}
                        </span>
                    </div>
                    {s.warnings.length > 0 && (
                        <ul className="mb-2 space-y-1 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                            {s.warnings.map((w) => (
                                <li key={w}>{w}</li>
                            ))}
                        </ul>
                    )}
                    <Card>
                        {lines
                            .filter((l) => l.assignment?.store_id === s.id)
                            .map((l) => (
                                <LineRow key={l.id} line={l} planned />
                            ))}
                    </Card>
                </section>
            ))}

            {unplanned.length > 0 && (
                <section>
                    <h3 className="mb-2 text-lg font-semibold text-gray-900">
                        No store assigned
                    </h3>
                    <Card>
                        {unplanned.map((l) => (
                            <LineRow key={l.id} line={l} planned />
                        ))}
                    </Card>
                </section>
            )}

            <p className="text-xs text-gray-500">
                Totals are estimates from recent stock checks or the last price
                paid. They're only used to check order minimums.
            </p>
        </div>
    );
}

function LineRow({ line: l, planned = false }) {
    const a = l.assignment;
    const [qty, setQty] = useState(String(l.qty));

    const saveQty = () => {
        const n = Number(qty);
        if (n > 0 && n !== l.qty) {
            router.patch(route('list.items.update', l.id), { qty: n }, opts);
        } else {
            setQty(String(l.qty));
        }
    };

    const moveTo = (storeId, remember = false) =>
        router.post(
            route('list.items.move', l.id),
            { store_id: storeId, remember },
            opts,
        );

    const otherStores = l.move_options.filter((s) => s.id !== a?.store_id);

    return (
        <div className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 items-start gap-2">
                {l.canonical_item_id ? (
                    <button
                        type="button"
                        title={
                            l.is_staple
                                ? 'Staple (click to unmark)'
                                : 'Mark as a staple'
                        }
                        className={
                            'mt-0.5 text-lg leading-none ' +
                            (l.is_staple
                                ? 'text-amber-500'
                                : 'text-gray-300 hover:text-amber-400')
                        }
                        onClick={() =>
                            router.post(
                                route('items.staple', l.canonical_item_id),
                                {},
                                opts,
                            )
                        }
                    >
                        ★
                    </button>
                ) : (
                    <span className="mt-0.5 w-[1em]" />
                )}
                <div className="min-w-0">
                    <div className="font-medium text-gray-900">{l.name}</div>
                    {planned && a && (
                        <div className={'text-xs ' + REASON_STYLE[a.reason]}>
                            {a.note}
                            {a.available === null && ' · stock not checked'}
                            {a.available === true && ' · in stock'}
                            {a.unit_price !== null &&
                                ` · ~${money(a.unit_price)}${l.qty !== 1 ? ' each' : ''}`}
                        </div>
                    )}
                    {planned && l.unplanned_reason && (
                        <div className="text-xs text-gray-500">
                            {l.unplanned_reason}
                        </div>
                    )}
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-2 ps-7 sm:ps-0">
                {planned && otherStores.length > 0 && (
                    <select
                        className="rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        value=""
                        onChange={(e) =>
                            e.target.value && moveTo(Number(e.target.value))
                        }
                    >
                        <option value="">Move to…</option>
                        {otherStores.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                )}
                {planned && a?.reason === 'manual' && l.canonical_item_id && (
                    <button
                        type="button"
                        className="text-xs text-indigo-600 underline"
                        onClick={() => moveTo(a.store_id, true)}
                    >
                        Always buy here
                    </button>
                )}
                <input
                    type="number"
                    step="any"
                    min="0"
                    aria-label="Quantity"
                    className="w-16 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    value={qty}
                    onChange={(e) => setQty(e.target.value)}
                    onBlur={saveQty}
                    onKeyDown={(e) => e.key === 'Enter' && e.target.blur()}
                />
                <button
                    type="button"
                    aria-label={`Remove ${l.name}`}
                    className="px-1 text-gray-400 hover:text-red-600"
                    onClick={() =>
                        router.delete(route('list.items.destroy', l.id), opts)
                    }
                >
                    ✕
                </button>
            </div>
        </div>
    );
}

const ago = (iso) => {
    const mins = Math.round((Date.now() - new Date(iso)) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} min ago`;
    const hours = Math.round(mins / 60);
    if (hours < 48) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    return `${Math.round(hours / 24)} days ago`;
};

function StockCheck({ status }) {
    const { last, requested_at: requestedAt } = status;
    const running = last?.status === 'running';
    const waiting = !!requestedAt;

    // While a check is queued or running, refresh just this panel and the plan.
    useEffect(() => {
        if (!running && !waiting) return;
        const id = setInterval(
            () =>
                router.reload({
                    only: ['stockCheck', 'lines', 'stores'],
                    preserveScroll: true,
                }),
            10000,
        );
        return () => clearInterval(id);
    }, [running, waiting]);

    let tone = 'bg-gray-50 text-gray-700';
    let message;
    if (running) {
        message = `Checking stock… (started ${ago(last.started_at)})`;
    } else if (waiting) {
        const minutes = (Date.now() - new Date(requestedAt)) / 60000;
        message =
            minutes > 3
                ? 'Still waiting for the home computer to pick this up. Is the sidecar running?'
                : 'Stock check requested. It starts within a minute if the home computer is on.';
        if (minutes > 3) tone = 'bg-amber-50 text-amber-800';
    } else if (!last) {
        message =
            "Stock hasn't been checked yet. Plans use usual stores and last-paid prices until it is.";
    } else if (last.status === 'session_expired') {
        tone = 'bg-red-50 text-red-800';
        message =
            'The Instacart sign-in on the home computer has expired. Run “npm run login” in the sidecar folder there to sign in again.';
    } else if (last.status === 'error' || last.status === 'stalled') {
        tone = 'bg-red-50 text-red-800';
        message = `Last stock check failed (${ago(last.started_at)}): ${last.error ?? 'it never finished.'}`;
    } else {
        message = `Stock checked ${ago(last.finished_at)}.`;
    }

    // Never let "Stock checked 1 min ago" imply items added since were covered.
    const unchecked = status.unchecked ?? [];
    if (unchecked.length > 0 && !running && last) {
        message += ` Not checked yet: ${unchecked.join(', ')}.`;
    }

    const showMissing = !running && !waiting && last?.missing?.length > 0;

    return (
        <div className={'space-y-2 rounded-lg p-3 text-sm ' + tone}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span>{message}</span>
                {!running && !waiting && (
                    <SecondaryButton
                        onClick={() =>
                            router.post(route('list.check-stock'), {}, opts)
                        }
                    >
                        Check stock now
                    </SecondaryButton>
                )}
            </div>
            {showMissing && (
                <details className="text-amber-800">
                    <summary className="cursor-pointer">
                        {last.missing.length} product
                        {last.missing.length === 1 ? " wasn't" : "s weren't"}{' '}
                        found on Instacart. The store may have stopped
                        carrying {last.missing.length === 1 ? 'it' : 'them'},
                        so link a replacement on the Item matching page.
                    </summary>
                    <ul className="ms-5 mt-1 list-disc">
                        {last.missing.map((m) => (
                            <li key={m}>{m}</li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
