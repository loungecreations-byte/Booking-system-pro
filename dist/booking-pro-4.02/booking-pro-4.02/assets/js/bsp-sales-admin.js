(function () {
    const globals = window || {};
    const wp = globals.wp || {};
    const rootEl = document.getElementById('bsp-sales-admin-root');
    const wrapEl = document.querySelector('.bsp-sales-wrap');

    if (!rootEl || !wrapEl) {
        return;
    }

    if (!wp.element || typeof wp.element.createElement !== 'function') {
        if ((wrapEl.getAttribute('data-screen') || '') === 'vendors') {
            console.warn('BSP Sales admin requires wp.element for the vendors screen.');
        }
        return;
    }

    const screen = wrapEl.getAttribute('data-screen') || '';
    if (screen !== 'vendors') {
        console.info('BSP Sales admin UI booted');
        return;
    }

    const config = globals.BSP_SALES_ADMIN || {};
    const {
        createElement: h,
        Fragment,
        useEffect,
        useMemo,
        useRef,
        useState,
        createRoot,
        render,
    } = wp.element;
    const __ = wp.i18n && typeof wp.i18n.__ === 'function' ? wp.i18n.__ : (text) => text;
    const _n = wp.i18n && typeof wp.i18n._n === 'function' ? wp.i18n._n : null;
    const sprintf = wp.i18n && typeof wp.i18n.sprintf === 'function' ? wp.i18n.sprintf : null;

    const vendorStatuses = Array.isArray(config.vendorStatuses) && config.vendorStatuses.length
        ? config.vendorStatuses
        : ['pending', 'active', 'suspended', 'archived'];
    const tourStatuses = Array.isArray(config.tourStatuses) && config.tourStatuses.length
        ? config.tourStatuses
        : ['upcoming', 'completed', 'cancelled'];
    const channelOptions = Array.isArray(config.channelOptions) && config.channelOptions.length
        ? config.channelOptions.filter((option) => option && option.slug).map((option) => ({
            slug: String(option.slug || '').toLowerCase(),
            name: option.name || String(option.slug || ''),
        }))
        : [];
    const currencyOptions = Array.isArray(config.currencyOptions) && config.currencyOptions.length
        ? config.currencyOptions.filter((item) => typeof item === "string" && item)
        : ['USD', 'EUR', 'GBP'];
    const defaultCurrency = typeof config.defaultCurrency === "string" && config.defaultCurrency
        ? config.defaultCurrency
        : (currencyOptions[0] || 'USD');
    const canMutate = !!(config.capabilities && config.capabilities.manageVendors);
    const restBase = (config.restBase || '').replace(/\/$/, '');
    const buildUrl = (path) => {
        const trimmed = String(path || '').replace(/^\/+/, '');
        return restBase ? restBase + '/' + trimmed : trimmed;
    };

    const apiRequest = async (path, options = {}) => {
        const opts = Object.assign({}, options);
        opts.headers = Object.assign({}, opts.headers || {});
        if (config.nonce && !opts.headers['X-WP-Nonce']) {
            opts.headers['X-WP-Nonce'] = config.nonce;
        }
        if (opts.body && !opts.headers['Content-Type']) {
            opts.headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(buildUrl(path), opts);
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }

        if (!response.ok) {
            const message = data && data.message ? data.message : response.statusText || __('Request failed.', 'sbdp');
            const error = new Error(message);
            error.data = data;
            throw error;
        }

        return data;
    };

    const parseCommaList = (value) => String(value || '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

    const parseIds = (value) => parseCommaList(value)
        .map((item) => parseInt(item, 10))
        .filter((item) => Number.isInteger(item) && item > 0);

    const ensureDraftEntry = (collection, key, defaults) => {
        if (collection[key]) {
            return collection[key];
        }
        return defaults;
    };

    const createInitialForm = () => ({
        name: '',
        slug: '',
        status: vendorStatuses[0] || 'pending',
        channels: '',
        commission: '',
        products: '',
        resources: '',
        contactName: '',
        contactEmail: '',
        contactPhone: '',
        webhookUrl: '',
        timezone: '',
        maxCapacity: '',
        notes: '',
        pricingCurrency: defaultCurrency,
        pricingBaseRate: '',
        pricingMarkupType: 'none',
        pricingMarkupValue: '',
    });

    const mapVendorToForm = (vendor) => ({
        name: vendor.name || '',
        slug: vendor.slug || '',
        status: vendor.status || vendorStatuses[0] || 'pending',
        channels: Array.isArray(vendor.channels) ? vendor.channels.join(', ') : '',
        commission: vendor.commission_rate != null ? String(vendor.commission_rate) : '',
        products: Array.isArray(vendor.product_ids) ? vendor.product_ids.join(', ') : '',
        resources: Array.isArray(vendor.resource_ids) ? vendor.resource_ids.join(', ') : '',
        contactName: vendor.contact_name || '',
        contactEmail: vendor.contact_email || '',
        contactPhone: vendor.contact_phone || '',
        webhookUrl: vendor.webhook_url || '',
        timezone: vendor.timezone || '',
        maxCapacity: vendor.max_capacity != null ? String(vendor.max_capacity) : '',
        notes: vendor.notes || '',
        pricingCurrency: vendor.pricing_currency || defaultCurrency,
        pricingBaseRate: vendor.pricing_base_rate != null ? String(vendor.pricing_base_rate) : '',
        pricingMarkupType: vendor.pricing_markup_type || 'none',
        pricingMarkupValue: vendor.pricing_markup_value != null ? String(vendor.pricing_markup_value) : '',
    });

    const getNextTourStatus = (currentStatus) => {
        if (!tourStatuses.length) {
            return currentStatus === 'completed' ? 'upcoming' : 'completed';
        }
        const index = tourStatuses.indexOf(currentStatus);
        if (index === -1) {
            return tourStatuses[0];
        }
        const nextIndex = (index + 1) % tourStatuses.length;
        return tourStatuses[nextIndex];
    };
    const summarisePricing = (vendor) => {
        const currency = vendor.pricing_currency ? String(vendor.pricing_currency).toUpperCase() : '';
        const baseRate = vendor.pricing_base_rate != null ? Number(vendor.pricing_base_rate) : null;
        const markupType = vendor.pricing_markup_type || null;
        const markupValue = vendor.pricing_markup_value != null ? Number(vendor.pricing_markup_value) : null;
        const parts = [];

        if (baseRate !== null && !Number.isNaN(baseRate)) {
            parts.push((currency ? currency + ' ' : '') + baseRate.toFixed(2));
        } else if (currency) {
            parts.push(currency);
        }

        if (markupType && markupValue !== null && !Number.isNaN(markupValue)) {
            if (markupType === 'percent') {
                parts.push('+' + markupValue.toFixed(2) + '%');
            } else {
                parts.push('+' + (currency ? currency + ' ' : '') + markupValue.toFixed(2));
            }
        }

        return parts.length ? parts.join(' ') : __('Not set', 'sbdp');
    };
    const VendorsApp = () => {
        const [vendors, setVendors] = useState([]);
        const [vendorsLoading, setVendorsLoading] = useState(true);
        const [vendorLoadError, setVendorLoadError] = useState('');
        const [listError, setListError] = useState('');
        const [listNotice, setListNotice] = useState('');
        const [formState, setFormState] = useState(createInitialForm());
        const [formError, setFormError] = useState('');
        const [formNotice, setFormNotice] = useState('');
        const [isSavingVendor, setIsSavingVendor] = useState(false);
        const [activeVendorId, setActiveVendorId] = useState(null);
        const [activeVendor, setActiveVendor] = useState(null);
        const [listBusyId, setListBusyId] = useState(null);
        const [schedule, setSchedule] = useState({ vendor_id: 0, resources: [] });
        const [scheduleLoading, setScheduleLoading] = useState(false);
        const [scheduleError, setScheduleError] = useState('');
        const [scheduleNotice, setScheduleNotice] = useState('');
        const [scheduleBusyId, setScheduleBusyId] = useState(0);
        const [draftAvailability, setDraftAvailability] = useState({});
        const [draftTours, setDraftTours] = useState({});
        const [vendorSearch, setVendorSearch] = useState('');
        const [vendorStatusFilter, setVendorStatusFilter] = useState('all');
        const [resourceSearch, setResourceSearch] = useState('');
        const [scheduleTab, setScheduleTab] = useState('all');
        const scheduleRequestRef = useRef(0);

        const loadVendors = async () => {
            setVendorsLoading(true);
            setVendorLoadError('');
            try {
                const response = await apiRequest('vendors?per_page=100&with_products=1', { method: 'GET' });
                const list = Array.isArray(response && response.vendors) ? response.vendors.slice() : [];
                list.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                setVendors(list);
                setListNotice('');
                setListError('');
                if (activeVendorId) {
                    const match = list.find((item) => item.id === activeVendorId);
                    if (match) {
                        setActiveVendor(match);
                        setFormState(mapVendorToForm(match));
                    }
                }
            } catch (err) {
                setVendorLoadError(err && err.message ? err.message : __('Unable to load vendors.', 'sbdp'));
            } finally {
                setVendorsLoading(false);
            }
        };

        useEffect(() => {
            loadVendors();
        }, []);

        useEffect(() => {
            let isActive = true;

            if (!activeVendorId) {
                setActiveVendor(null);
                setFormState(createInitialForm());
                setFormNotice('');
                setFormError('');
                return () => {
                    isActive = false;
                };
            }

            const vendorFromList = vendors.find((item) => item.id === activeVendorId);
            if (vendorFromList) {
                setActiveVendor(vendorFromList);
                setFormState(mapVendorToForm(vendorFromList));
            }

            const loadDetails = async () => {
                try {
                    const response = await apiRequest(`vendors/${activeVendorId}`, { method: 'GET' });
                    const vendorData = response && response.vendor ? response.vendor : null;
                    if (!isActive || !vendorData) {
                        return;
                    }
                    setActiveVendor(vendorData);
                    setFormState(mapVendorToForm(vendorData));
                } catch (err) {
                    if (isActive) {
                        setFormError(err && err.message ? err.message : __('Unable to load vendor details.', 'sbdp'));
                    }
                }
            };

            loadDetails();

            return () => {
                isActive = false;
            };
        }, [activeVendorId, vendors]);
        const fetchSchedule = async ({ silent = false, resetDrafts = false } = {}) => {
            if (!activeVendorId) {
                return;
            }

            const requestId = Date.now();
            scheduleRequestRef.current = requestId;

            if (!silent) {
                setScheduleLoading(true);
                setScheduleNotice('');
            }
            setScheduleError('');
            if (resetDrafts) {
                setDraftAvailability({});
                setDraftTours({});
            }

            try {
                const response = await apiRequest(`vendors/${activeVendorId}/schedule`, { method: 'GET' });
                const resources = response && Array.isArray(response.resources) ? response.resources : [];
                if (scheduleRequestRef.current !== requestId) {
                    return;
                }
                setSchedule({ vendor_id: activeVendorId, resources });
            } catch (err) {
                if (scheduleRequestRef.current !== requestId) {
                    return;
                }
                if (!silent) {
                    setSchedule({ vendor_id: activeVendorId, resources: [] });
                    setScheduleError(err && err.message ? err.message : __('Unable to load availability.', 'sbdp'));
                }
            } finally {
                if (scheduleRequestRef.current === requestId && !silent) {
                    setScheduleLoading(false);
                }
            }
        };

        useEffect(() => {
            if (!activeVendorId) {
                scheduleRequestRef.current = 0;
                setSchedule({ vendor_id: 0, resources: [] });
                setScheduleLoading(false);
                setScheduleError('');
                setScheduleNotice('');
                setDraftAvailability({});
                setDraftTours({});
                setResourceSearch('');
                return;
            }

            fetchSchedule({ silent: false, resetDrafts: true });
        }, [activeVendorId]);

        const currentResources = Array.isArray(schedule && schedule.resources) ? schedule.resources : [];

        const filteredVendors = useMemo(() => {
            const search = vendorSearch.trim().toLowerCase();
            const statusFilter = vendorStatusFilter;
            return vendors.filter((vendor) => {
                const matchesSearch = !search
                    || (vendor.name || '').toLowerCase().includes(search)
                    || (vendor.slug || '').toLowerCase().includes(search);
                const matchesStatus = statusFilter === 'all'
                    || (vendor.status || '') === statusFilter;
                return matchesSearch && matchesStatus;
            });
        }, [vendors, vendorSearch, vendorStatusFilter]);

        const filteredResources = useMemo(() => {
            const query = resourceSearch.trim().toLowerCase();
            if (!query) {
                return currentResources;
            }
            return currentResources.filter((resource) => (resource.title || '').toLowerCase().includes(query));
        }, [currentResources, resourceSearch]);

        const updateDraftAvailability = (resourceId, field, value) => {
            setDraftAvailability((current) => {
                const next = Object.assign({}, current);
                const defaults = ensureDraftEntry(next, resourceId, { start: '', end: '', notes: '' });
                next[resourceId] = Object.assign({}, defaults, { [field]: value });
                return next;
            });
        };

        const updateDraftTour = (resourceId, field, value) => {
            setDraftTours((current) => {
                const next = Object.assign({}, current);
                const defaults = ensureDraftEntry(next, resourceId, {
                    date: '',
                    status: tourStatuses[0] || 'upcoming',
                    notes: '',
                });
                next[resourceId] = Object.assign({}, defaults, { [field]: value });
                return next;
            });
        };
        const handleCreateVendorClick = () => {
            setActiveVendorId(null);
            setActiveVendor(null);
            setFormState(createInitialForm());
            setFormError('');
            setFormNotice('');
            setListNotice('');
            setListError('');
            setScheduleError('');
            setScheduleNotice('');
            setDraftAvailability({});
            setDraftTours({});
            setSchedule({ vendor_id: 0, resources: [] });
            setScheduleLoading(false);
            setResourceSearch('');
            setScheduleTab('all');
        };

        const handleEditVendor = (vendor) => {
            setActiveVendorId(vendor.id);
            setFormNotice('');
            setFormError('');
            setListError('');
            setScheduleError('');
            setScheduleNotice('');
        };

        const handleFormFieldChange = (field) => (event) => {
            const value = event.target.value;
            setFormState((current) => Object.assign({}, current, { [field]: value }));
        };

        const handleSubmitForm = async (event) => {
            event.preventDefault();
            if (!canMutate || isSavingVendor) {
                return;
            }

            setIsSavingVendor(true);
            setFormError('');
            setFormNotice('');
            setListError('');
            setListNotice('');

            const trimmedCommission = (formState.commission || '').trim();
            const trimmedCapacity = (formState.maxCapacity || '').trim();
            const isEditing = !!activeVendorId;

            if (trimmedCommission && Number.isNaN(parseFloat(trimmedCommission))) {
                setFormError(__('Commission must be a number.', 'sbdp'));
                setIsSavingVendor(false);
                return;
            }

            if (trimmedCapacity && Number.isNaN(parseInt(trimmedCapacity, 10))) {
                setFormError(__('Max capacity must be a number.', 'sbdp'));
                setIsSavingVendor(false);
                return;
            }

            const payload = {
                name: formState.name,
                slug: formState.slug || undefined,
                status: formState.status,
                channels: parseCommaList(formState.channels),
                commission_rate: trimmedCommission === '' ? null : parseFloat(trimmedCommission),
                contact_name: formState.contactName || null,
                contact_email: formState.contactEmail || null,
                contact_phone: formState.contactPhone || null,
                webhook_url: formState.webhookUrl || null,
                resource_ids: parseIds(formState.resources),
                timezone: formState.timezone || null,
                notes: formState.notes || null,
            };

            if (trimmedCapacity !== '') {
                payload.max_capacity = parseInt(trimmedCapacity, 10);
            }

            const productIds = parseIds(formState.products);
            if (productIds.length) {
                payload.product_ids = productIds;
            }

            try {
                const path = isEditing ? `vendors/${activeVendorId}` : 'vendors';
                const method = isEditing ? 'PATCH' : 'POST';
                const response = await apiRequest(path, {
                    method,
                    body: JSON.stringify(payload),
                });
                const vendor = response && response.vendor ? response.vendor : null;

                if (vendor) {
                    setVendors((current) => {
                        const next = current.filter((item) => item.id !== vendor.id);
                        next.push(vendor);
                        next.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                        return next;
                    });
                    setActiveVendorId(vendor.id);
                    setActiveVendor(vendor);
                    setFormState(mapVendorToForm(vendor));
                    setFormNotice(isEditing ? __('Vendor updated.', 'sbdp') : __('Vendor created.', 'sbdp'));
                } else {
                    await loadVendors();
                    setFormNotice(__('Vendor saved.', 'sbdp'));
                }
            } catch (err) {
                setFormError(err && err.message ? err.message : __('Unable to save vendor.', 'sbdp'));
            } finally {
                setIsSavingVendor(false);
            }
        };
        const changeVendorStatus = async (vendor, status) => {
            if (!canMutate) {
                return;
            }
            setListBusyId(vendor.id);
            setListError('');
            setListNotice('');
            try {
                const response = await apiRequest(`vendors/${vendor.id}/status`, {
                    method: 'POST',
                    body: JSON.stringify({ status }),
                });
                const updated = response && response.vendor ? response.vendor : null;
                setVendors((current) => current.map((item) => (item.id === vendor.id ? (updated || Object.assign({}, item, { status })) : item)));
                if (activeVendorId === vendor.id && updated) {
                    setActiveVendor(updated);
                    setFormState(mapVendorToForm(updated));
                }
                setListNotice(__('Status updated.', 'sbdp'));
            } catch (err) {
                setListError(err && err.message ? err.message : __('Unable to update status.', 'sbdp'));
            } finally {
                setListBusyId(null);
            }
        };

        const syncVendorProducts = async (vendor) => {
            if (!canMutate) {
                return;
            }
            setListBusyId(vendor.id);
            setListError('');
            setListNotice('');
            try {
                const response = await apiRequest(`vendors/${vendor.id}/products`, {
                    method: 'POST',
                    body: JSON.stringify({
                        product_ids: Array.isArray(vendor.product_ids) ? vendor.product_ids : [],
                    }),
                });
                const updated = response && response.vendor ? response.vendor : null;
                if (updated) {
                    setVendors((current) => current.map((item) => (item.id === vendor.id ? updated : item)));
                    if (activeVendorId === vendor.id) {
                        setActiveVendor(updated);
                        setFormState(mapVendorToForm(updated));
                    }
                }
                setListNotice(__('Products refreshed.', 'sbdp'));
            } catch (err) {
                setListError(err && err.message ? err.message : __('Unable to sync products.', 'sbdp'));
            } finally {
                setListBusyId(null);
            }
        };

        const handleScheduleReload = () => {
            if (!activeVendorId) {
                return;
            }
            fetchSchedule({ silent: false, resetDrafts: true });
        };

        const saveResourceSchedule = async (resourceId, availability, tours, message) => {
            if (!activeVendorId) {
                return;
            }

            setScheduleBusyId(resourceId);
            setScheduleError('');
            setScheduleNotice('');
            try {
                const response = await apiRequest(`vendors/${activeVendorId}/schedule`, {
                    method: 'POST',
                    body: JSON.stringify({
                        resource_id: resourceId,
                        availability,
                        tours,
                    }),
                });
                const resources = response && Array.isArray(response.resources) ? response.resources : [];
                setSchedule({ vendor_id: activeVendorId, resources });
                setScheduleNotice(message || __('Schedule updated.', 'sbdp'));
            } catch (err) {
                setScheduleError(err && err.message ? err.message : __('Unable to update schedule.', 'sbdp'));
            } finally {
                setScheduleBusyId(0);
            }
        };

        const handleAddAvailabilitySlot = async (resourceId) => {
            if (!canMutate) {
                return;
            }

            const draft = ensureDraftEntry(draftAvailability, resourceId, { start: '', end: '', notes: '' });
            const start = (draft.start || '').trim();
            const end = (draft.end || '').trim();

            setScheduleError('');
            setScheduleNotice('');

            if (!start || !end) {
                setScheduleError(__('Please provide both start and end times.', 'sbdp'));
                return;
            }

            if (start >= end) {
                setScheduleError(__('End time must be after the start time.', 'sbdp'));
                return;
            }

            const notes = (draft.notes || '').trim();
            const resource = currentResources.find((item) => item.id === resourceId) || { availability: [], tours: [] };
            const nextAvailability = Array.isArray(resource.availability) ? resource.availability.slice() : [];
            nextAvailability.push({ start, end, notes });
            await saveResourceSchedule(resourceId, nextAvailability, resource.tours || [], __('Availability updated.', 'sbdp'));
            setDraftAvailability((current) => Object.assign({}, current, {
                [resourceId]: { start: '', end: '', notes: '' },
            }));
        };

        const handleRemoveAvailabilitySlot = async (resourceId, index) => {
            if (!canMutate) {
                return;
            }

            const resource = currentResources.find((item) => item.id === resourceId);
            if (!resource || !Array.isArray(resource.availability)) {
                return;
            }

            const nextAvailability = resource.availability.slice();
            nextAvailability.splice(index, 1);
            await saveResourceSchedule(resourceId, nextAvailability, resource.tours || [], __('Availability updated.', 'sbdp'));
        };

        const handleAddTourSlot = async (resourceId) => {
            if (!canMutate) {
                return;
            }

            const draft = ensureDraftEntry(draftTours, resourceId, {
                date: '',
                status: tourStatuses[0] || 'upcoming',
                notes: '',
            });
            const date = (draft.date || '').trim();

            setScheduleError('');
            setScheduleNotice('');

            if (!date) {
                setScheduleError(__('Please provide a tour date/time.', 'sbdp'));
                return;
            }

            const status = tourStatuses.includes(draft.status) ? draft.status : (tourStatuses[0] || 'upcoming');
            const notes = (draft.notes || '').trim();
            const resource = currentResources.find((item) => item.id === resourceId) || { availability: [], tours: [] };
            const nextTours = Array.isArray(resource.tours) ? resource.tours.slice() : [];
            nextTours.push({ date, status, notes });
            await saveResourceSchedule(resourceId, resource.availability || [], nextTours, __('Tour added.', 'sbdp'));
            setDraftTours((current) => Object.assign({}, current, {
                [resourceId]: {
                    date: '',
                    status: tourStatuses[0] || 'upcoming',
                    notes: '',
                },
            }));
        };

        const handleRemoveTourSlot = async (resourceId, index) => {
            if (!canMutate) {
                return;
            }

            const resource = currentResources.find((item) => item.id === resourceId);
            if (!resource || !Array.isArray(resource.tours)) {
                return;
            }

            const nextTours = resource.tours.slice();
            nextTours.splice(index, 1);
            await saveResourceSchedule(resourceId, resource.availability || [], nextTours, __('Tour removed.', 'sbdp'));
        };

        const handleToggleTourStatus = async (resourceId, index) => {
            if (!canMutate) {
                return;
            }

            const resource = currentResources.find((item) => item.id === resourceId);
            if (!resource || !Array.isArray(resource.tours) || !resource.tours[index]) {
                return;
            }

            const nextTours = resource.tours.slice();
            const updatedTour = Object.assign({}, nextTours[index]);
            updatedTour.status = getNextTourStatus(updatedTour.status);
            nextTours[index] = updatedTour;
            await saveResourceSchedule(resourceId, resource.availability || [], nextTours, __('Tour status updated.', 'sbdp'));
        };

        const handleCancelEdit = () => {
            handleCreateVendorClick();
        };
        const vendorRows = () => {
            const columnCount = 8;

            if (vendorsLoading) {
                return h('tr', { key: 'loading' }, [
                    h('td', { colSpan: columnCount }, __('Loading vendors.', 'sbdp')),
                ]);
            }

            if (!filteredVendors.length) {
                const message = vendorSearch.trim() || vendorStatusFilter !== 'all'
                    ? __('No vendors match the current filters.', 'sbdp')
                    : __('No vendors configured yet.', 'sbdp');
                return h('tr', { key: 'empty' }, [
                    h('td', { colSpan: columnCount }, message),
                ]);
            }

            return filteredVendors.map((vendor) => {
                const channelLabel = Array.isArray(vendor.channels) && vendor.channels.length
                    ? vendor.channels.join(', ')
                    : __('Not set', 'sbdp');
                const productCount = Array.isArray(vendor.product_ids) ? vendor.product_ids.length : 0;
                const resourceCount = Array.isArray(vendor.resource_ids) ? vendor.resource_ids.length : 0;
                let resourceLabel;

                if (_n && sprintf) {
                    resourceLabel = sprintf(_n('%d resource', '%d resources', resourceCount, 'sbdp'), resourceCount);
                } else if (resourceCount === 0) {
                    resourceLabel = __('None', 'sbdp');
                } else if (resourceCount === 1) {
                    resourceLabel = __('1 resource', 'sbdp');
                } else {
                    resourceLabel = resourceCount + ' ' + __('resources', 'sbdp');
                }

                return h('tr', { key: vendor.id }, [
                    h('td', { className: 'column-primary' }, [
                        h('strong', {}, vendor.name || __('(untitled)', 'sbdp')),
                        canMutate && h('div', { className: 'row-actions' }, [
                            h('button', {
                                type: 'button',
                                className: 'button-link',
                                onClick: () => handleEditVendor(vendor),
                            }, activeVendorId === vendor.id ? __('Editing', 'sbdp') : __('Edit', 'sbdp')),
                        ]),
                    ].filter(Boolean)),
                    h('td', {}, vendor.slug || '-'),
                    h('td', {}, canMutate
                        ? h('select', {
                            value: vendor.status,
                            onChange: (event) => changeVendorStatus(vendor, event.target.value),
                            disabled: listBusyId === vendor.id,
                        }, vendorStatuses.map((status) => h('option', { key: status, value: status }, status)))
                        : (vendor.status || '-')),
                    h('td', {}, channelLabel),
                    h('td', {}, resourceLabel),
                    h('td', {}, String(productCount)),
                    h('td', {}, canMutate && productCount > 0 ? h('button', {
                        type: 'button',
                        className: 'button button-secondary',
                        disabled: listBusyId === vendor.id,
                        onClick: () => syncVendorProducts(vendor),
                    }, listBusyId === vendor.id ? __('Syncing.', 'sbdp') : __('Refresh products', 'sbdp')) : '-'),
                ]);
            });
        };
        const renderSchedulePanel = () => {
            if (!canMutate) {
                return null;
            }

            if (!activeVendorId) {
                return h('div', { className: 'bsp-schedule-card' }, [
                    h('h2', {}, __('Availability & Tours', 'sbdp')),
                    h('p', {}, __('Select a vendor to review schedules and resources.', 'sbdp')),
                ]);
            }

            return h('div', { className: 'bsp-schedule-card' }, [
                h('h2', {}, __('Availability & Tours', 'sbdp')),
                activeVendor && activeVendor.name && h('p', { className: 'description' }, sprintf ? sprintf(__('Current vendor: %s', 'sbdp'), activeVendor.name) : __('Current vendor:', 'sbdp') + ' ' + activeVendor.name),
                scheduleError && h('div', { className: 'notice notice-error' }, h('p', {}, scheduleError)),
                scheduleNotice && h('div', { className: 'notice notice-success' }, h('p', {}, scheduleNotice)),
                h('div', { className: 'bsp-schedule-toolbar' }, [
                    h('label', { className: 'screen-reader-text', htmlFor: 'bsp-resource-search' }, __('Filter resources', 'sbdp')),
                    h('input', {
                        id: 'bsp-resource-search',
                        type: 'search',
                        placeholder: __('Filter resources', 'sbdp'),
                        value: resourceSearch,
                        onChange: (event) => setResourceSearch(event.target.value),
                        disabled: scheduleLoading,
                    }),
                    h('label', { className: 'screen-reader-text', htmlFor: 'bsp-schedule-tab' }, __('Schedule view', 'sbdp')),
                    h('select', {
                        id: 'bsp-schedule-tab',
                        value: scheduleTab,
                        onChange: (event) => setScheduleTab(event.target.value),
                        disabled: scheduleLoading,
                    }, [
                        h('option', { value: 'all' }, __('All sections', 'sbdp')),
                        h('option', { value: 'availability' }, __('Availability only', 'sbdp')),
                        h('option', { value: 'tours' }, __('Tours only', 'sbdp')),
                    ]),
                    h('button', {
                        type: 'button',
                        className: 'button',
                        onClick: handleScheduleReload,
                        disabled: scheduleLoading,
                    }, scheduleLoading ? __('Refreshing.', 'sbdp') : __('Refresh schedule', 'sbdp')),
                ]),
                scheduleLoading
                    ? h('p', {}, __('Loading schedule.', 'sbdp'))
                    : filteredResources.length === 0
                        ? h('p', {}, __('No resources assigned to this vendor yet.', 'sbdp'))
                        : filteredResources.map((resource) => {
                            const availDraft = ensureDraftEntry(draftAvailability, resource.id, { start: '', end: '', notes: '' });
                            const tourDraft = ensureDraftEntry(draftTours, resource.id, {
                                date: '',
                                status: tourStatuses[0] || 'upcoming',
                                notes: '',
                            });
                            const showAvailability = scheduleTab === 'all' || scheduleTab === 'availability';
                            const showTours = scheduleTab === 'all' || scheduleTab === 'tours';

                            return h('div', { key: resource.id, className: 'bsp-resource-card' }, [
                                h('h3', {}, resource.title || __('Untitled resource', 'sbdp')),
                                showAvailability && h('section', { className: 'bsp-resource-availability' }, [
                                    h('h4', {}, __('Availability slots', 'sbdp')),
                                    resource.availability && resource.availability.length
                                        ? h('ul', { className: 'bsp-availability-list' }, resource.availability.map((slot, index) => h('li', { key: `${slot.start}-${index}` }, [
                                            h('span', {}, `${slot.start} -> ${slot.end}`),
                                            slot.notes && h('em', { className: 'bsp-availability-note' }, ` (${slot.notes})`),
                                            canMutate && h('button', {
                                                type: 'button',
                                                className: 'button-link delete',
                                                onClick: () => handleRemoveAvailabilitySlot(resource.id, index),
                                                disabled: scheduleBusyId === resource.id,
                                                style: { marginLeft: '0.5rem' },
                                            }, __('Remove', 'sbdp')),
                                        ].filter(Boolean))))
                                        : h('p', {}, __('No availability stored yet.', 'sbdp')),
                                    canMutate && h('div', { className: 'bsp-form-inline' }, [
                                        h('input', {
                                            type: 'datetime-local',
                                            value: availDraft.start,
                                            onChange: (event) => updateDraftAvailability(resource.id, 'start', event.target.value),
                                            disabled: scheduleBusyId === resource.id,
                                        }),
                                        h('input', {
                                            type: 'datetime-local',
                                            value: availDraft.end,
                                            onChange: (event) => updateDraftAvailability(resource.id, 'end', event.target.value),
                                            disabled: scheduleBusyId === resource.id,
                                        }),
                                        h('input', {
                                            type: 'text',
                                            placeholder: __('Notes', 'sbdp'),
                                            value: availDraft.notes,
                                            onChange: (event) => updateDraftAvailability(resource.id, 'notes', event.target.value),
                                            disabled: scheduleBusyId === resource.id,
                                        }),
                                        h('button', {
                                            type: 'button',
                                            className: 'button button-secondary',
                                            onClick: () => handleAddAvailabilitySlot(resource.id),
                                            disabled: scheduleBusyId === resource.id,
                                        }, __('Add slot', 'sbdp')),
                                    ]),
                                ].filter(Boolean)),
                                showTours && h('section', { className: 'bsp-resource-tours' }, [
                                    h('h4', {}, __('Tours', 'sbdp')),
                                    resource.tours && resource.tours.length
                                        ? h('ul', { className: 'bsp-tour-list' }, resource.tours.map((tour, index) => h('li', { key: `${tour.date}-${index}` }, [
                                            h('span', {}, tour.date),
                                            tour.status && h('span', { className: `bsp-tour-status bsp-tour-status-${tour.status}` }, ` ${tour.status}`),
                                            tour.notes && h('em', { className: 'bsp-tour-note' }, ` (${tour.notes})`),
                                            canMutate && h('div', { className: 'bsp-tour-actions' }, [
                                                h('button', {
                                                    type: 'button',
                                                    className: 'button-link',
                                                    onClick: () => handleToggleTourStatus(resource.id, index),
                                                    disabled: scheduleBusyId === resource.id,
                                                }, __('Next status', 'sbdp')),
                                                h('button', {
                                                    type: 'button',
                                                    className: 'button-link delete',
                                                    onClick: () => handleRemoveTourSlot(resource.id, index),
                                                    disabled: scheduleBusyId === resource.id,
                                                }, __('Remove', 'sbdp')),
                                            ]),
                                        ].filter(Boolean))))
                                        : h('p', {}, __('No tours recorded yet.', 'sbdp')),
                                    canMutate && h('div', { className: 'bsp-form-inline' }, [
                                        h('input', {
                                            type: 'datetime-local',
                                            value: tourDraft.date,
                                            onChange: (event) => updateDraftTour(resource.id, 'date', event.target.value),
                                            disabled: scheduleBusyId === resource.id,
                                        }),
                                        h('select', {
                                            value: tourDraft.status,
                                            onChange: (event) => updateDraftTour(resource.id, 'status', event.target.value),
                                            disabled: scheduleBusyId === resource.id,
                                        }, tourStatuses.map((status) => h('option', { key: status, value: status }, status))),
                                        h('input', {
                                            type: 'text',
                                            placeholder: __('Notes', 'sbdp'),
                                            value: tourDraft.notes,
                                            onChange: (event) => updateDraftTour(resource.id, 'notes', event.target.value),
                                            disabled: scheduleBusyId === resource.id,
                                        }),
                                        h('button', {
                                            type: 'button',
                                            className: 'button button-secondary',
                                            onClick: () => handleAddTourSlot(resource.id),
                                            disabled: scheduleBusyId === resource.id,
                                        }, __('Add tour', 'sbdp')),
                                    ]),
                                ].filter(Boolean)),
                            ].filter(Boolean));
                        }),
            ]);
        };
        return h(Fragment, {}, [
            h('div', { className: 'bsp-sales-layout' }, [
                h('div', { className: 'bsp-sales-column' }, [
                    h('h2', {}, __('Vendors', 'sbdp')),
                    vendorLoadError && h('div', { className: 'notice notice-error' }, h('p', {}, vendorLoadError)),
                    listError && h('div', { className: 'notice notice-error' }, h('p', {}, listError)),
                    listNotice && h('div', { className: 'notice notice-success' }, h('p', {}, listNotice)),
                    h('div', { className: 'bsp-vendors-toolbar' }, [
                        h('label', { className: 'screen-reader-text', htmlFor: 'bsp-vendor-search' }, __('Search vendors', 'sbdp')),
                        h('input', {
                            id: 'bsp-vendor-search',
                            type: 'search',
                            placeholder: __('Search name or slug', 'sbdp'),
                            value: vendorSearch,
                            onChange: (event) => setVendorSearch(event.target.value),
                            disabled: vendorsLoading,
                        }),
                        h('label', { className: 'screen-reader-text', htmlFor: 'bsp-vendor-status' }, __('Filter by status', 'sbdp')),
                        h('select', {
                            id: 'bsp-vendor-status',
                            value: vendorStatusFilter,
                            onChange: (event) => setVendorStatusFilter(event.target.value),
                            disabled: vendorsLoading,
                        }, [h('option', { value: 'all' }, __('All statuses', 'sbdp'))].concat(
                            vendorStatuses.map((status) => h('option', { key: status, value: status }, status)),
                        )),
                        canMutate && h('button', {
                            type: 'button',
                            className: 'button button-secondary',
                            onClick: handleCreateVendorClick,
                        }, __('New vendor', 'sbdp')),
                    ].filter(Boolean)),
                    h('table', { className: 'widefat striped' }, [
                        h('thead', {}, h('tr', {}, [
                            h('th', { className: 'column-primary' }, __('Name', 'sbdp')),
                            h('th', {}, __('Slug', 'sbdp')),
                            h('th', {}, __('Status', 'sbdp')),
                            h('th', {}, __('Channels', 'sbdp')),
                            h('th', {}, __('Resource count', 'sbdp')),
                            h('th', {}, __('Products', 'sbdp')),
                            h('th', {}, __('Actions', 'sbdp')),
                        ])),
                        h('tbody', {}, vendorRows()),
                    ]),
                ]),
                h('div', { className: 'bsp-sales-column' }, [
                    h('h2', {}, activeVendorId ? __('Edit vendor', 'sbdp') : __('Create vendor', 'sbdp')),
                    formError && h('div', { className: 'notice notice-error' }, h('p', {}, formError)),
                    formNotice && h('div', { className: 'notice notice-success' }, h('p', {}, formNotice)),
                    canMutate ? h('form', { onSubmit: handleSubmitForm }, [
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-name' }, __('Name', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-name',
                                type: 'text',
                                required: true,
                                value: formState.name,
                                onChange: handleFormFieldChange('name'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-slug' }, __('Slug', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-slug',
                                type: 'text',
                                value: formState.slug,
                                onChange: handleFormFieldChange('slug'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-status' }, __('Status', 'sbdp')),
                            h('select', {
                                id: 'bsp-vendor-status',
                                value: formState.status,
                                onChange: handleFormFieldChange('status'),
                                disabled: isSavingVendor,
                            }, vendorStatuses.map((status) => h('option', { key: status, value: status }, status))),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-channels' }, __('Channels (comma separated)', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-channels',
                                type: 'text',
                                value: formState.channels,
                                onChange: handleFormFieldChange('channels'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-resources' }, __('Resource IDs (comma separated)', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-resources',
                                type: 'text',
                                value: formState.resources,
                                onChange: handleFormFieldChange('resources'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-products' }, __('Product IDs (comma separated)', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-products',
                                type: 'text',
                                value: formState.products,
                                onChange: handleFormFieldChange('products'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-commission' }, __('Commission rate (%)', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-commission',
                                type: 'number',
                                step: '0.01',
                                min: '0',
                                max: '100',
                                value: formState.commission,
                                onChange: handleFormFieldChange('commission'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-max-capacity' }, __('Max capacity', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-max-capacity',
                                type: 'number',
                                min: '0',
                                value: formState.maxCapacity,
                                onChange: handleFormFieldChange('maxCapacity'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-timezone' }, __('Timezone', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-timezone',
                                type: 'text',
                                value: formState.timezone,
                                onChange: handleFormFieldChange('timezone'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-contact-name' }, __('Contact name', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-contact-name',
                                type: 'text',
                                value: formState.contactName,
                                onChange: handleFormFieldChange('contactName'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-contact-email' }, __('Contact email', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-contact-email',
                                type: 'email',
                                value: formState.contactEmail,
                                onChange: handleFormFieldChange('contactEmail'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-contact-phone' }, __('Contact phone', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-contact-phone',
                                type: 'tel',
                                value: formState.contactPhone,
                                onChange: handleFormFieldChange('contactPhone'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-webhook' }, __('Webhook URL', 'sbdp')),
                            h('input', {
                                id: 'bsp-vendor-webhook',
                                type: 'url',
                                value: formState.webhookUrl,
                                onChange: handleFormFieldChange('webhookUrl'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', {}, [
                            h('label', { htmlFor: 'bsp-vendor-notes' }, __('Internal notes', 'sbdp')),
                            h('textarea', {
                                id: 'bsp-vendor-notes',
                                rows: 4,
                                value: formState.notes,
                                onChange: handleFormFieldChange('notes'),
                                disabled: isSavingVendor,
                            }),
                        ]),
                        h('p', { className: 'submit' }, [
                            h('button', {
                                type: 'submit',
                                className: 'button button-primary',
                                disabled: isSavingVendor,
                            }, isSavingVendor ? __('Saving.', 'sbdp') : activeVendorId ? __('Update vendor', 'sbdp') : __('Create vendor', 'sbdp')),
                            activeVendorId && h('button', {
                                type: 'button',
                                className: 'button button-secondary',
                                onClick: handleCancelEdit,
                                disabled: isSavingVendor,
                                style: { marginLeft: '0.5rem' },
                            }, __('Cancel', 'sbdp')),
                        ].filter(Boolean)),
                    ]) : h('p', {}, __('You do not have permission to manage vendors.', 'sbdp')),
                ]),
            ]),
            renderSchedulePanel(),
        ]);
    };
    const rootFactory = typeof createRoot === 'function' ? createRoot : null;
    if (rootFactory) {
        rootFactory(rootEl).render(h(VendorsApp));
    } else if (typeof render === 'function') {
        render(h(VendorsApp), rootEl);
    }
})();
















