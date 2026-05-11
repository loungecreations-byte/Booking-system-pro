/**
 * Modern Tour Builder - Admin Interface
 * Drag & drop, map picker, media library integration
 * 
 * @package Booking_Pro_Module
 */

(function($) {
    'use strict';

    // Builder state
    const state = {
        steps: [],
        map: null,
        activeStepIndex: null,
        currentMarker: null,
        stepMarkers: []
    };

    // Step templates
    const STEP_TEMPLATES = {
        audio: {
            title: 'Audio Stap',
            type: 'audio',
            content: 'Luister naar de audio guide...',
            points: 20
        },
        video: {
            title: 'Video Stap',
            type: 'video',
            content: 'Bekijk de video...',
            points: 25
        },
        vr: {
            title: 'VR/AR Ervaring',
            type: 'vr',
            content: 'Ontdek de virtual reality ervaring...',
            points: 40
        },
        game: {
            title: 'Game Challenge',
            type: 'game',
            content: 'Voltooi de uitdaging...',
            points: 35
        },
        text: {
            title: 'Informatie Punt',
            type: 'text',
            content: 'Lees meer over...',
            points: 15
        }
    };

    const GEOCODE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';
    const GEOCODE_SEARCH_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    const locationLabelCache = new Map();
    const locationSearchCache = new Map();

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    }

    function escapeAttribute(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function parseGamificationPayload(value) {
        if (!value) {
            return {};
        }

        if (typeof value === 'object') {
            return { ...value };
        }

        try {
            const decoded = JSON.parse(String(value));
            return decoded && typeof decoded === 'object' ? decoded : {};
        } catch (error) {
            return {};
        }
    }

    function buildGamificationPayload(step) {
        const payload = parseGamificationPayload(step.gamification || '');
        const fields = {
            challenge: String(step.missionChallenge || '').trim(),
            clue: String(step.missionClue || '').trim(),
            reveal: String(step.missionReveal || '').trim(),
        };

        Object.entries(fields).forEach(([key, value]) => {
            if (value) {
                payload[key] = value;
            } else {
                delete payload[key];
            }
        });

        return Object.keys(payload).length ? JSON.stringify(payload) : '';
    }

    function normaliseAltitude(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    async function fetchCoordinatesForLabel(label) {
        if (!label || typeof fetch !== 'function') {
            return null;
        }

        const trimmed = label.trim();
        if (!trimmed) {
            return null;
        }

        const cacheKey = trimmed.toLowerCase();
        if (locationSearchCache.has(cacheKey)) {
            return locationSearchCache.get(cacheKey);
        }

        const params = new URLSearchParams({
            format: 'jsonv2',
            q: trimmed,
            limit: '1',
            addressdetails: '1',
            countrycodes: 'nl',
        });

        try {
            console.log('[Tour Builder] Geocoding search:', trimmed);
            const response = await fetch(`${GEOCODE_SEARCH_ENDPOINT}?${params.toString()}`, {
                method: 'GET',
                mode: 'cors',
                headers: {
                    'Accept': 'application/json',
                }
            });

            console.log('[Tour Builder] Response status:', response.status);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('[Tour Builder] Geocode error:', response.status, errorText);
                throw new Error(`Geocode search failed: ${response.status}`);
            }

            const data = await response.json();
            console.log('[Tour Builder] Geocode data:', data);
            
            const candidate = Array.isArray(data) && data.length > 0 ? data[0] : null;
            if (candidate && candidate.lat && candidate.lon) {
                const parsed = {
                    lat: parseFloat(candidate.lat),
                    lng: parseFloat(candidate.lon),
                    label: candidate.display_name || trimmed,
                };
                console.log('[Tour Builder] Found location:', parsed);
                locationSearchCache.set(cacheKey, parsed);
                return parsed;
            } else {
                console.warn('[Tour Builder] No results found for:', trimmed);
            }
        } catch (error) {
            console.error('[Tour Builder] Forward geocode failed:', error);
        }

        locationSearchCache.set(cacheKey, null);
        return null;
    }

    async function handleLocationLabelInput(value, index, $modal, updateFeedback = () => {}) {
        const trimmed = (value || '').trim();
        console.log('[Tour Builder] handleLocationLabelInput called:', {trimmed, index, hasModal: $modal.length > 0});
        
        if (!trimmed) {
            updateFeedback('', 'info');
            return;
        }

        updateFeedback(`Zoeken naar "${trimmed}"…`, 'info');

        const latField = $modal.find('[name="location_lat"]');
        const lngField = $modal.find('[name="location_lng"]');
        console.log('[Tour Builder] Found fields:', {latField: latField.length, lngField: lngField.length});

        try {
            const result = await fetchCoordinatesForLabel(trimmed);
            console.log('[Tour Builder] Geocode result:', result);
            
            if (!result) {
                updateFeedback(`❌ Geen resultaten gevonden voor "${trimmed}". Probeer een vollediger adres.`, 'error');
                return null;
            }

            latField.val(result.lat);
            lngField.val(result.lng);
            console.log('[Tour Builder] Set field values:', {lat: result.lat, lng: result.lng});
            
            const displayLabel = result.label || trimmed;
            const normalizedInput = trimmed.toLowerCase();
            const normalizedDisplay = displayLabel.toLowerCase();
            const tokens = normalizedInput
                .split(/\s+/)
                .filter(Boolean);
            const matches = tokens.every((token) => normalizedDisplay.includes(token));
            updateFeedback(
                matches ? `Adres gevonden: ${displayLabel}` : `Suggestie: ${displayLabel}`,
                matches ? 'success' : 'warning'
            );

            if ($modal.length) {
                $modal.find('[name="location_label"]').val(displayLabel);
                // Update location display in modal
                const $locationDisplay = $modal.find('.sbdp-location-display');
                console.log('[Tour Builder] Found location display:', $locationDisplay.length);
                if ($locationDisplay.length) {
                    $locationDisplay.text(`📍 ${result.lat.toFixed(4)}, ${result.lng.toFixed(4)}`);
                }
            }

            setStepLocation(index, result.lat, result.lng, {
                label: displayLabel,
                labelAuto: false,
                skipEnsure: true,
            });

            console.log('[Tour Builder] Checking map:', {hasMap: !!state.map, hasLeaflet: typeof L !== 'undefined'});
            if (state.map && typeof L !== 'undefined') {
                console.log('[Tour Builder] Updating marker to:', result.lat, result.lng);
                updateMarker(L.latLng(result.lat, result.lng));
            } else {
                console.warn('[Tour Builder] Map not available:', {hasMap: !!state.map, hasLeaflet: typeof L !== 'undefined'});
            }
            
            return result;
        } catch (error) {
            console.error('[Tour Builder] Error in handleLocationLabelInput:', error);
            updateFeedback(`⚠️ Fout bij zoeken: ${error.message}`, 'error');
            return null;
        }
    }

    function setLocationFeedback($feedback, message, variant = 'info') {
        if (!$feedback || !$feedback.length) {
            return;
        }

        $feedback
            .attr('data-variant', variant)
            .text(message || '')
            .toggleClass('is-visible', Boolean(message));
    }

    function formatLocationText(step) {
        if (!step) {
            return '❌ Geen locatie ingesteld';
        }

        if (step.locationLabel) {
            return `📍 ${step.locationLabel}`;
        }

        const lat = Number.isFinite(step.lat) ? step.lat : null;
        const lng = Number.isFinite(step.lng) ? step.lng : null;

        if (lat !== null && lng !== null) {
            return `📍 ${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        }

        return '❌ Geen locatie ingesteld';
    }

    function updateLocationDisplay(index) {
        const step = state.steps[index];
        if (!step) {
            return;
        }

        const text = formatLocationText(step);
        const $step = $(`[data-step-index="${index}"]`);
        $step.find('[data-step-location]').text(text);
        $('.sbdp-location-display').text(text);
        $('[name="location_label"]').val(step.locationLabel || '');
    }

    async function fetchLocationLabel(lat, lng) {
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || typeof fetch !== 'function') {
            return '';
        }

        const key = `${lat.toFixed(6)},${lng.toFixed(6)}`;
        if (locationLabelCache.has(key)) {
            return locationLabelCache.get(key);
        }

        const params = new URLSearchParams({
            format: 'jsonv2',
            lat,
            lon: lng,
            'accept-language': 'nl',
            addressdetails: '0',
        });

        try {
            const response = await fetch(`${GEOCODE_ENDPOINT}?${params.toString()}`, {
                method: 'GET',
                mode: 'cors',
            });

            if (!response.ok) {
                throw new Error('Geocode error');
            }

            const data = await response.json();
            const rawLabel = typeof data?.display_name === 'string' ? data.display_name : '';
            const trimmed = rawLabel
                ? rawLabel
                      .split(',')
                      .map((part) => part.trim())
                      .filter(Boolean)
                      .slice(0, 3)
                      .join(', ')
                : '';

            locationLabelCache.set(key, trimmed);
            return trimmed;
        } catch (error) {
            console.warn('[Tour Builder] Reverse geocode failed', error);
            locationLabelCache.set(key, '');
            return '';
        }
    }

    function ensureLocationLabel(index, lat, lng) {
        if (!state.steps[index]) {
            return;
        }

        fetchLocationLabel(lat, lng).then((label) => {
            if (!label) {
                return;
            }

            const step = state.steps[index];
            if (!step) {
                return;
            }

            step.locationLabel = label;
            step.locationLabelAuto = true;
            updateLocationDisplay(index);
            saveBlueprint();
        });
    }

    /**
     * Initialize the tour builder
     */
    function init() {
        console.log('[Tour Builder] Initializing...');
        console.log('[Tour Builder] jQuery version:', $.fn.jquery);
        
        const $builder = $('[data-private-tour-builder]');
        
        console.log('[Tour Builder] Builder element found:', $builder.length);
        
        if ($builder.length === 0) {
            console.warn('[Tour Builder] No builder element found with [data-private-tour-builder]');
            return;
        }

        // Load existing steps from blueprint
        const blueprint = $('#sbdp_tour_blueprint').val();
        console.log('[Tour Builder] Blueprint value:', blueprint);
        
        if (blueprint) {
            try {
                const data = JSON.parse(blueprint);
                state.steps = (data.steps || []).map(step => {
                    const gamificationPayload = parseGamificationPayload(step.gamification || '');
                    const locationLabel = step.locationLabel || step.location_label || '';
                    const lat = step.lat !== undefined && step.lat !== null && step.lat !== '' ? parseFloat(step.lat) : null;
                    const lng = step.lng !== undefined && step.lng !== null && step.lng !== '' ? parseFloat(step.lng) : null;
                    const altitudeM = normaliseAltitude(step.altitudeM !== undefined ? step.altitudeM : step.altitude_m);
                    const area = typeof step.area === 'string' ? step.area : '';
                    const locationType = typeof step.locationType === 'string'
                        ? step.locationType
                        : (typeof step.location_type === 'string' ? step.location_type : '');

                    return {
                        ...step,
                        lat,
                        lng,
                        altitude_m: altitudeM,
                        area,
                        locationType,
                        points: parseInt(step.points) || 0,
                        video_url: step.videoUrl || step.video_url || '',
                        audio_url: step.audioUrl || step.audio_url || '',
                        image_url: step.imageUrl || step.image_url || '',
                        heygen_video_url: step.heygenVideoUrl || step.heygen_video_url || '',
                        gamification: typeof step.gamification === 'string' ? step.gamification : buildGamificationPayload(step),
                        missionChallenge: typeof step.missionChallenge === 'string' ? step.missionChallenge : (typeof gamificationPayload.challenge === 'string' ? gamificationPayload.challenge : ''),
                        missionClue: typeof step.missionClue === 'string' ? step.missionClue : (typeof gamificationPayload.clue === 'string' ? gamificationPayload.clue : ''),
                        missionReveal: typeof step.missionReveal === 'string' ? step.missionReveal : (typeof gamificationPayload.reveal === 'string' ? gamificationPayload.reveal : ''),
                        locationLabel,
                        locationLabelAuto: locationLabel ? false : true,
                    };
                });
                console.log('[Tour Builder] Loaded and normalized steps from blueprint:', state.steps.length);
                console.log('[Tour Builder] First step sample:', state.steps[0]);
            } catch (err) {
                console.error('[Tour Builder] Failed to parse blueprint:', err);
                state.steps = [];
            }
        } else {
            console.log('[Tour Builder] No existing blueprint, starting fresh');
        }

        // Setup UI
        console.log('[Tour Builder] Setting up event listeners...');
        setupEventListeners($builder);
        
        console.log('[Tour Builder] Initializing map...');
        initMap($builder);
        
        console.log('[Tour Builder] Rendering step list...');
        renderStepList($builder);
        
        // Enable sortable
        makeSortable($builder);

        console.log('[Tour Builder] Initialized with', state.steps.length, 'steps');
    }

    /**
     * Setup event listeners
     */
    function setupEventListeners($builder) {
        console.log('[Tour Builder] Setting up event listeners on builder:', $builder);
        
        // Add new step
        $builder.on('click', '[data-private-tour-add-step]', function(e) {
            e.preventDefault();
            console.log('[Tour Builder] Add step button clicked!');
            showTemplateSelector($builder);
        });

        // Edit step
        $builder.on('click', '[data-step-edit]', function(e) {
            e.preventDefault();
            const index = $(this).closest('[data-step-index]').data('step-index');
            openStepEditor(index, $builder);
        });

        // Delete step
        $builder.on('click', '[data-step-delete]', function(e) {
            e.preventDefault();
            const index = $(this).closest('[data-step-index]').data('step-index');
            if (confirm('Weet je zeker dat je deze stap wilt verwijderen?')) {
                deleteStep(index, $builder);
            }
        });

        // Duplicate step
        $builder.on('click', '[data-step-duplicate]', function(e) {
            e.preventDefault();
            const index = $(this).closest('[data-step-index]').data('step-index');
            duplicateStep(index, $builder);
        });

        // Save before submit
        $('form#post').on('submit', function() {
            saveBlueprint();
        });
    }

    /**
     * Initialize interactive map for location picking
     */
    function initMap($builder) {
        // Check if Leaflet is available
        if (typeof L === 'undefined') {
            console.warn('[Tour Builder] Leaflet not loaded, map picker disabled');
            return;
        }

        // Create map container if not exists
        let $mapContainer = $builder.find('[data-builder-map]');
        if ($mapContainer.length === 0) {
            $mapContainer = $('<div data-builder-map style="height: 400px; margin: 20px 0; border-radius: 8px; overflow: hidden;"></div>');
            $builder.find('[data-builder-list]').before($mapContainer);
        }

        // Initialize map
        state.map = L.map($mapContainer[0], {
            center: [51.6879, 5.3048], // Den Bosch center
            zoom: 14
        });

        // Add tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(state.map);

        // Add click handler for setting location
        state.map.on('click', function(e) {
            if (state.activeStepIndex !== null) {
                setStepLocation(state.activeStepIndex, e.latlng.lat, e.latlng.lng);
                updateMarker(e.latlng);
            }
        });

        // Render existing markers
        renderStepMarkers();
    }

    /**
     * Render all step markers on map
     */
    function clearStepMarkers() {
        if (!state.map) {
            return;
        }

        state.stepMarkers.forEach((marker) => {
            state.map.removeLayer(marker);
        });
        state.stepMarkers = [];
    }

    function renderStepMarkers() {
        if (!state.map) {
            return;
        }

        clearStepMarkers();

        state.steps.forEach((step) => {
            if (!Number.isFinite(step.lat) || !Number.isFinite(step.lng)) {
                return;
            }

            const marker = L.marker([step.lat, step.lng], {
                title: step.locationLabel || step.title || ''
            }).addTo(state.map);

            const popupParts = [];
            if (step.title) {
                popupParts.push(`<strong>${escapeHtml(step.title)}</strong>`);
            }
            if (step.locationLabel) {
                popupParts.push(`<span>${escapeHtml(step.locationLabel)}</span>`);
            }
            popupParts.push(`<small>${escapeHtml(step.type || 'text')}</small>`);

            marker.bindPopup(popupParts.join('<br>'));
            state.stepMarkers.push(marker);
        });
    }

    /**
     * Update current edit marker
     */
    function updateMarker(latlng) {
        if (!state.map) return;

        // Remove old marker
        if (state.currentMarker) {
            state.map.removeLayer(state.currentMarker);
        }

        // Add new marker
        state.currentMarker = L.marker(latlng, {
            draggable: true,
            icon: L.divIcon({
                html: '<div style="background: #dc3232; width: 32px; height: 32px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3);"></div>',
                className: 'custom-edit-marker',
                iconSize: [32, 32],
                iconAnchor: [16, 16]
            })
        }).addTo(state.map);

        // Pan map to marker
        state.map.setView(latlng, state.map.getZoom());

        // Update on drag
        state.currentMarker.on('dragend', function(e) {
            const pos = e.target.getLatLng();
            if (state.activeStepIndex !== null) {
                setStepLocation(state.activeStepIndex, pos.lat, pos.lng);
                renderStepMarkers();
            }
        });

        // Center map on marker
        state.map.setView(latlng, 16);
    }

    /**
     * Set step location
     */
    function setStepLocation(index, lat, lng, options = {}) {
        const step = state.steps[index];
        if (!step) {
            return;
        }

        const normalizedLat = parseFloat(lat.toFixed(6));
        const normalizedLng = parseFloat(lng.toFixed(6));

        step.lat = normalizedLat;
        step.lng = normalizedLng;

        const {
            label,
            labelAuto = true,
            skipEnsure = false,
        } = options;

        if (label !== undefined) {
            step.locationLabel = label;
        } else if (labelAuto) {
            step.locationLabel = '';
        }

        step.locationLabelAuto = Boolean(labelAuto);

        updateLocationDisplay(index);
        renderStepMarkers();
        if (labelAuto && !skipEnsure) {
            ensureLocationLabel(index, normalizedLat, normalizedLng);
        }
        console.log(`[Tour Builder] Updated step ${index} location:`, normalizedLat, normalizedLng);
        saveBlueprint();
    }

    /**
     * Show template selector modal
     */
    function showTemplateSelector($builder) {
        const $modal = $('<div class="sbdp-modal-overlay"></div>');
        const $content = $(`
            <div class="sbdp-modal">
                <div class="sbdp-modal__header">
                    <h2>Kies een stap type</h2>
                    <button type="button" class="sbdp-modal__close">&times;</button>
                </div>
                <div class="sbdp-modal__body">
                    <div class="sbdp-template-grid">
                        ${Object.keys(STEP_TEMPLATES).map(key => {
                            const tpl = STEP_TEMPLATES[key];
                            const icons = {
                                audio: '🎵',
                                video: '🎬',
                                vr: '🥽',
                                game: '🎮',
                                text: '📝'
                            };
                            return `
                                <button type="button" class="sbdp-template-card" data-template="${key}">
                                    <span class="sbdp-template-card__icon">${icons[key] || '📄'}</span>
                                    <span class="sbdp-template-card__title">${tpl.title}</span>
                                    <span class="sbdp-template-card__points">${tpl.points} punten</span>
                                </button>
                            `;
                        }).join('')}
                        <button type="button" class="sbdp-template-card" data-template="blank">
                            <span class="sbdp-template-card__icon">➕</span>
                            <span class="sbdp-template-card__title">Lege stap</span>
                            <span class="sbdp-template-card__points">Zelf invullen</span>
                        </button>
                    </div>
                </div>
            </div>
        `);

        $modal.append($content);
        $('body').append($modal);

        console.log('[Tour Builder] Template selector modal opened');

        // Template selection (must be first to prevent propagation issues)
        $modal.on('click', '[data-template]', function(e) {
            e.stopPropagation();
            const template = $(this).data('template');
            console.log('[Tour Builder] Template selected:', template);
            addStepFromTemplate(template, $builder);
            $modal.remove();
        });

        // Close handlers
        $modal.on('click', '.sbdp-modal__close', function(e) {
            e.stopPropagation();
            console.log('[Tour Builder] Close button clicked');
            $modal.remove();
        });

        $modal.on('click', function(e) {
            if ($(e.target).is('.sbdp-modal-overlay')) {
                console.log('[Tour Builder] Overlay clicked, closing modal');
                $modal.remove();
            }
        });
    }

    /**
     * Add step from template
     */
    function addStepFromTemplate(templateKey, $builder) {
        console.log('[Tour Builder] addStepFromTemplate called with:', templateKey);
        
        const template = STEP_TEMPLATES[templateKey] || {};
        const newStep = {
            id: Date.now(),
            title: template.title || 'Nieuwe stap',
            content: template.content || '',
            type: template.type || 'text',
            points: template.points || 0,
            lat: null,
            lng: null,
            video_url: '',
            audio_url: '',
            image_url: '',
            heygen_video_url: '',
            gamification: '',
            missionChallenge: '',
            missionClue: '',
            missionReveal: '',
            altitude_m: null,
            area: '',
            locationType: '',
            locationLabel: '',
            locationLabelAuto: true
        };

        console.log('[Tour Builder] Created new step:', newStep);

        state.steps.push(newStep);
        renderStepList($builder);
        
        console.log('[Tour Builder] Step added, total steps:', state.steps.length);
        
        // Open editor immediately
        const newIndex = state.steps.length - 1;
        console.log('[Tour Builder] Opening editor for step index:', newIndex);
        openStepEditor(newIndex, $builder);
    }

    /**
     * Open step editor modal
     */
    function openStepEditor(index, $builder) {
        console.log('[Tour Builder] openStepEditor called with index:', index);
        
        const step = state.steps[index];
        if (!step) {
            console.error('[Tour Builder] Step not found at index:', index);
            return;
        }

        const safeTitle = escapeAttribute(step.title);
        const safeContent = escapeHtml(step.content);
        const safeLocationLabel = escapeAttribute(step.locationLabel || '');
        const safeVideoUrl = escapeAttribute(step.video_url || '');
        const safeAudioUrl = escapeAttribute(step.audio_url || '');
        const safeImageUrl = escapeAttribute(step.image_url || '');
        const safeHeygenUrl = escapeAttribute(step.heygen_video_url || '');
        const safeArea = escapeAttribute(step.area || '');
        const safeLocationType = escapeAttribute(step.locationType || '');
        const safeMissionChallenge = escapeHtml(step.missionChallenge || '');
        const safeMissionClue = escapeHtml(step.missionClue || '');
        const safeMissionReveal = escapeHtml(step.missionReveal || '');

        console.log('[Tour Builder] Opening editor for step:', step);

        state.activeStepIndex = index;

        const $modal = $('<div class="sbdp-modal-overlay"></div>');
        const $content = $(`
            <div class="sbdp-modal sbdp-modal--large">
                <div class="sbdp-modal__header">
                    <div class="sbdp-modal__header-copy">
                        <p class="sbdp-modal__eyebrow">Tourstop ${index + 1}</p>
                        <h2>Bewerk stop</h2>
                    </div>
                    <button type="button" class="sbdp-modal__close">&times;</button>
                </div>
                <div class="sbdp-modal__body">
                    <div class="sbdp-step-editor">
                        <section class="sbdp-editor-section sbdp-editor-section--story">
                            <div class="sbdp-editor-section__header">
                                <p class="sbdp-editor-section__eyebrow">Verhaal</p>
                                <h3 class="sbdp-editor-section__title">Inhoud van deze stop</h3>
                            </div>
                            <div class="sbdp-field-group sbdp-field-group--story-head">
                                <div class="sbdp-field">
                                    <label>Titel</label>
                                    <input type="text" name="title" value="${safeTitle}" class="widefat" />
                                </div>
                                <div class="sbdp-field">
                                    <label>Type</label>
                                    <select name="type" class="widefat">
                                        <option value="text" ${step.type === 'text' ? 'selected' : ''}>Tekst</option>
                                        <option value="audio" ${step.type === 'audio' ? 'selected' : ''}>Audio</option>
                                        <option value="video" ${step.type === 'video' ? 'selected' : ''}>Video</option>
                                        <option value="vr" ${step.type === 'vr' ? 'selected' : ''}>VR/AR</option>
                                        <option value="game" ${step.type === 'game' ? 'selected' : ''}>Game</option>
                                    </select>
                                </div>
                                <div class="sbdp-field sbdp-field--points">
                                    <label>Punten</label>
                                    <input type="number" name="points" value="${step.points || 0}" class="widefat" min="0" />
                                </div>
                            </div>
                            <div class="sbdp-field">
                                <label>Verhaaltekst</label>
                                <textarea name="content" rows="10" class="widefat">${safeContent}</textarea>
                                <p class="description">Gebruik headings en alinea's. Voorbeeld: <code>&lt;h2&gt;Volgende aanwijzing&lt;/h2&gt;</code> gevolgd door gewone paragrafen.</p>
                            </div>
                        </section>

                        <section class="sbdp-editor-section sbdp-editor-section--mission">
                            <div class="sbdp-editor-section__header">
                                <p class="sbdp-editor-section__eyebrow">Missie</p>
                                <h3 class="sbdp-editor-section__title">Maak de opdracht concreet</h3>
                            </div>
                            <div class="sbdp-field-group sbdp-field-group--mission">
                                <div class="sbdp-field">
                                    <label>Missie opdracht</label>
                                    <textarea name="mission_challenge" rows="3" class="widefat" placeholder="Zoek het detail boven de ingang.">${safeMissionChallenge}</textarea>
                                </div>
                                <div class="sbdp-field">
                                    <label>Missie hint</label>
                                    <textarea name="mission_clue" rows="2" class="widefat" placeholder="Kijk links van de boog.">${safeMissionClue}</textarea>
                                </div>
                                <div class="sbdp-field">
                                    <label>Missie reveal</label>
                                    <textarea name="mission_reveal" rows="2" class="widefat" placeholder="Daar zie je het symbool dat Bosch vaker gebruikte.">${safeMissionReveal}</textarea>
                                </div>
                            </div>
                        </section>

                        <section class="sbdp-editor-section sbdp-editor-section--location">
                            <div class="sbdp-editor-section__header">
                                <p class="sbdp-editor-section__eyebrow">Locatie</p>
                                <h3 class="sbdp-editor-section__title">Waar vindt deze stop plaats?</h3>
                            </div>
                            <div class="sbdp-field">
                                <label>Huidige locatie</label>
                                <p class="description">Klik op de kaart in de builder of vul hieronder het label en de coördinaten in.</p>
                                <div class="sbdp-location-display">
                                    ${step.lat && step.lng ? `📍 ${step.lat.toFixed(4)}, ${step.lng.toFixed(4)}` : 'Nog geen locatie ingesteld'}
                                </div>
                            </div>
                            <div class="sbdp-field">
                                <label>Locatie label</label>
                                <input type="text" name="location_label" value="${safeLocationLabel}" class="widefat" placeholder="Markt 1, 's-Hertogenbosch" />
                                <p class="sbdp-location-feedback" data-location-feedback></p>
                            </div>
                            <div class="sbdp-field-group sbdp-field-group--location">
                                <div class="sbdp-field">
                                    <label>Latitude</label>
                                    <input type="number" step="0.000001" name="location_lat" value="${step.lat !== null && step.lat !== undefined ? step.lat : ''}" class="widefat" placeholder="52.0907" />
                                </div>
                                <div class="sbdp-field">
                                    <label>Longitude</label>
                                    <input type="number" step="0.000001" name="location_lng" value="${step.lng !== null && step.lng !== undefined ? step.lng : ''}" class="widefat" placeholder="5.1214" />
                                </div>
                                <div class="sbdp-field">
                                    <label>Altitude (m)</label>
                                    <input type="number" step="any" name="location_altitude_m" value="${step.altitude_m !== null && step.altitude_m !== undefined ? step.altitude_m : ''}" class="widefat" placeholder="6" />
                                </div>
                                <div class="sbdp-field">
                                    <label>Area</label>
                                    <input type="text" name="location_area" value="${safeArea}" class="widefat" placeholder="Markt" />
                                </div>
                                <div class="sbdp-field">
                                    <label>Locatie type</label>
                                    <input type="text" name="location_type" value="${safeLocationType}" class="widefat" placeholder="monument" />
                                </div>
                            </div>
                        </section>

                        <section class="sbdp-editor-section sbdp-editor-section--media">
                            <div class="sbdp-editor-section__header">
                                <p class="sbdp-editor-section__eyebrow">Media</p>
                                <h3 class="sbdp-editor-section__title">Koppel video, audio of beeld</h3>
                            </div>
                            <div class="sbdp-field-group sbdp-field-group--media">
                                <div class="sbdp-field">
                                    <label>Video URL</label>
                                    <div class="sbdp-media-field">
                                        <input type="url" name="video_url" value="${safeVideoUrl}" class="widefat" />
                                        <button type="button" class="button" data-media-select="video">Kies</button>
                                    </div>
                                </div>
                                
                                <div class="sbdp-field">
                                    <label>Audio URL</label>
                                    <div class="sbdp-media-field">
                                        <input type="url" name="audio_url" value="${safeAudioUrl}" class="widefat" />
                                        <button type="button" class="button" data-media-select="audio">Kies</button>
                                    </div>
                                </div>
                                
                                <div class="sbdp-field">
                                    <label>Afbeelding URL</label>
                                    <div class="sbdp-media-field">
                                        <input type="url" name="image_url" value="${safeImageUrl}" class="widefat" />
                                        <button type="button" class="button" data-media-select="image">Kies</button>
                                    </div>
                                </div>

                                <div class="sbdp-field">
                                    <label>HeyGen Video URL</label>
                                    <div class="sbdp-media-field">
                                        <input type="url" name="heygen_video_url" value="${safeHeygenUrl}" class="widefat" placeholder="https://app.heygen.com/embeds/..." />
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="sbdp-modal__footer">
                    <button type="button" class="button button-primary" data-save-step>Stop opslaan</button>
                    <button type="button" class="button" data-cancel>Annuleren</button>
                </div>
            </div>
        `);

        $modal.append($content);
        $('body').append($modal);

        console.log('[Tour Builder] Step editor modal appended to body');

        const $labelInput = $modal.find('[name="location_label"]');
        const $latInput = $modal.find('[name="location_lat"]');
        const $lngInput = $modal.find('[name="location_lng"]');
        const $feedback = $modal.find('[data-location-feedback]');
        const updateFeedback = (message, variant = 'info') => setLocationFeedback($feedback, message, variant);
        let labelTimer = null;

        const scheduleLabelLookup = () => {
            clearTimeout(labelTimer);
            labelTimer = setTimeout(() => {
                handleLocationLabelInput($labelInput.val(), index, $modal, updateFeedback);
            }, 600);
        };

        $labelInput.on('input', scheduleLabelLookup);
        $labelInput.on('blur', () => {
            clearTimeout(labelTimer);
            handleLocationLabelInput($labelInput.val(), index, $modal, updateFeedback);
        });

        const updateMapFromCoordinates = () => {
            const latValue = parseFloat($latInput.val());
            const lngValue = parseFloat($lngInput.val());

            if (!Number.isFinite(latValue) || !Number.isFinite(lngValue)) {
                return;
            }

            const manualLabel = String($labelInput.val() || '').trim();
            setStepLocation(index, latValue, lngValue, {
                label: manualLabel || undefined,
                labelAuto: !manualLabel,
                skipEnsure: true
            });
            
            // Update location display in modal
            const $locationDisplay = $modal.find('.sbdp-location-display');
            if ($locationDisplay.length) {
                $locationDisplay.text(`📍 ${latValue.toFixed(4)}, ${lngValue.toFixed(4)}`);
            }
            
            if (state.map && typeof L !== 'undefined') {
                updateMarker(L.latLng(latValue, lngValue));
            }
            updateFeedback('Coördinaten bijgewerkt', 'success');
            saveBlueprint();
        };

        $latInput.on('change', updateMapFromCoordinates);
        $lngInput.on('change', updateMapFromCoordinates);

        // Set marker if location exists
        if (step.lat && step.lng) {
            console.log('[Tour Builder] Setting marker for existing location:', step.lat, step.lng);
            updateMarker(L.latLng(step.lat, step.lng));
        }

        // Media library handlers
        $modal.on('click', '[data-media-select]', function(e) {
            e.preventDefault();
            const type = $(this).data('media-select');
            const $input = $(this).siblings('input');
            openMediaLibrary(type, $input);
        });

        // Save handler
        $modal.on('click', '[data-save-step]', function() {
            console.log('[Tour Builder] Save button clicked');

            // Get values from form
            const videoUrl = $modal.find('[name="video_url"]').val();
            const audioUrl = $modal.find('[name="audio_url"]').val();
            const imageUrl = $modal.find('[name="image_url"]').val();
            const heygenVideoUrl = $modal.find('[name="heygen_video_url"]').val();
            const latValue = parseFloat($modal.find('[name="location_lat"]').val());
            const lngValue = parseFloat($modal.find('[name="location_lng"]').val());
            const altitudeValue = normaliseAltitude($modal.find('[name="location_altitude_m"]').val());
            const areaValue = String($modal.find('[name="location_area"]').val() || '').trim();
            const locationTypeValue = String($modal.find('[name="location_type"]').val() || '').trim();
            const manualLabel = String($modal.find('[name="location_label"]').val() || '').trim();
            const missionChallengeValue = String($modal.find('[name="mission_challenge"]').val() || '').trim();
            const missionClueValue = String($modal.find('[name="mission_clue"]').val() || '').trim();
            const missionRevealValue = String($modal.find('[name="mission_reveal"]').val() || '').trim();

            console.log('[Tour Builder] Form values:', {
                video_url: videoUrl,
                audio_url: audioUrl,
                image_url: imageUrl,
                heygen_video_url: heygenVideoUrl,
                lat: latValue,
                lng: lngValue,
                altitude_m: altitudeValue,
                area: areaValue,
                location_type: locationTypeValue,
                location_label: manualLabel,
                mission_challenge: missionChallengeValue,
                mission_clue: missionClueValue,
                mission_reveal: missionRevealValue
            });

            const updatedStep = {
                ...state.steps[index],
                title: $modal.find('[name="title"]').val(),
                type: $modal.find('[name="type"]').val(),
                content: $modal.find('[name="content"]').val(),
                video_url: videoUrl,
                audio_url: audioUrl,
                image_url: imageUrl,
                heygen_video_url: heygenVideoUrl,
                altitude_m: altitudeValue,
                area: areaValue,
                locationType: locationTypeValue,
                missionChallenge: missionChallengeValue,
                missionClue: missionClueValue,
                missionReveal: missionRevealValue,
                points: parseInt($modal.find('[name="points"]').val()) || 0
            };
            updatedStep.gamification = buildGamificationPayload(updatedStep);

            if (Number.isFinite(latValue) && Number.isFinite(lngValue)) {
                updatedStep.lat = latValue;
                updatedStep.lng = lngValue;
                if (manualLabel) {
                    updatedStep.locationLabel = manualLabel;
                    updatedStep.locationLabelAuto = false;
                } else {
                    updatedStep.locationLabel = '';
                    updatedStep.locationLabelAuto = true;
                    ensureLocationLabel(index, latValue, lngValue);
                }

                if (state.map && typeof L !== 'undefined') {
                    updateMarker(L.latLng(latValue, lngValue));
                }
            } else if (manualLabel) {
                updatedStep.locationLabel = manualLabel;
                updatedStep.locationLabelAuto = false;
            }

            state.steps[index] = updatedStep;
            if (Number.isFinite(latValue) && Number.isFinite(lngValue) && state.map && typeof L !== 'undefined') {
                updateMarker(L.latLng(latValue, lngValue));
            }
            updateLocationDisplay(index);

            console.log('[Tour Builder] Step saved to state:', state.steps[index]);

            // Save blueprint to hidden field immediately
            saveBlueprint();
            
            // Trigger WordPress "unsaved changes" warning
            if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                try {
                    wp.data.dispatch('core/editor').editPost({ meta: { _modified: Date.now() } });
                } catch (e) {
                    // Classic editor or not Gutenberg
                }
            }
            
            // Show save reminder
            const $publishBtn = $('#publish, #save-post');
            if ($publishBtn.length) {
                $publishBtn.addClass('button-primary-highlight');
                setTimeout(() => $publishBtn.removeClass('button-primary-highlight'), 3000);
            }

            state.activeStepIndex = null;
            if (state.currentMarker && state.map) {
                state.map.removeLayer(state.currentMarker);
                state.currentMarker = null;
            }

            renderStepList($builder);
            renderStepMarkers();
            $modal.remove();
            
            // Show save notification
            showSaveNotification();
        });

        // Cancel/Close handlers
        $modal.on('click', '.sbdp-modal__close, [data-cancel]', function(e) {
            e.stopPropagation();
            console.log('[Tour Builder] Cancel/Close clicked');
            
            state.activeStepIndex = null;
            if (state.currentMarker && state.map) {
                state.map.removeLayer(state.currentMarker);
                state.currentMarker = null;
            }
            $modal.remove();
        });

        $modal.on('click', function(e) {
            if ($(e.target).is('.sbdp-modal-overlay')) {
                console.log('[Tour Builder] Overlay clicked, closing editor');
                
                state.activeStepIndex = null;
                if (state.currentMarker && state.map) {
                    state.map.removeLayer(state.currentMarker);
                    state.currentMarker = null;
                }
                $modal.remove();
            }
        });
    }

    /**
     * Open WordPress media library
     */
    function openMediaLibrary(type, $input) {
        if (typeof wp === 'undefined' || !wp.media) {
            alert('Media library niet beschikbaar');
            return;
        }

        const frame = wp.media({
            title: 'Selecteer Media',
            button: { text: 'Gebruik dit bestand' },
            multiple: false,
            library: { type: type === 'image' ? 'image' : type }
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url);
        });

        frame.open();
    }

    /**
     * Duplicate step
     */
    function duplicateStep(index, $builder) {
        const original = state.steps[index];
        if (!original) return;

        const duplicate = {
            ...original,
            id: Date.now(),
            title: original.title + ' (kopie)'
        };

        state.steps.splice(index + 1, 0, duplicate);
        renderStepList($builder);
        saveBlueprint();
    }

    /**
     * Delete step
     */
    function deleteStep(index, $builder) {
        state.steps.splice(index, 1);
        renderStepList($builder);
        renderStepMarkers();
        saveBlueprint();
    }

    /**
     * Render step list
     */
    function renderStepList($builder) {
        const $list = $builder.find('[data-builder-list]');
        const $empty = $builder.find('[data-builder-empty]');

        if (state.steps.length === 0) {
            $list.empty();
            $empty.show();
            return;
        }

        $empty.hide();

        const html = state.steps.map((step, index) => {
            const icons = {
                audio: '🎵',
                video: '🎬',
                vr: '🥽',
                game: '🎮',
                text: '📝'
            };
            const gamification = parseGamificationPayload(step.gamification || '');
            const locationText = formatLocationText(step);
            const typeLabel = step.type || 'text';
            const missionLabel = step.missionChallenge || gamification.challenge ? 'Missie klaar' : 'Nog geen missie';
            const missionState = step.missionChallenge || gamification.challenge ? 'is-ready' : 'is-empty';
            const contentState = String(step.content || '').trim() !== '' ? 'Verhaal ingevuld' : 'Nog geen verhaal';

            return `
                <div class="sbdp-step-card" data-step-index="${index}">
                    <span class="sbdp-step-card__handle" aria-hidden="true">☰</span>
                    <span class="sbdp-step-card__number">${index + 1}</span>
                    <span class="sbdp-step-card__icon">${icons[step.type] || '📄'}</span>
                    <div class="sbdp-step-card__content">
                        <div class="sbdp-step-card__topline">
                            <strong>${escapeHtml(step.title || 'Naamloze stop')}</strong>
                            <span class="sbdp-step-card__type">${escapeHtml(typeLabel)}</span>
                        </div>
                        <small data-step-location>${locationText}</small>
                        <div class="sbdp-step-card__meta">
                            <span class="sbdp-step-card__status">${escapeHtml(contentState)}</span>
                            <span class="sbdp-step-card__status ${missionState}">${escapeHtml(missionLabel)}</span>
                        </div>
                    </div>
                    <span class="sbdp-step-card__points">${step.points || 0} pts</span>
                    <div class="sbdp-step-card__actions">
                        <button type="button" class="button button-secondary button-small" data-step-edit title="Bewerken">Bewerken</button>
                        <button type="button" class="button button-small" data-step-duplicate title="Dupliceren">Kopie</button>
                        <button type="button" class="button button-small button-link-delete" data-step-delete title="Verwijderen">Verwijder</button>
                    </div>
                </div>
            `;
        }).join('');

        $list.html(html);
    }

    /**
     * Make list sortable
     */
    function makeSortable($builder) {
        const $list = $builder.find('[data-builder-list]');

        if (typeof $.fn.sortable === 'undefined') {
            console.warn('[Tour Builder] jQuery UI Sortable not available');
            return;
        }

        $list.sortable({
            handle: '.sbdp-step-card__handle',
            placeholder: 'sbdp-step-card-placeholder',
            update: function() {
                // Update order in state
                const newOrder = [];
                $list.find('[data-step-index]').each(function() {
                    const oldIndex = $(this).data('step-index');
                    newOrder.push(state.steps[oldIndex]);
                });
                state.steps = newOrder;
                renderStepList($builder);
            }
        });
    }

    /**
     * Save blueprint to hidden field
     */
    function saveBlueprint() {
        console.log('[Tour Builder] saveBlueprint() called');
        console.log('[Tour Builder] Current state.steps:', JSON.parse(JSON.stringify(state.steps)));
        
        // Convert steps to format that PHP expects (camelCase for compatibility)
        const stepsForBackend = state.steps.map(step => ({
            id: step.id,
            title: step.title,
            content: step.content,
            type: step.type,
            points: step.points,
            lat: step.lat,
            lng: step.lng,
            altitudeM: step.altitude_m,
            area: step.area || '',
            locationType: step.locationType || '',
            // expose location label for backend storage
            locationLabel: step.locationLabel || '',
            // Backend expects camelCase
            videoUrl: step.video_url || '',
            audioUrl: step.audio_url || '',
            imageUrl: step.image_url || '',
            heygenVideoUrl: step.heygen_video_url || '',
            vrAsset: step.vr_asset || '',
            templateId: step.template_id || 0,
            missionChallenge: step.missionChallenge || '',
            missionClue: step.missionClue || '',
            missionReveal: step.missionReveal || '',
            gamification: buildGamificationPayload(step),
        }));
        
        console.log('[Tour Builder] stepsForBackend (camelCase):', JSON.parse(JSON.stringify(stepsForBackend)));
        
        const blueprint = {
            version: '1.0',
            steps: stepsForBackend
        };

        const jsonString = JSON.stringify(blueprint);
        $('#sbdp_tour_blueprint').val(jsonString);
        
        console.log('[Tour Builder] Blueprint saved with', stepsForBackend.length, 'steps');
        console.log('[Tour Builder] First step:', stepsForBackend[0]);
        console.log('[Tour Builder] Hidden field value length:', jsonString.length);
    }
    
    /**
     * Show notification to save the post
     */
    function showSaveNotification() {
        // Remove existing notification
        $('.sbdp-save-notification').remove();
        
        const $notification = $(`
            <div class="sbdp-save-notification" style="
                position: fixed;
                top: 32px;
                right: 20px;
                background: #2271b1;
                color: white;
                padding: 12px 20px;
                border-radius: 4px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                z-index: 999999;
                font-size: 14px;
                animation: slideIn 0.3s ease-out;
            ">
                ✓ Wijzigingen opgeslagen in builder. <strong>Klik op "Bijwerken"</strong> om permanent op te slaan.
            </div>
            <style>
                @keyframes slideIn {
                    from { transform: translateX(400px); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(400px); opacity: 0; }
                }
            </style>
        `);
        
        $('body').append($notification);
        
        // Fade out after 5 seconds
        setTimeout(() => {
            $notification.css('animation', 'slideOut 0.3s ease-in');
            setTimeout(() => $notification.remove(), 300);
        }, 5000);
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
