import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const TABS = [
    { key: 'review', label: 'Needs review' },
    { key: 'unlinked', label: 'Not linked' },
    { key: 'linked', label: 'Linked' },
];

const storeLabel = (slug) =>
    slug
        .replace(/-(corp|meat-grocery|farmers-market)$/, '')
        .split('-')
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');

const post = (name, params, data = {}) =>
    router.post(route(name, params), data, { preserveScroll: true });

export default function Index({ tab, products, counts, items }) {
    const [filter, setFilter] = useState('');
    const [creatingFrom, setCreatingFrom] = useState(null);

    const visible = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return products;
        return products.filter(
            (p) =>
                p.description.toLowerCase().includes(q) ||
                p.item?.name.toLowerCase().includes(q),
        );
    }, [products, filter]);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Item matching
                </h2>
            }
        >
            <Head title="Item matching" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <p className="text-sm text-gray-600">
                        Group the same thing bought at different stores (or as
                        different products) under one shared item, like
                        “Whole milk”. Suggestions are only guesses until you
                        say yes.
                    </p>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <nav className="flex gap-1 rounded-lg bg-gray-200 p-1">
                            {TABS.map((t) => (
                                <Link
                                    key={t.key}
                                    href={route('matching.index', { tab: t.key })}
                                    preserveState={false}
                                    className={
                                        'rounded-md px-3 py-1.5 text-sm font-medium ' +
                                        (tab === t.key
                                            ? 'bg-white text-gray-900 shadow'
                                            : 'text-gray-600 hover:text-gray-900')
                                    }
                                >
                                    {t.label}{' '}
                                    <span className="text-gray-400">
                                        {counts[t.key]}
                                    </span>
                                </Link>
                            ))}
                        </nav>
                        <TextInput
                            className="w-full sm:w-64"
                            placeholder="Filter…"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        />
                    </div>

                    <div className="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm">
                        {visible.length === 0 && (
                            <p className="p-6 text-center text-sm text-gray-500">
                                {tab === 'review'
                                    ? 'Nothing waiting for review.'
                                    : 'Nothing here.'}
                            </p>
                        )}
                        {visible.map((p) => (
                            <ProductRow
                                key={p.id}
                                product={p}
                                items={items}
                                onCreate={() => setCreatingFrom(p)}
                            />
                        ))}
                    </div>
                </div>
            </div>

            <NewItemModal
                product={creatingFrom}
                onClose={() => setCreatingFrom(null)}
            />
        </AuthenticatedLayout>
    );
}

function ProductRow({ product: p, items, onCreate }) {
    return (
        <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <div className="font-medium text-gray-900">{p.description}</div>
                <div className="text-xs text-gray-500">
                    {storeLabel(p.store)}
                    {p.size && ` · ${p.size}`}
                    {` · bought ${p.purchases}×`}
                    {p.last_bought && `, last ${p.last_bought}`}
                    {p.status === 'rejected' && ' · suggestion declined'}
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-2">
                {p.status === 'auto' && (
                    <>
                        <span className="text-sm text-gray-700">
                            Is this <strong>{p.item.name}</strong>?{' '}
                            <span className="text-gray-400">
                                {Math.round(p.score * 100)}%
                            </span>
                        </span>
                        <PrimaryButton
                            onClick={() => post('matching.confirm', p.id)}
                        >
                            Yes
                        </PrimaryButton>
                        <SecondaryButton
                            onClick={() => post('matching.reject', p.id)}
                        >
                            No
                        </SecondaryButton>
                    </>
                )}

                {p.status === 'confirmed' && (
                    <>
                        <span className="text-sm text-gray-700">
                            → <strong>{p.item.name}</strong>
                        </span>
                        <DangerButton
                            onClick={() => post('matching.reject', p.id)}
                        >
                            Unlink
                        </DangerButton>
                    </>
                )}

                {p.status !== 'confirmed' && (
                    <>
                        <ItemPicker
                            items={items}
                            excludeId={p.item?.id}
                            onPick={(id) =>
                                post('matching.confirm', p.id, {
                                    canonical_item_id: id,
                                })
                            }
                        />
                        <SecondaryButton onClick={onCreate}>
                            New item
                        </SecondaryButton>
                    </>
                )}
            </div>
        </div>
    );
}

function ItemPicker({ items, excludeId, onPick }) {
    const options = items.filter((i) => i.id !== excludeId);
    if (options.length === 0) return null;

    return (
        <select
            className="rounded-md border-gray-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            value=""
            onChange={(e) => e.target.value && onPick(Number(e.target.value))}
        >
            <option value="">
                {excludeId ? 'No, it’s…' : 'Link to…'}
            </option>
            {options.map((i) => (
                <option key={i.id} value={i.id}>
                    {i.name} ({i.linked})
                </option>
            ))}
        </select>
    );
}

function NewItemModal({ product, onClose }) {
    const [name, setName] = useState('');
    const [similar, setSimilar] = useState([]);
    const [checked, setChecked] = useState({});
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState({});

    useEffect(() => {
        if (!product) return;
        let cancelled = false;
        setName('');
        setSimilar([]);
        setErrors({});
        setLoading(true);
        fetch(route('matching.similar', product.id), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data) => {
                if (cancelled) return;
                setName(data.name);
                setSimilar(data.similar);
                setChecked(
                    Object.fromEntries(
                        data.similar.map((s) => [s.id, s.preselected]),
                    ),
                );
            })
            .finally(() => !cancelled && setLoading(false));
        return () => {
            cancelled = true;
        };
    }, [product]);

    const close = onClose;

    const submit = (e) => {
        e.preventDefault();
        const ids = [
            product.id,
            ...similar.filter((s) => checked[s.id]).map((s) => s.id),
        ];
        router.post(
            route('matching.items.store'),
            { name, store_product_ids: ids },
            {
                preserveScroll: true,
                onSuccess: close,
                onError: setErrors,
            },
        );
    };

    return (
        <Modal show={product !== null} onClose={close} maxWidth="lg">
            {product && (
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h3 className="text-lg font-medium text-gray-900">
                        New item
                    </h3>
                    <p className="text-sm text-gray-600">
                        From: {product.description} (
                        {storeLabel(product.store)})
                    </p>

                    <div>
                        <InputLabel htmlFor="name" value="Item name" />
                        <TextInput
                            id="name"
                            className="mt-1 block w-full"
                            value={name}
                            isFocused
                            onChange={(e) => setName(e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            Keep it general — “Greek yogurt”, not the brand
                            and size.
                        </p>
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    {loading && (
                        <p className="text-sm text-gray-500">
                            Looking for similar products…
                        </p>
                    )}
                    {similar.length > 0 && (
                        <fieldset>
                            <legend className="text-sm font-medium text-gray-700">
                                Also add these? Ticked ones look like the same
                                thing; check the rest.
                            </legend>
                            <div className="mt-2 max-h-64 space-y-1 overflow-y-auto">
                                {similar.map((s) => (
                                    <label
                                        key={s.id}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            className="mt-0.5 rounded border-gray-300 text-indigo-600"
                                            checked={!!checked[s.id]}
                                            onChange={(e) =>
                                                setChecked({
                                                    ...checked,
                                                    [s.id]: e.target.checked,
                                                })
                                            }
                                        />
                                        <span>
                                            {s.description}{' '}
                                            <span className="text-gray-400">
                                                ({storeLabel(s.store)})
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    <div className="flex justify-end gap-2">
                        <SecondaryButton type="button" onClick={close}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={!name.trim() || loading}>
                            Create item
                        </PrimaryButton>
                    </div>
                </form>
            )}
        </Modal>
    );
}
