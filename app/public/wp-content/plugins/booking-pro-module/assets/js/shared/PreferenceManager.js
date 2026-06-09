/**
 * PreferenceManager - Central utility for managing user preferences
 * across Home Widget, Product Pages, and Plan Je Dag
 * 
 * Handles:
 * - Parameter mapping between different components
 * - Validation and sanitization
 * - Session storage with fallbacks
 * - Consistent data format
 */

const STORAGE_KEY = 'sbdp_user_preferences';
const LEGACY_STORAGE_KEY = 'sbdp_home_widget_prefill';
const STORAGE_EXPIRY_MS = 2 * 60 * 60 * 1000; // 2 hours

// Mapping tables for consistent parameter names
const STYLE_TO_VIBE_MAP = {
  'Bourgondisch genieten': 'bourgondisch',
  'Actief & buiten': 'actief',
  'Cultuur & historie': 'cultuur',
  'Met de kids': 'kidsproof',
  'Laat je verrassen': 'verrassend',
  // Fallback direct values
  'bourgondisch': 'bourgondisch',
  'actief': 'actief',
  'cultuur': 'cultuur',
  'mystiek': 'mystiek',
  'kidsproof': 'kidsproof',
  'verrassend': 'verrassend',
  'romantisch': 'romantisch',
  'gezellig': 'gezellig',
};

const AUDIENCE_MAP = {
  'Alleen': 'solo',
  'Stelletje': 'partner',
  'Familie': 'gezin',
  'Team / Groep': 'vrienden',
  'School / Bedrijf': 'collegas',
  // Fallback direct values
  'solo': 'solo',
  'partner': 'partner',
  'gezin': 'gezin',
  'familie': 'gezin',
  'vrienden': 'vrienden',
  'collegas': 'collegas',
  'bedrijf': 'collegas',
};

const DURATION_MAP = {
  'ochtend': 'ochtend',
  'middag': 'middag',
  'avond': 'avond',
  'hele-dag': 'hele-dag',
  'weekend': 'weekend',
  'halve-dag': 'middag', // fallback
  'full-day': 'hele-dag', // fallback
};

// Valid values for validation
const VALID_VIBES = Object.values(STYLE_TO_VIBE_MAP);
const VALID_AUDIENCES = Object.values(AUDIENCE_MAP);
const VALID_DURATIONS = Object.keys(DURATION_MAP);

class PreferenceManager {
  static firstValidCount(...values) {
    for (const value of values) {
      const normalized = this.normalizeCount(value);
      if (normalized) {
        return normalized;
      }
    }
    return null;
  }

  /**
   * Normalize preferences from any source (widget, product page, URL)
   * @param {Object} raw - Raw preference data
   * @returns {Object} Normalized preferences
   */
  static normalize(raw) {
    if (!raw || typeof raw !== 'object') {
      return null;
    }

    const normalized = {
      visitDate: this.normalizeDate(raw.visitDate || raw.date),
      count: this.firstValidCount(raw.count, raw.participants, raw.people),
      duration: this.normalizeDuration(raw.duration),
      audience: this.normalizeAudience(raw.audience),
      vibe: this.normalizeVibe(raw.vibe || raw.style),
      startActivity: raw.startActivity || raw.start || null,
    };

    // Only return if we have at least some valid data
    const hasValidData = normalized.visitDate || normalized.count || 
                         normalized.duration || normalized.audience || 
                         normalized.vibe;
    
    return hasValidData ? normalized : null;
  }

  /**
   * Normalize date to YYYY-MM-DD format
   */
  static normalizeDate(value) {
    if (!value) return null;

    // Already in correct format
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return value;
    }

    // Handle common date strings
    const today = new Date();
    const lowerValue = String(value).toLowerCase();

    if (lowerValue === 'vandaag' || lowerValue === 'today') {
      return this.formatDate(today);
    }

    if (lowerValue === 'morgen' || lowerValue === 'tomorrow') {
      const tomorrow = new Date(today);
      tomorrow.setDate(tomorrow.getDate() + 1);
      return this.formatDate(tomorrow);
    }

    if (lowerValue === 'dit weekend' || lowerValue === 'this weekend') {
      const daysUntilSaturday = (6 - today.getDay() + 7) % 7;
      const saturday = new Date(today);
      saturday.setDate(saturday.getDate() + daysUntilSaturday);
      return this.formatDate(saturday);
    }

    if (lowerValue === 'volgende week' || lowerValue === 'next week') {
      const nextWeek = new Date(today);
      nextWeek.setDate(nextWeek.getDate() + 7);
      return this.formatDate(nextWeek);
    }

    // Try parsing as date
    try {
      const parsed = new Date(value);
      if (!isNaN(parsed.getTime())) {
        return this.formatDate(parsed);
      }
    } catch (e) {
      // Invalid date
    }

    return null;
  }

  /**
   * Format date as YYYY-MM-DD
   */
  static formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  /**
   * Normalize count to valid number
   */
  static normalizeCount(value) {
    if (!value) return null;

    // Handle special strings from widget
    if (value === 'Meer dan 25') return 26;
    if (value === '6–10') return 8;
    if (value === '11–25') return 18;

    const num = parseInt(value, 10);
    if (isNaN(num) || num < 1) return null;
    if (num > 100) return 100; // Cap at reasonable max

    return num;
  }

  /**
   * Normalize duration to valid value
   */
  static normalizeDuration(value) {
    if (!value) return null;
    
    const normalized = String(value).toLowerCase().trim();
    return DURATION_MAP[normalized] || null;
  }

  /**
   * Normalize audience to valid value
   */
  static normalizeAudience(value) {
    if (!value) return null;
    
    const key = String(value).trim();
    return AUDIENCE_MAP[key] || null;
  }

  /**
   * Normalize vibe/style to valid vibe value
   */
  static normalizeVibe(value) {
    if (!value) return null;
    
    const key = String(value).trim();
    return STYLE_TO_VIBE_MAP[key] || null;
  }

  /**
   * Save preferences to session storage
   */
  static save(preferences) {
    const normalized = this.normalize(preferences);
    if (!normalized) return false;

    const payload = {
      data: normalized,
      timestamp: Date.now(),
      expiresAt: Date.now() + STORAGE_EXPIRY_MS,
    };

    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
      sessionStorage.setItem(LEGACY_STORAGE_KEY, JSON.stringify(normalized));
      
      // Also set as window global for immediate access
      window.SBDP_HOME_WIDGET_PREFILL = normalized;

      try {
        window.dispatchEvent(new CustomEvent('ddb:preferences:updated', {
          detail: normalized,
          bubbles: false,
        }));
      } catch (_e) {
        // ignore dispatch errors
      }

      return true;
    } catch (e) {
      console.warn('Failed to save preferences to sessionStorage:', e);
      // At least set window global
      window.SBDP_HOME_WIDGET_PREFILL = normalized;
      return false;
    }
  }

  /**
   * Load preferences with fallback chain
   */
  static load() {
    // 1. Explicit URL params should beat stale stored state.
    if (typeof window !== 'undefined' && window.location) {
      const params = new URLSearchParams(window.location.search);
      const fromUrl = {
        visitDate: params.get('visitDate') || params.get('date'),
        count: params.get('count') || params.get('participants') || params.get('people'),
        duration: params.get('duration'),
        audience: params.get('audience'),
        vibe: params.get('vibe') || params.get('style'),
        startActivity: params.get('startActivity') || params.get('start'),
      };

      const normalized = this.normalize(fromUrl);
      if (normalized) {
        this.save(normalized);
        return normalized;
      }
    }

    // 2. Try window global (most immediate)
    if (window.SBDP_HOME_WIDGET_PREFILL) {
      const normalized = this.normalize(window.SBDP_HOME_WIDGET_PREFILL);
      if (normalized) return normalized;
    }

    // 3. Try session storage
    try {
      const stored = sessionStorage.getItem(STORAGE_KEY);
      if (stored) {
        const payload = JSON.parse(stored);
        
        // Check expiry
        if (payload.expiresAt && Date.now() < payload.expiresAt) {
          const normalized = this.normalize(payload.data);
          if (normalized) {
            // Refresh window global
            window.SBDP_HOME_WIDGET_PREFILL = normalized;
            return normalized;
          }
        } else {
          // Expired, clean up
          sessionStorage.removeItem(STORAGE_KEY);
        }
      }
      const legacyStored = sessionStorage.getItem(LEGACY_STORAGE_KEY);
      if (legacyStored) {
        const legacyPayload = JSON.parse(legacyStored);
        const legacyNormalized = this.normalize(legacyPayload);
        if (legacyNormalized) {
          this.save(legacyNormalized);
          return legacyNormalized;
        }
      }
    } catch (e) {
      console.warn('Failed to load preferences from sessionStorage:', e);
    }

    return null;
  }

  /**
   * Clear all stored preferences
   */
  static clear() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem(LEGACY_STORAGE_KEY);
    } catch (e) {
      // Ignore
    }
    delete window.SBDP_HOME_WIDGET_PREFILL;
  }

  /**
   * Build URL with preferences
   */
  static buildUrl(basePath, preferences) {
    const normalized = this.normalize(preferences);
    if (!normalized) return basePath;

    const url = new URL(basePath, window.location.origin);
    
    if (normalized.visitDate) {
      url.searchParams.set('visitDate', normalized.visitDate);
    }
    if (normalized.count) {
      url.searchParams.set('count', String(normalized.count));
    }
    if (normalized.duration) {
      url.searchParams.set('duration', normalized.duration);
    }
    if (normalized.audience) {
      url.searchParams.set('audience', normalized.audience);
    }
    if (normalized.vibe) {
      url.searchParams.set('vibe', normalized.vibe);
    }
    if (normalized.startActivity) {
      url.searchParams.set('start', normalized.startActivity);
    }

    return url.toString();
  }

  /**
   * Validate preferences object
   */
  static validate(preferences) {
    const errors = [];

    if (preferences.vibe && !VALID_VIBES.includes(preferences.vibe)) {
      errors.push(`Invalid vibe: ${preferences.vibe}`);
    }

    if (preferences.audience && !VALID_AUDIENCES.includes(preferences.audience)) {
      errors.push(`Invalid audience: ${preferences.audience}`);
    }

    if (preferences.duration && !VALID_DURATIONS.includes(preferences.duration)) {
      errors.push(`Invalid duration: ${preferences.duration}`);
    }

    if (preferences.count && (preferences.count < 1 || preferences.count > 100)) {
      errors.push(`Invalid count: ${preferences.count}`);
    }

    return {
      valid: errors.length === 0,
      errors,
    };
  }
}

// Export for both ES modules and script tags
if (typeof module !== 'undefined' && module.exports) {
  module.exports = PreferenceManager;
}

if (typeof window !== 'undefined') {
  window.PreferenceManager = PreferenceManager;
}

export default PreferenceManager;
