const SELECTORS = {
	component: "[data-component='sbdp-private-tour-portal']",
	form: '.sbdp-portal__form',
	messages: '.sbdp-portal__messages',
	login: '.sbdp-portal__login',
	session: '.sbdp-portal__session',
	title: '.sbdp-portal__title',
	summary: '.sbdp-portal__summary',
	meta: '.sbdp-portal__meta',
	progressFill: '.sbdp-portal__progress-fill',
	progressNext: '.sbdp-portal__progress-next',
	progressCount: '.sbdp-portal__progress-count',
	steps: '[data-steps]',
};

function sanitiseBase(value) {
	if (typeof value !== 'string') {
		return '';
	}

	return value.replace(/\/+$/, '');
}

const API_BASE = sanitiseBase(window.sbdpPrivateTours?.apiBase);
const LEGACY_API_BASE = sanitiseBase(window.sbdpPrivateTours?.legacyApiBase);
const NONCE = window.sbdpPrivateTours?.nonce || '';

function normalizeHeygenUrl(value) {
	if (!value) {
		return '';
	}

	try {
		const parsed = new URL(String(value), window.location.origin);
		if (parsed.hostname !== 'app.heygen.com') {
			return '';
		}

		const segments = parsed.pathname.split('/').filter(Boolean);
		if (segments.length < 2) {
			return '';
		}

		const context = segments[0].toLowerCase();
		const id = segments[1];
		if (!['embeds', 'share', 'videos'].includes(context) || !/^[A-Za-z0-9_-]+$/.test(id)) {
			return '';
		}

		const query = parsed.search && parsed.search !== '?' ? parsed.search : '';
		return `https://app.heygen.com/embeds/${encodeURIComponent(id)}${query}`;
	} catch (error) {
		return '';
	}
}

function escapeHtml(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
}

function looksLikeInternalToken(value) {
	const raw = String(value || '').trim();
	if (!raw || raw.length > 64) {
		return false;
	}

	if (/[.!?]/.test(raw) || /[A-Z]/.test(raw)) {
		return false;
	}

	return /^[a-z0-9_-]+$/.test(raw);
}

function normalizeDisplayText(value, fallback = '') {
	const raw = String(value || '')
		.replace(/[_-]+/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();

	if (!raw) {
		return fallback;
	}

	if (looksLikeInternalToken(raw)) {
		return fallback;
	}

	if (/^[a-z0-9 ]+$/i.test(raw) && raw === raw.toLowerCase()) {
		return raw.charAt(0).toUpperCase() + raw.slice(1);
	}

	return raw;
}

function formatAccessUntil(value) {
	if (!value) {
		return '';
	}

	const normalized = String(value).trim().replace(' ', 'T');
	const date = new Date(`${normalized.endsWith('Z') ? normalized : `${normalized}Z`}`);
	if (Number.isNaN(date.getTime())) {
		return '';
	}

	return new Intl.DateTimeFormat('nl-NL', {
		day: 'numeric',
		month: 'short',
		hour: '2-digit',
		minute: '2-digit',
	}).format(date);
}

function toFiniteNumber(value) {
	const number = Number(value);
	return Number.isFinite(number) ? number : null;
}

function hasStepCoordinates(step) {
	return toFiniteNumber(step?.lat) !== null && toFiniteNumber(step?.lng) !== null;
}

function formatDistance(meters) {
	const safeMeters = Number.isFinite(meters) ? Math.max(0, meters) : 0;
	return safeMeters >= 1000 ? `${(safeMeters / 1000).toFixed(1)} km` : `${Math.round(safeMeters)} m`;
}

function formatDuration(seconds) {
	const minutes = Math.max(0, Math.round((Number(seconds) || 0) / 60));
	if (minutes < 1) {
		return '< 1 min';
	}
	if (minutes >= 60) {
		const hours = Math.floor(minutes / 60);
		const rest = minutes % 60;
		return rest ? `${hours}u ${rest}m` : `${hours} uur`;
	}
	return `${minutes} min`;
}

function haversineMeters(a, b) {
	if (!a || !b) {
		return null;
	}

	const earthRadius = 6371000;
	const dLat = ((b.lat - a.lat) * Math.PI) / 180;
	const dLng = ((b.lng - a.lng) * Math.PI) / 180;
	const sinLat = Math.sin(dLat / 2);
	const sinLng = Math.sin(dLng / 2);
	const value =
		sinLat * sinLat +
		Math.cos((a.lat * Math.PI) / 180) * Math.cos((b.lat * Math.PI) / 180) * sinLng * sinLng;
	return earthRadius * (2 * Math.atan2(Math.sqrt(value), Math.sqrt(Math.max(0, 1 - value))));
}

function getArrivalState(distance) {
	if (!Number.isFinite(distance)) {
		return { key: 'idle', label: 'Start GPS voor loopnavigatie.' };
	}
	if (distance <= 18) {
		return { key: 'arrived', label: `Bestemming bereikt. Nog ${formatDistance(distance)}.` };
	}
	if (distance <= 35) {
		return { key: 'almost', label: `Je bent bijna bij de stop. Nog ${formatDistance(distance)}.` };
	}
	if (distance <= 80) {
		return { key: 'near', label: `Dichtbij. Nog ${formatDistance(distance)}.` };
	}
	return { key: 'walking', label: `Loop naar deze stop. Nog ${formatDistance(distance)}.` };
}

function buildRouteEndpoint(from, to) {
	const endpoint = new URL(`${API_BASE}/navigation/route`, window.location.origin);
	endpoint.searchParams.set('fromLat', Number(from.lat).toFixed(5));
	endpoint.searchParams.set('fromLng', Number(from.lng).toFixed(5));
	endpoint.searchParams.set('toLat', Number(to.lat).toFixed(5));
	endpoint.searchParams.set('toLng', Number(to.lng).toFixed(5));
	endpoint.searchParams.set('profile', 'walking');
	return endpoint.toString();
}

function renderRoutePanel(step, index, steps) {
	if (!hasStepCoordinates(step)) {
		return '';
	}

	const nextStep = steps[index + 1] || null;
	const targetLabel = getStepLocationLabel(step) || step.title || `Stop ${index + 1}`;
	const nextLabel = nextStep ? (getStepLocationLabel(nextStep) || nextStep.title || `Stop ${index + 2}`) : '';
	const lat = toFiniteNumber(step.lat);
	const lng = toFiniteNumber(step.lng);

	return `
		<section class="sbdp-portal__route-panel" data-portal-route-panel data-target-lat="${lat}" data-target-lng="${lng}" data-target-label="${escapeHtml(targetLabel)}">
			<div class="sbdp-portal__route-copy">
				<p class="sbdp-portal__route-eyebrow">Loopnavigatie</p>
				<h4>${escapeHtml(targetLabel)}</h4>
				<p class="sbdp-portal__route-status" data-portal-route-status>Start GPS om afstand en aankomst te volgen.</p>
				${nextLabel ? `<p class="sbdp-portal__route-next">Hierna: ${escapeHtml(nextLabel)}</p>` : '<p class="sbdp-portal__route-next">Laatste stop van de tour.</p>'}
			</div>
			<div class="sbdp-portal__route-actions">
				<button type="button" class="button button-primary" data-portal-start-gps>Start GPS</button>
				<button type="button" class="button button-secondary" data-portal-arrived>Ik ben aangekomen</button>
			</div>
			<div class="sbdp-portal__route-map" data-portal-route-map aria-label="Routekaart naar deze stop"></div>
		</section>
	`;
}

/**
 * Execute a REST call against the private tour API.
 *
 * @param {string} endpoint Relative endpoint.
 * @param {RequestInit} options Fetch options.
 *
 * @returns {Promise<any>}
 */
async function request(endpoint, options = {}, attempt = 0) {
	const headers = new Headers(options.headers || {});

	if (!headers.has('Content-Type') && options.body) {
		headers.set('Content-Type', 'application/json');
	}

	if (NONCE) {
		headers.set('X-WP-Nonce', NONCE);
	}

	const base = attempt === 0 ? API_BASE : LEGACY_API_BASE;
	if (!base) {
		throw new Error('API base is not configured.');
	}

	const response = await fetch(`${base}${endpoint}`, {
		credentials: 'same-origin',
		...options,
		headers,
	});

	if (!response.ok) {
		let message = response.statusText;
		try {
			const data = await response.json();
			message = data?.message || message;
		} catch (e) {
			// Ignore parse failure and fall back to status text.
		}
		const error = new Error(message);
		error.status = response.status;

		if (response.status === 404 && attempt === 0 && LEGACY_API_BASE && LEGACY_API_BASE !== base) {
			return request(endpoint, options, 1);
		}

		throw error;
	}

	if (response.status === 204) {
		return null;
	}

	return response.json();
}

/**
 * Render flash message inside the portal.
 *
 * @param {HTMLElement} container Message container.
 * @param {string} text Message content.
 * @param {'error'|'success'} variant Visual variant.
 */
function renderMessage(container, text, variant = 'success') {
	if (!container) {
		return;
	}

	container.textContent = text;
	container.dataset.variant = variant;
	container.hidden = false;
}

/**
 * Clear current message state.
 *
 * @param {HTMLElement} container Message container.
 */
function clearMessage(container) {
	if (!container) {
		return;
	}
	container.textContent = '';
	container.hidden = true;
	delete container.dataset.variant;
}

/**
 * Render step cards with progress actions.
 *
 * @param {HTMLElement} target List target.
 * @param {Array} steps Step definitions.
 * @param {Object} progress Current progress map.
 * @param {Function} onToggle Callback for completion changes.
 */
/**
 * Calculate unlock/completion state for each step.
 *
 * @param {Array} steps Step definitions.
 * @param {Object} progress Progress map keyed by step ID.
 *
 * @returns {Array<{completed:boolean, unlocked:boolean}>}
 */
function computeStepStatuses(steps, progress) {
	const statuses = [];
	let previousCompleted = true;

	steps.forEach((step, index) => {
		const record = progress[step.id] || {};
		const completed = Boolean(record.completed);
		const unlocked = index === 0 ? true : previousCompleted;
		statuses.push({ completed, unlocked });
		previousCompleted = completed && unlocked;
	});

	return statuses;
}

/**
 * Create wizard DOM structure inside the container.
 *
 * @param {HTMLElement} container Wizard container.
 *
 * @returns {{root:HTMLElement, nav:HTMLElement, content:HTMLElement, controls:HTMLElement, prevButton:HTMLButtonElement, nextButton:HTMLButtonElement}}
 */
function buildWizard(container) {
	container.innerHTML = '';

	const root = document.createElement('div');
	root.className = 'sbdp-portal__wizard';

	const layout = document.createElement('div');
	layout.className = 'sbdp-portal__wizard-layout';

	const nav = document.createElement('ol');
	nav.className = 'sbdp-portal__wizard-nav';

	const content = document.createElement('div');
	content.className = 'sbdp-portal__wizard-content';

	const controls = document.createElement('div');
	controls.className = 'sbdp-portal__wizard-controls';

	const prevButton = document.createElement('button');
	prevButton.type = 'button';
	prevButton.className = 'button button-secondary';
	prevButton.textContent = 'Vorig hoofdstuk';

	const nextButton = document.createElement('button');
	nextButton.type = 'button';
	nextButton.className = 'button button-primary';
	nextButton.textContent = 'Volgend hoofdstuk';

	controls.appendChild(prevButton);
	controls.appendChild(nextButton);

	const navWrapper = document.createElement('div');
	navWrapper.className = 'sbdp-portal__wizard-nav-wrapper';
	navWrapper.appendChild(nav);

	const contentWrapper = document.createElement('div');
	contentWrapper.className = 'sbdp-portal__wizard-content-wrapper';
	contentWrapper.appendChild(content);
	contentWrapper.appendChild(controls);

	layout.appendChild(navWrapper);
	layout.appendChild(contentWrapper);
	root.appendChild(layout);
	container.appendChild(root);

	return {
		root,
		nav,
		content,
		controls,
		prevButton,
		nextButton,
	};
}

/**
 * Render optional media links for steps.
 *
 * @param {Object} step Step details.
 *
 * @returns {string}
 */
function renderMediaLinks(step) {
	const media = [];
	const blocks = [];
	const mappedHeygenUrl = normalizeHeygenUrl(step.heygenEmbedUrl || step.heygenVideoUrl || step.heygen_video_url || '');
	const fallbackHeygenFromVideo = mappedHeygenUrl ? '' : normalizeHeygenUrl(step.videoUrl || step.video_url || '');
	const heygenUrl = mappedHeygenUrl || fallbackHeygenFromVideo;

	if (step.mediaUrl && !fallbackHeygenFromVideo) {
		media.push(
			`<a class="sbdp-portal__media-link" href="${step.mediaUrl}" target="_blank" rel="noopener">
				<span class="dashicons dashicons-format-video"></span> Media
			</a>`
		);
	}

	if (step.vrAsset) {
		media.push(
			`<a class="sbdp-portal__media-link" href="${step.vrAsset}" target="_blank" rel="noopener">
				<span class="dashicons dashicons-visibility"></span> VR / AR
			</a>`
		);
	}

	if (media.length) {
		blocks.push(`<div class="sbdp-portal__media">${media.join(' ')}</div>`);
	}

	if (heygenUrl) {
		blocks.push(
			`<div class="sbdp-portal__media sbdp-portal__media--heygen"><div class="sbdp-portal__video-frame"><iframe src="${heygenUrl}" allowfullscreen allow="autoplay; fullscreen"></iframe></div></div>`
		);
	}

	if (!blocks.length) {
		return '';
	}

	return blocks.join('');
}

function getStepLocationLabel(step) {
	if (!step) {
		return '';
	}

	if (step.locationLabel) {
		return step.locationLabel;
	}

	const lat = Number.isFinite(step.lat) ? step.lat : null;
	const lng = Number.isFinite(step.lng) ? step.lng : null;

	if (lat !== null && lng !== null) {
		return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
	}

	return '';
 }

/**
 * Render gamification hints.
 *
 * @param {Object} gamification Gamification payload.
 * @param {number} points Points awarded.
 *
 * @returns {string}
 */
function renderGamification(gamification, points) {
	const details = [];

	if (points) {
		details.push(`<span class="sbdp-portal__badge">${points} XP</span>`);
	}

	if (gamification && typeof gamification === 'object') {
		if (gamification.challenge) {
			const challenge = normalizeDisplayText(gamification.challenge, 'Missie');
			if (challenge) {
				details.push(`<span class="sbdp-portal__tag">${escapeHtml(challenge)}</span>`);
			}
		} else if (gamification.mode) {
			const mode = normalizeDisplayText(gamification.mode, 'Opdracht');
			if (mode) {
				details.push(`<span class="sbdp-portal__tag">${escapeHtml(mode)}</span>`);
			}
		}
	}

	if (!details.length) {
		return '';
	}

	return `<div class="sbdp-portal__gamification">${details.join(' ')}</div>`;
}

/**
 * Update general tour info.
 *
 * @param {HTMLElement} root Component root.
 * @param {Object} payload Session payload.
 */
function renderSession(root, payload) {
	const title = root.querySelector(SELECTORS.title);
	const summary = root.querySelector(SELECTORS.summary);
	const meta = root.querySelector(SELECTORS.meta);

	if (title) {
		title.textContent = payload.tour.title;
	}

	if (summary) {
		summary.innerHTML = payload.tour.summary;
	}

	if (meta) {
		const stats = [];
		if (payload.tour.duration) {
			stats.push(`${payload.tour.duration} min.`);
		}
		if (payload.steps.length) {
			stats.push(`${payload.steps.length} stappen`);
		}
		if (payload.tour.visibility && payload.tour.visibility.label) {
			stats.push(payload.tour.visibility.label);
		}
		const contactEmail = payload.tour.contact?.email || payload.tour.supportMail;
		if (contactEmail) {
			stats.push(contactEmail);
		}
		const accessUntil = formatAccessUntil(payload.ticket?.accessExpiresAt);
		if (accessUntil) {
			stats.push(`Geldig tot ${accessUntil}`);
		}
		meta.innerHTML = stats.map((item) => `<span>${escapeHtml(item)}</span>`).join('');
	}

	const login = root.querySelector(SELECTORS.login);
	const session = root.querySelector(SELECTORS.session);
	if (login && session) {
		login.hidden = true;
		session.hidden = false;
		root.dataset.sessionActive = 'true';
		document.body.classList.add('sbdp-private-tour-portal-active');
	}
}

function updateProgressSummary(root, state) {
	const fill = root.querySelector(SELECTORS.progressFill);
	const next = root.querySelector(SELECTORS.progressNext);
	const count = root.querySelector(SELECTORS.progressCount);

	if (!fill && !next && !count) {
		return;
	}

	const statuses = computeStepStatuses(state.steps, state.progress);
	const total = state.steps.length;
	const completedCount = statuses.filter((status) => status.completed).length;
	const progressRatio = total ? Math.round((completedCount / total) * 100) : 0;
	const upcomingIndex = statuses.findIndex((status) => status.unlocked && !status.completed);
	const upcomingStep = upcomingIndex >= 0 ? state.steps[upcomingIndex] : null;

	if (fill) {
		fill.style.width = `${progressRatio}%`;
	}

	if (next) {
		next.textContent = upcomingStep
			? `Volgende: ${upcomingStep.title || `Hoofdstuk ${upcomingIndex + 1}`}`
			: 'Alle hoofdstukken afgerond';
	}

	if (count) {
		count.textContent = total
			? `${completedCount}/${total} afgerond`
			: 'Nog geen hoofdstukken';
	}
}

/**
 * Attach behaviour to a single portal instance.
 *
 * @param {HTMLElement} root Component root.
 */
function mountPortal(root) {
	const form = root.querySelector(SELECTORS.form);
	const messages = root.querySelector(SELECTORS.messages);
	const stepsContainer = root.querySelector(SELECTORS.steps);

	if (!form || !stepsContainer) {
		return;
	}

	const state = {
		session: '',
		progress: {},
		steps: [],
		activeIndex: 0,
		previewTokenUsed: false,
		geo: {
			watchId: null,
			current: null,
			map: null,
			routeLayer: null,
			userMarker: null,
			targetMarker: null,
			lastRouteUrl: '',
		},
		ui: buildWizard(stepsContainer),
	};

	const tokenField = form.querySelector("input[name='ticket']");
	const emailField = form.querySelector("input[name='email']");
	const submitButton = form.querySelector("button[type='submit']");
	const params = new URLSearchParams(window.location.search);
	const previewToken = params.get('sbdp_preview_token');
	const ticketToken = params.get('ticket');
	const sessionStorageKey = 'sbdp_private_tour_session';

	state.ui.prevButton.addEventListener('click', () => {
		setActiveIndex(state.activeIndex - 1);
	});

	state.ui.nextButton.addEventListener('click', () => {
		handleNext();
	});

	state.ui.content.addEventListener('click', (event) => {
		const button = event.target.closest('[data-portal-video-toggle]');
		if (!button) {
			return;
		}

		const frameWrap = button.parentElement ? button.parentElement.querySelector('[data-portal-video-frame]') : null;
		if (!frameWrap) {
			return;
		}

		if (frameWrap.dataset.loaded !== '1') {
			const src = String(button.getAttribute('data-video-src') || '');
			if (!src) {
				return;
			}

			const iframe = document.createElement('iframe');
			iframe.src = src;
			iframe.width = '100%';
			iframe.height = '450';
			iframe.frameBorder = '0';
			iframe.allowFullscreen = true;
			iframe.loading = 'lazy';
			frameWrap.appendChild(iframe);
			frameWrap.dataset.loaded = '1';
		}

		frameWrap.hidden = false;
		button.hidden = true;
	});

	state.ui.content.addEventListener('click', (event) => {
		const gpsButton = event.target.closest('[data-portal-start-gps]');
		if (gpsButton) {
			startGpsTracking(gpsButton);
			return;
		}

		const arrivedButton = event.target.closest('[data-portal-arrived]');
		if (arrivedButton) {
			handleNext();
		}
	});

	if (previewToken && tokenField) {
		tokenField.value = previewToken;
		if (emailField) {
			emailField.value = '';
		}
		state.previewTokenUsed = true;
		renderMessage(messages, 'Previewtoken geladen. We loggen je direct in...', 'success');
		setTimeout(() => {
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit();
			} else {
				form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
			}
		}, 150);
	}

	if (!previewToken && ticketToken && tokenField) {
		tokenField.value = ticketToken;
		renderMessage(messages, 'Ticketlink geladen. Vul indien gevraagd het e-mailadres van je bestelling in en start de tour.', 'success');
	}

	if (!previewToken && !ticketToken) {
		const storedSession = String(window.sessionStorage.getItem(sessionStorageKey) || '');
		if (storedSession) {
			state.session = storedSession;
			loadSession(root, stepsContainer, messages, state).catch(() => {
				window.sessionStorage.removeItem(sessionStorageKey);
			});
		}
	}

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		clearMessage(messages);

		const formData = new FormData(form);
		const token = String(formData.get('ticket') || '').trim();
		const email = String(formData.get('email') || '').trim();

		if (!token) {
			renderMessage(messages, 'Voer je ticketcode in.', 'error');
			return;
		}

		if (submitButton) {
			submitButton.disabled = true;
		}

		try {
			const session = await request('/session', {
				method: 'POST',
				body: JSON.stringify({
					token,
					email,
				}),
			});

			state.session = session.session;
			window.sessionStorage.setItem(sessionStorageKey, state.session);
			await loadSession(root, stepsContainer, messages, state);
			renderMessage(messages, 'Ticket gevalideerd. Veel plezier met de tour!', 'success');
			form.reset();
		} catch (error) {
			renderMessage(messages, error.message || 'Validatie mislukt.', 'error');
		} finally {
			if (submitButton) {
				submitButton.disabled = false;
			}
		}
	});

	async function loadSession(rootEl, stepsEl, messageEl, localState) {
		try {
			const payload = await request(`/session/${localState.session}`, {
				method: 'GET',
			});

			localState.progress = payload.progress || {};
			localState.steps = payload.steps || [];
			localState.activeIndex = determineInitialIndex();

			renderSession(rootEl, payload);
			renderCanonicalExperience(stepsEl, payload, localState);
			clearMessage(messageEl);

			if (localState.previewTokenUsed || ticketToken) {
				const localParams = new URLSearchParams(window.location.search);
				localParams.delete('sbdp_preview_token');
				localParams.delete('ticket');
				const newQuery = localParams.toString();
				const newUrl = `${window.location.pathname}${newQuery ? `?${newQuery}` : ''}${window.location.hash}`;
				window.history.replaceState({}, '', newUrl);
				localState.previewTokenUsed = false;
			}
		} catch (error) {
			if (localState.session) {
				window.sessionStorage.removeItem(sessionStorageKey);
			}
			renderMessage(messageEl, error.message || 'Kon sessie niet laden.', 'error');
		}
	}

	function computeStatuses() {
		return computeStepStatuses(state.steps, state.progress);
	}

	function determineInitialIndex() {
		const statuses = computeStatuses();
		const firstIncomplete = statuses.findIndex((status) => status.unlocked && !status.completed);
		if (firstIncomplete >= 0) {
			return firstIncomplete;
		}
		return state.steps.length > 0 ? state.steps.length - 1 : 0;
	}

	function setActiveIndex(index) {
		const statuses = computeStatuses();
		if (index < 0 || index >= state.steps.length) {
			return;
		}
		if (!statuses[index]?.unlocked) {
			return;
		}
		state.activeIndex = index;
		updateWizard();
	}

	function updateWizard() {
		const statuses = computeStatuses();
		state.ui.nav.innerHTML = '';

		state.steps.forEach((step, idx) => {
			const item = document.createElement('li');
			item.className = 'sbdp-wizard__nav-item';

			if (!statuses[idx]?.unlocked) {
				item.classList.add('is-locked');
			}

			if (statuses[idx]?.completed) {
				item.classList.add('is-complete');
			}

			if (idx === state.activeIndex) {
				item.classList.add('is-active');
			}

			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'sbdp-wizard__nav-button';
			button.textContent = step.title || `Hoofdstuk ${idx + 1}`;

			if (!statuses[idx]?.unlocked) {
				button.disabled = true;
				button.setAttribute('aria-disabled', 'true');
			} else {
				button.addEventListener('click', () => setActiveIndex(idx));
			}

			item.appendChild(button);
			state.ui.nav.appendChild(item);
		});

		renderActiveStep();
		updateControls();
		updateProgressSummary(root, state);
	}

	function renderCanonicalExperience(container, payload, localState) {
		const tour = payload && payload.tour ? payload.tour : {};
		root.classList.add('sbdp-private-tour-portal--canonical');
		document.body.classList.add('sbdp-is-tour-mode');
		const legacyHeader = root.querySelector('.sbdp-portal__header');
		if (legacyHeader) legacyHeader.hidden = true;
		const experience = document.createElement('div');
		experience.className = 'sbdp-tour-navigation sbdp-tour-navigation--experience';
		experience.setAttribute('data-tour-navigation', '');
		experience.dataset.tourId = String(tour.id || 0);
		experience.dataset.tourTitle = String(tour.title || 'Privétour');
		experience.dataset.tourSummary = String(tour.summary || '');
		experience.dataset.tourDuration = String(tour.duration || 0);
		experience.dataset.tourSupportEmail = String(tour.supportMail || '');
		experience.dataset.tourStepCount = String(localState.steps.length);
		experience.dataset.tourSteps = JSON.stringify(localState.steps);
		experience.dataset.ticketSession = String(localState.session);
		experience.dataset.ticketSessionApiBase = API_BASE;
		experience.dataset.ticketProgress = JSON.stringify(localState.progress || {});
		experience.innerHTML = `
			<div class="tour-shell tour-shell--guided">
				<section class="tour-summary-panel" data-tour-summary-panel></section>
				<div class="tour-shell__body">
					<aside class="tour-route-rail" data-tour-step-list></aside>
					<section class="tour-stage" aria-live="polite">
						<section class="tour-stage__panel tour-stage__panel--story" data-tour-story-panel></section>
						<section class="tour-stage__panel tour-stage__panel--navigation" data-tour-navigation-panel hidden>
							<div class="tour-navigation-layout">
								<section class="tour-navigation-map-panel" data-tour-map-panel>
									<div class="tour-map-meta" data-tour-map-meta></div>
									<div class="tour-map" data-tour-map></div>
									<p class="tour-map-status" data-tour-map-status aria-live="polite"></p>
								</section>
								<aside class="tour-navigation-sidebar" data-tour-navigation-copy></aside>
							</div>
						</section>
					</section>
				</div>
			</div>`;
		container.replaceChildren(experience);

		if (window.SBDPTourNavigation && typeof window.SBDPTourNavigation.mount === 'function') {
			window.SBDPTourNavigation.mount();
		} else {
			document.dispatchEvent(new CustomEvent('sbdp:tour-navigation:mount'));
		}
	}

	function renderActiveStep() {
		const step = state.steps[state.activeIndex];
		state.ui.content.innerHTML = '';

		if (!step) {
			const empty = document.createElement('div');
			empty.className = 'sbdp-wizard__empty';
			empty.textContent = 'Geen hoofdstukken beschikbaar.';
			state.ui.content.appendChild(empty);
			return;
		}

	const header = document.createElement('div');
	header.className = 'sbdp-wizard__step-header';

	const title = document.createElement('h3');
	title.textContent = step.title || `Hoofdstuk ${state.activeIndex + 1}`;
	header.appendChild(title);

	const locationText = getStepLocationLabel(step);
	const locationWrapper = document.createElement('p');
	locationWrapper.className = 'sbdp-portal__step-location';
	const locationIcon = document.createElement('span');
	locationIcon.className = 'sbdp-portal__step-location-icon';
	locationIcon.setAttribute('aria-hidden', 'true');
	locationIcon.textContent = '📍';
	const locationLabel = document.createElement('span');
	locationLabel.className = 'sbdp-portal__step-location-text';
	locationLabel.textContent = locationText || 'Locatie onbekend';
	locationWrapper.appendChild(locationIcon);
	locationWrapper.appendChild(locationLabel);
	header.appendChild(locationWrapper);

		const body = document.createElement('div');
		body.className = 'sbdp-wizard__step-body';

		const routeMarkup = renderRoutePanel(step, state.activeIndex, state.steps);
		if (routeMarkup) {
			body.insertAdjacentHTML('beforeend', routeMarkup);
			window.setTimeout(() => hydrateRoutePanel(), 0);
		}

		const mediaMarkup = renderMediaLinks(step);
		if (mediaMarkup) {
			body.insertAdjacentHTML('beforeend', mediaMarkup);
		}

		const gamificationMarkup = renderGamification(step.gamification, step.points);
		if (gamificationMarkup) {
			body.insertAdjacentHTML('beforeend', gamificationMarkup);
		}

		const content = document.createElement('div');
		content.className = 'sbdp-portal__step-content';
		content.innerHTML = step.content || '';
		body.appendChild(content);

		state.ui.content.appendChild(header);
		state.ui.content.appendChild(body);
	}

	function updateControls() {
		const statuses = computeStatuses();
		const currentStatus = statuses[state.activeIndex] || { unlocked: false, completed: false };

		state.ui.prevButton.disabled = state.activeIndex === 0;

		const lastStep = state.activeIndex === state.steps.length - 1;

		if (!currentStatus.unlocked) {
			state.ui.nextButton.disabled = true;
			state.ui.nextButton.textContent = 'Volgende stop';
			return;
		}

		if (lastStep) {
			if (currentStatus.completed) {
				state.ui.nextButton.disabled = true;
				state.ui.nextButton.textContent = 'Tour afgerond';
			} else {
				state.ui.nextButton.disabled = false;
				state.ui.nextButton.textContent = 'Tour afronden';
			}
			return;
		}

		state.ui.nextButton.disabled = false;
		state.ui.nextButton.textContent = 'Volgende stop';
	}

	async function handleNext() {
		const statuses = computeStatuses();
		const currentStatus = statuses[state.activeIndex];
		const step = state.steps[state.activeIndex];

		if (!step || !currentStatus?.unlocked) {
			return;
		}

		if (!currentStatus.completed) {
			const saved = await updateProgress(step, true);
			if (!saved) {
				return;
			}
		}

		const updatedStatuses = computeStatuses();

		if (state.activeIndex < state.steps.length - 1 && updatedStatuses[state.activeIndex + 1]?.unlocked) {
			setActiveIndex(state.activeIndex + 1);
		} else {
			updateWizard();
			if (state.activeIndex === state.steps.length - 1 && updatedStatuses[state.activeIndex]?.completed) {
				renderMessage(messages, 'Alle hoofdstukken zijn afgerond.', 'success');
			}
		}
	}

	function getActiveRoutePanel() {
		return state.ui.content.querySelector('[data-portal-route-panel]');
	}

	function getActiveTarget() {
		const panel = getActiveRoutePanel();
		if (!panel) {
			return null;
		}

		const lat = toFiniteNumber(panel.dataset.targetLat);
		const lng = toFiniteNumber(panel.dataset.targetLng);
		if (lat === null || lng === null) {
			return null;
		}

		return {
			lat,
			lng,
			label: panel.dataset.targetLabel || 'Deze stop',
		};
	}

	function hydrateRoutePanel() {
		const panel = getActiveRoutePanel();
		const target = getActiveTarget();
		if (!panel || !target) {
			return;
		}

		initRouteMap(panel, target);
		if (state.geo.current) {
			updateRoutePanel(state.geo.current);
		}
	}

	function initRouteMap(panel, target) {
		const mapElement = panel.querySelector('[data-portal-route-map]');
		if (!mapElement || !window.L) {
			return;
		}

		if (state.geo.map) {
			state.geo.map.remove();
			state.geo.map = null;
			state.geo.routeLayer = null;
			state.geo.userMarker = null;
			state.geo.targetMarker = null;
			state.geo.lastRouteUrl = '';
		}

		const map = window.L.map(mapElement, {
			attributionControl: false,
			dragging: true,
			scrollWheelZoom: false,
			tap: true,
		}).setView([target.lat, target.lng], 16);

		window.L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
			maxZoom: 20,
			attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
		}).addTo(map);

		state.geo.targetMarker = window.L.circleMarker([target.lat, target.lng], {
			radius: 8,
			color: '#d6a461',
			fillColor: '#d6a461',
			fillOpacity: 0.95,
			weight: 2,
		}).addTo(map);

		state.geo.map = map;
		window.setTimeout(() => map.invalidateSize(), 80);
	}

	function startGpsTracking(button) {
		const panel = getActiveRoutePanel();
		if (!panel) {
			return;
		}

		const status = panel.querySelector('[data-portal-route-status]');
		if (!window.isSecureContext || !navigator.geolocation) {
			if (status) {
				status.textContent = 'GPS is niet beschikbaar in deze browser. Gebruik de locatie op de kaart.';
			}
			return;
		}

		if (button) {
			button.disabled = true;
			button.textContent = 'GPS actief';
		}

		if (state.geo.watchId !== null) {
			navigator.geolocation.clearWatch(state.geo.watchId);
			state.geo.watchId = null;
		}

		state.geo.watchId = navigator.geolocation.watchPosition(
			(position) => {
				const current = {
					lat: position.coords.latitude,
					lng: position.coords.longitude,
					accuracy: position.coords.accuracy,
				};
				state.geo.current = current;
				updateRoutePanel(current);
			},
			() => {
				if (status) {
					status.textContent = 'GPS-toegang is geweigerd. Je kunt de stop nog steeds op de kaart volgen.';
				}
				if (button) {
					button.disabled = false;
					button.textContent = 'Start GPS';
				}
			},
			{
				enableHighAccuracy: true,
				maximumAge: 8000,
				timeout: 12000,
			}
		);
	}

	async function updateRoutePanel(current) {
		const panel = getActiveRoutePanel();
		const target = getActiveTarget();
		if (!panel || !target || !current) {
			return;
		}

		const status = panel.querySelector('[data-portal-route-status]');
		const distance = haversineMeters(current, target);
		const arrival = getArrivalState(distance);
		panel.dataset.arrivalState = arrival.key;
		if (status) {
			status.textContent = arrival.label;
		}

		updateRouteMap(current, target);
		await drawWalkingRoute(current, target, status);
	}

	function updateRouteMap(current, target) {
		if (!state.geo.map || !window.L) {
			return;
		}

		if (!state.geo.userMarker) {
			state.geo.userMarker = window.L.circleMarker([current.lat, current.lng], {
				radius: 7,
				color: '#72aee6',
				fillColor: '#72aee6',
				fillOpacity: 0.95,
				weight: 2,
			}).addTo(state.geo.map);
		} else {
			state.geo.userMarker.setLatLng([current.lat, current.lng]);
		}

		const bounds = window.L.latLngBounds([
			[current.lat, current.lng],
			[target.lat, target.lng],
		]);
		state.geo.map.fitBounds(bounds.pad(0.35), { animate: true, maxZoom: 17 });
	}

	async function drawWalkingRoute(current, target, status) {
		if (!state.geo.map || !window.L || !API_BASE) {
			return;
		}

		const requestUrl = buildRouteEndpoint(current, target);
		if (state.geo.lastRouteUrl === requestUrl) {
			return;
		}
		state.geo.lastRouteUrl = requestUrl;

		try {
			const response = await fetch(requestUrl, {
				credentials: 'same-origin',
				headers: NONCE ? { 'X-WP-Nonce': NONCE } : {},
			});
			if (!response.ok) {
				return;
			}

			const payload = await response.json();
			const path = Array.isArray(payload?.path) ? payload.path : [];
			if (path.length < 2) {
				return;
			}

			if (state.geo.routeLayer) {
				state.geo.routeLayer.remove();
			}

			state.geo.routeLayer = window.L.polyline(path, {
				color: '#d6a461',
				weight: 5,
				opacity: 0.9,
			}).addTo(state.geo.map);

			const routeDistance = Number(payload.distance || 0);
			const routeDuration = Number(payload.duration || 0);
			if (status && routeDistance > 0) {
				status.textContent = `Nog ${formatDistance(routeDistance)} • ${formatDuration(routeDuration)} lopen.`;
			}
		} catch (error) {
			// Keep the local GPS distance visible when route drawing fails.
		}
	}

	async function updateProgress(step, completed) {
		if (!state.session) {
			return false;
		}

		const current = state.progress[step.id];
		if (current && Boolean(current.completed) === completed) {
			return true;
		}

		try {
			const response = await request(`/session/${state.session}/progress`, {
				method: 'POST',
				body: JSON.stringify({
					stepId: step.id,
					completed,
				}),
			});

			state.progress[step.id] = response.progress;
			renderMessage(messages, 'Voortgang bijgewerkt.', 'success');
			return true;
		} catch (error) {
			renderMessage(messages, error.message || 'Kon voortgang niet opslaan.', 'error');
			return false;
		}
	}
}

/**
 * Bootstrap all portal instances on the page.
 */
function initPortal() {
	const components = document.querySelectorAll(SELECTORS.component);
	components.forEach((component) => mountPortal(component));
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initPortal, { once: true });
} else {
	initPortal();
}
