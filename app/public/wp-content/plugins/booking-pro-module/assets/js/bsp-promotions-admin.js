(function () {
    const globals = window || {};
    const wp = globals.wp || {};
    const rootEl = document.getElementById('bsp-sales-admin-root');
    const screenWrap = document.querySelector('.bsp-sales-wrap[data-screen="promotions"]');

    if (!rootEl || !screenWrap || !wp.element || typeof wp.element.createElement !== 'function') {
        return;
    }

    const {
        createElement: h,
        useState,
        useEffect,
        Fragment,
        createRoot,
        render,
    } = wp.element;

    const config = globals.BSP_PROMOTIONS_ADMIN || {};

    const sessionStorageKey = 'bspPromotionsSessionId';
    const generateSessionId = () => {
        if (globals.crypto && typeof globals.crypto.randomUUID === 'function') {
            return 'promo-' + globals.crypto.randomUUID();
        }

        return 'promo-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    };

    const resolveSessionId = () => {
        try {
            if (globals.localStorage) {
                const stored = globals.localStorage.getItem(sessionStorageKey);
                if (stored) {
                    return stored;
                }
                const generated = generateSessionId();
                globals.localStorage.setItem(sessionStorageKey, generated);
                return generated;
            }
        } catch (storageError) {
            // ignore storage errors and fall back to memory
        }
        if (!globals.__BSP_PROMO_SESSION__) {
            globals.__BSP_PROMO_SESSION__ = generateSessionId();
        }
        return globals.__BSP_PROMO_SESSION__;
    };

    const funnelSessionId = resolveSessionId();

    const normaliseBase = (base) => {
        if (!base || typeof base !== 'string') {
            return '';
        }

        return base.replace(/\/+$/, '');
    };

    const restBaseUrl = normaliseBase(config.restBase) || '/wp-json/sbdp/v1';
    const nonce = config.nonce || '';
    const canMutate = Boolean((config.capabilities && config.capabilities.managePromotions) && nonce);

    const buildUrl = (path) => {
        const trimmedPath = String(path || '').replace(/^\/+/, '');
        return `${restBaseUrl}/${trimmedPath}`;
    };

    const restRequest = async (path, options) => {
        const defaultOptions = options || {};
        const method = (defaultOptions.method || 'GET').toUpperCase();
        const headers = { Accept: 'application/json', ...(defaultOptions.headers || {}) };
        if (nonce) {
            headers['X-SBDP-Promotions-Nonce'] = nonce;
        }

        if (funnelSessionId) {
            headers['X-SBDP-Funnel-Session'] = funnelSessionId;
        }
        let body = defaultOptions.body;
        if (body && typeof body !== 'string') {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }

        const response = await fetch(buildUrl(path), {
            credentials: 'same-origin',
            ...defaultOptions,
            method,
            headers,
            body,
        });

        const text = await response.text();
        let payload;
        if (text) {
            try {
                payload = JSON.parse(text);
            } catch (error) {
                payload = null;
            }
        }

        if (!response.ok) {
            const message = (payload && payload.message) || `Request failed (${response.status})`;
            const error = new Error(message);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload;
    };

    const statuses = ['draft', 'scheduled', 'active', 'archived'];
    const types = ['percentage', 'fixed', 'bundle', 'loyalty_boost'];
    const initialFormState = {
        code: '',
        name: '',
        type: types[0],
        status: statuses[0],
    };

    const PromotionActions = ({ promotion, onTransition, onEdit, saving }) => {
        if (!promotion || !promotion.id) {
            return null;
        }

        const elements = [];
        const disabled = saving || !canMutate;

        if (canMutate) {
            elements.push(
                h(
                    'button',
                    {
                        type: 'button',
                        className: 'button button-secondary',
                        disabled,
                        onClick: () => onEdit(promotion),
                        key: 'edit',
                    },
                    'Edit'
                )
            );
        }

        if (promotion.status !== 'active' && promotion.status !== 'archived') {
            elements.push(
                h(
                    'button',
                    {
                        type: 'button',
                        className: 'button button-secondary',
                        disabled,
                        onClick: () => onTransition(promotion.id, 'activate'),
                        key: 'activate',
                    },
                    'Activate'
                )
            );
        }

        if (promotion.status !== 'archived') {
            elements.push(
                h(
                    'button',
                    {
                        type: 'button',
                        className: 'button',
                        disabled,
                        onClick: () => onTransition(promotion.id, 'archive'),
                        key: 'archive',
                    },
                    'Archive'
                )
            );
        }

        if (!elements.length) {
            return null;
        }

        return h('div', { className: 'bsp-promotions-admin__row-actions' }, elements);
    };

    const PromotionsApp = () => {
        const [promotions, setPromotions] = useState([]);
        const [loading, setLoading] = useState(true);
        const [error, setError] = useState('');
        const [message, setMessage] = useState('');
        const [saving, setSaving] = useState(false);
        const [editingId, setEditingId] = useState(null);
        const [formState, setFormState] = useState(initialFormState);

        const refresh = async () => {
            setLoading(true);
            setError('');
            try {
                const payload = await restRequest('promotions');
                setPromotions(Array.isArray(payload && payload.promotions) ? payload.promotions : []);
            } catch (fetchError) {
                setError(fetchError.message || 'Unable to load promotions.');
            } finally {
                setLoading(false);
            }
        };

        useEffect(() => {
            refresh();
        }, []);

        const updateFormField = (field) => (event) => {
            setFormState((prev) => ({
                ...prev,
                [field]: event && event.target ? event.target.value : '',
            }));
        };

        const resetForm = () => {
            setEditingId(null);
            setFormState(initialFormState);
        };

        const handleSubmit = async (event) => {
            event.preventDefault();

            if (!canMutate) {
                setError('You do not have permission to modify promotions.');
                return;
            }

            const code = String(formState.code || '').trim();
            const name = String(formState.name || '').trim();

            if (!code || !name) {
                setError('Code and Name are required.');
                return;
            }

            setSaving(true);
            setError('');
            setMessage('');

            try {
                const payload = {
                    code,
                    name,
                    type: formState.type,
                    status: formState.status,
                };

                if (editingId) {
                    await restRequest(`promotions/${editingId}`, {
                        method: 'PATCH',
                        body: payload,
                    });
                    setMessage('Promotion updated.');
                } else {
                    await restRequest('promotions', {
                        method: 'POST',
                        body: payload,
                    });
                    setMessage('Promotion created.');
                }

                resetForm();
                await refresh();
            } catch (submitError) {
                setError(submitError.message || 'Unable to save promotion.');
            } finally {
                setSaving(false);
            }
        };

        const handleTransition = async (id, action) => {
            if (!canMutate || !id) {
                return;
            }

            setSaving(true);
            setError('');
            setMessage('');

            try {
                await restRequest(`promotions/${id}/${action}`, { method: 'POST' });
                setMessage(`Promotion ${action === 'archive' ? 'archived' : 'activated'}.`);
                await refresh();
            } catch (transitionError) {
                setError(transitionError.message || 'Unable to update promotion.');
            } finally {
                setSaving(false);
            }
        };

        const handleEdit = (promotion) => {
            if (!promotion || !promotion.id) {
                return;
            }

            setEditingId(promotion.id);
            setFormState({
                code: promotion.code || '',
                name: promotion.name || '',
                type: types.includes(promotion.type) ? promotion.type : types[0],
                status: statuses.includes(promotion.status) ? promotion.status : statuses[0],
            });

            if (typeof rootEl.scrollIntoView === 'function') {
                rootEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        };

        const renderList = () => {
            if (loading) {
                return h('p', { className: 'bsp-promotions-admin__state' }, 'Loading promotions...');
            }

            if (error && promotions.length === 0) {
                return h('p', { className: 'bsp-promotions-admin__state bsp-promotions-admin__state--error' }, error);
            }

            if (!promotions.length) {
                return h('p', { className: 'bsp-promotions-admin__state' }, 'No promotions found yet. Create the first offer below.');
            }

            return h(
                'table',
                { className: 'widefat striped bsp-promotions-admin__table' },
                [
                    h('thead', {},
                        h('tr', {}, [
                            h('th', { key: 'code' }, 'Code'),
                            h('th', { key: 'name' }, 'Name'),
                            h('th', { key: 'type' }, 'Type'),
                            h('th', { key: 'status' }, 'Status'),
                            h('th', { key: 'window' }, 'Schedule'),
                            h('th', { key: 'actions' }, 'Actions'),
                        ])
                    ),
                    h('tbody', {}, promotions.map((promotion) =>
                        h('tr', { key: promotion.id, className: promotion.id === editingId ? 'bsp-promotions-admin__row--editing' : '' }, [
                            h('td', { key: 'code' }, promotion.code || '-'),
                            h('td', { key: 'name' }, promotion.name || '-'),
                            h('td', { key: 'type' }, promotion.type || '-'),
                            h('td', { key: 'status' }, promotion.status || '-'),
                            h('td', { key: 'window' },
                                promotion.starts_at || promotion.ends_at
                                    ? `${promotion.starts_at || '-'} -> ${promotion.ends_at || '-'}`
                                    : '-'
                            ),
                            h('td', { key: 'actions' },
                                h(PromotionActions, {
                                    promotion,
                                    onTransition: handleTransition,
                                    onEdit: handleEdit,
                                    saving,
                                })
                            ),
                        ])
                    )),
                ]
            );
        };

        return h(
            Fragment,
            null,
            [
                h('div', { className: 'bsp-promotions-admin__messages' }, [
                    message && h('div', { key: 'msg', className: 'notice notice-success inline' }, h('p', {}, message)),
                    error && !loading && promotions.length > 0 && h('div', { key: 'err', className: 'notice notice-error inline' }, h('p', {}, error)),
                    !canMutate && h('div', { key: 'warn', className: 'notice notice-warning inline' }, h('p', {}, 'You can view promotions but lack permission to change them.')),
                ].filter(Boolean)),
                h('div', { className: 'bsp-promotions-admin__list' }, renderList()),
                h('div', { className: 'bsp-promotions-admin__form' }, [
                    h('h2', { key: 'title' }, editingId ? 'Edit Promotion' : 'Create Promotion'),
                    h('form', { key: 'form', onSubmit: handleSubmit }, [
                        h('div', { className: 'bsp-field-row' }, [
                            h('label', { htmlFor: 'bsp-promotions-code' }, 'Code'),
                            h('input', {
                                id: 'bsp-promotions-code',
                                type: 'text',
                                required: true,
                                value: formState.code,
                                onChange: updateFormField('code'),
                                disabled: saving || !canMutate,
                            }),
                        ]),
                        h('div', { className: 'bsp-field-row' }, [
                            h('label', { htmlFor: 'bsp-promotions-name' }, 'Name'),
                            h('input', {
                                id: 'bsp-promotions-name',
                                type: 'text',
                                required: true,
                                value: formState.name,
                                onChange: updateFormField('name'),
                                disabled: saving || !canMutate,
                            }),
                        ]),
                        h('div', { className: 'bsp-field-row' }, [
                            h('label', { htmlFor: 'bsp-promotions-type' }, 'Type'),
                            h('select', {
                                id: 'bsp-promotions-type',
                                value: formState.type,
                                onChange: updateFormField('type'),
                                disabled: saving || !canMutate,
                            }, types.map((option) => h('option', { key: option, value: option }, option))),
                        ]),
                        h('div', { className: 'bsp-field-row' }, [
                            h('label', { htmlFor: 'bsp-promotions-status' }, editingId ? 'Status' : 'Initial Status'),
                            h('select', {
                                id: 'bsp-promotions-status',
                                value: formState.status,
                                onChange: updateFormField('status'),
                                disabled: saving || !canMutate,
                            }, statuses.map((option) => h('option', { key: option, value: option }, option))),
                        ]),
                        h('p', { className: 'submit' }, [
                            h('button', {
                                type: 'submit',
                                className: 'button button-primary',
                                disabled: saving || !canMutate,
                            }, saving ? 'Saving...' : editingId ? 'Update Promotion' : 'Create Promotion'),
                            editingId && h('button', {
                                type: 'button',
                                className: 'button button-secondary',
                                onClick: resetForm,
                                disabled: saving,
                                style: { marginLeft: '0.5rem' },
                            }, 'Cancel'),
                        ].filter(Boolean)),
                    ]),
                ]),
            ]
        );
    };

    const rootFactory = typeof createRoot === 'function' ? createRoot : null;
    if (rootFactory) {
        rootFactory(rootEl).render(h(PromotionsApp));
    } else if (typeof render === 'function') {
        render(h(PromotionsApp), rootEl);
    }
})();



