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
	prevButton.textContent = 'Vorige hoofdstuk';

	const nextButton = document.createElement('button');
	nextButton.type = 'button';
	nextButton.className = 'button button-primary';
	nextButton.textContent = 'Volgende hoofdstuk';

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
			`<div class="sbdp-portal__media sbdp-portal__media--heygen"><div class="sbdp-portal__video-frame" style="position:relative;width:100%;padding-bottom:56.25%;overflow:hidden;"><iframe src="${heygenUrl}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" allowfullscreen allow="autoplay; fullscreen"></iframe></div></div>`
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
		meta.textContent = stats.join(' • ');
	}

	const login = root.querySelector(SELECTORS.login);
	const session = root.querySelector(SELECTORS.session);
	if (login && session) {
		login.hidden = true;
		session.hidden = false;
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
		ui: buildWizard(stepsContainer),
	};

	const tokenField = form.querySelector("input[name='ticket']");
	const emailField = form.querySelector("input[name='email']");
	const submitButton = form.querySelector("button[type='submit']");
	const params = new URLSearchParams(window.location.search);
	const previewToken = params.get('sbdp_preview_token');
	const ticketToken = params.get('ticket');

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
			updateWizard();
			clearMessage(messageEl);

			if (localState.previewTokenUsed) {
				const localParams = new URLSearchParams(window.location.search);
				if (localParams.has('sbdp_preview_token')) {
					localParams.delete('sbdp_preview_token');
					const newQuery = localParams.toString();
					const newUrl = `${window.location.pathname}${newQuery ? `?${newQuery}` : ''}${window.location.hash}`;
					window.history.replaceState({}, '', newUrl);
				}
				localState.previewTokenUsed = false;
			}
		} catch (error) {
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
			state.ui.nextButton.textContent = 'Volgende hoofdstuk';
			return;
		}

		if (lastStep) {
			if (currentStatus.completed) {
				state.ui.nextButton.disabled = true;
				state.ui.nextButton.textContent = 'Tour afgerond';
			} else {
				state.ui.nextButton.disabled = false;
				state.ui.nextButton.textContent = 'Rond hoofdstuk af';
			}
			return;
		}

		state.ui.nextButton.disabled = false;
		state.ui.nextButton.textContent = currentStatus.completed ? 'Volgende hoofdstuk' : 'Markeer als voltooid';
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
