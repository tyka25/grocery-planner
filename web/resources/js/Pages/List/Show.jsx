import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const opts = { preserveScroll: true };

const REASON_STYLE = {
    preferred: 'text-gray-500',
    manual: 'text-indigo-600',
    fallback: 'text-amber-700',
    minimum_repair: 'text-amber-700',
};

const money = (n) => `$${n.toFixed(2)}`;

export default function Show({ list, lines, stores, catalog }) {
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

function AddItem({ catalog }) {
    const [name, setName] = useState('');
    const [qty, setQty] = useState('');
    const known = catalog.some(
        (c) => c.name.toLowerCase() === name.trim().toLowerCase(),
    );

    const submit = (e) => {
        e.preventDefault();
        if (!name.trim()) return;
        router.post(
            route('list.items.store'),
            { name, qty: qty || null },
            { ...opts, onSuccess: () => (setName(''), setQty('')) },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-1">
            <div className="flex gap-2">
                <TextInput
                    className="min-w-0 flex-1"
                    placeholder="Add an item, e.g. Whole milk"
                    list="catalog"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
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
            <datalist id="catalog">
                {catalog.map((c) => (
                    <option key={c.id} value={c.name} />
                ))}
            </datalist>
            {name.trim() && !known && (
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
