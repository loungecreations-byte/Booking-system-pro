import { defineComponent, reactive, computed, onMounted } from "vue";
import { createFinanceApi } from "./api.js";
import { formatCurrency, formatNumber, formatPercent, chooseTrendColor } from "./utils/format.js";

import KPICockpit from "./components/KPICockpit.js";
import ChartRevenue from "./components/ChartRevenue.js";
import ChartRefunds from "./components/ChartRefunds.js";
import MarginTable from "./components/MarginTable.js";
import PaymentBreakdown from "./components/PaymentBreakdown.js";
import ForecastWidget from "./components/ForecastWidget.js";
import BookingLossList from "./components/BookingLossList.js";
import CostOverview from "./components/CostOverview.js";
import ReleaseHighlights from "./components/ReleaseHighlights.js";

const DEFAULT_I18N = {
  refresh: "Vernieuwen",
  filtersTitle: "Filters",
  downloadCsv: "Download CSV",
  noData: "Geen data gevonden",
  lossesTitle: "Gemiste omzet",
  refundTitle: "Refunds",
  refundTopLabel: "Top producten",
  refundTotalLabel: "Totaal",
  refundRatioLabel: "Refund ratio",
  marginTitle: "Marges",
  forecastTitle: "Voorspelling",
  paymentTitle: "Betaalmethodes",
  summaryTitle: "KPI cockpit",
  revenueTitle: "Omzet",
  daterangeLabel: "Periode",
  granularityLabel: "Resolutie",
  todayLabel: "Vandaag",
  monthLabel: "Deze maand",
  revenueTodayLabel: "Omzet vandaag",
  revenueMonthLabel: "Omzet maand",
  bookingsLabel: "Boekingen",
  averageOrderLabel: "Gemiddelde orderwaarde",
  refundRateLabel: "Refund ratio",
  noShowLabel: "No-shows",
  loadingLabel: "Bezig met laden...",
  lossesEmpty: "Geen geannuleerde of mislukte boekingen gevonden.",
  paymentEmpty: "Geen betalingen gevonden voor de geselecteerde periode.",
  channelLabel: "Kanaal",
  vendorLabel: "Verkoper",
  allLabel: "Alle",
  highlightReleaseLabel: "Release datum",
  highlightAudienceLabel: "Impact voor klanten",
  highlightClientsLabel: "Focus-klanten",
  highlightReadMore: "Lees verder",
  highlightTitleFallback: "Nieuw in WooCommerce",
  highlightTimelineLabel: "Recente releases",
  highlightTimelineEmpty: "Geen recente releases gevonden.",
};

const DEFAULT_SUMMARY = {
  generated_at: "",
  revenue_today: 0,
  revenue_month: 0,
  bookings: 0,
  average_order_value: 0,
  refund_rate: 0,
  no_show_estimate: 0,
};

const DEFAULT_REVENUE = {
  granularity: "day",
  series: [],
};

const DEFAULT_REFUNDS = {
  total_refunds: 0,
  refund_rate: 0,
  top_refunded_products: [],
  refunds: [],
};

const DEFAULT_FORECAST = {
  expected_month_total: 0,
  growth_rate: 0,
  trend: {
    slope: 0,
    intercept: 0,
    r_squared: 0,
    n: 0,
    start_date: "",
    end_date: "",
  },
};

const DEFAULT_HIGHLIGHTS = {
  title: "",
  summary: "",
  releaseDate: "",
  version: "",
  items: [],
  links: [],
  timeline: [],
  audience: {
    label: "",
    description: "",
    clients: [],
  },
};

function resolveI18n(config) {
  return { ...DEFAULT_I18N, ...(config?.i18n ?? {}) };
}

function resolveHighlights(config) {
  const source = config?.highlights ?? {};
  const audience = source?.audience ?? {};

  return {
    ...DEFAULT_HIGHLIGHTS,
    ...source,
    items: Array.isArray(source?.items) ? source.items : [],
    links: Array.isArray(source?.links) ? source.links : [],
    timeline: Array.isArray(source?.timeline) ? source.timeline : [],
    audience: {
      ...DEFAULT_HIGHLIGHTS.audience,
      ...(audience ?? {}),
      clients: Array.isArray(audience?.clients) ? audience.clients : [],
    },
  };
}

export default defineComponent({
  name: "FinanceLayout",
  components: {
    KPICockpit,
    ChartRevenue,
    ChartRefunds,
  MarginTable,
  PaymentBreakdown,
  ForecastWidget,
  BookingLossList,
  CostOverview,
  ReleaseHighlights,
  },
  props: {
    config: {
      type: Object,
      required: true,
    },
  },
  setup(props) {
    const currency = props.config?.currency ?? "EUR";
    const locale = props.config?.locale ?? "nl-NL";
    const i18n = resolveI18n(props.config);
    const api = createFinanceApi(props.config);

    const state = reactive({
      summary: { ...DEFAULT_SUMMARY, ...(props.config?.summary ?? {}) },
      revenue: { ...DEFAULT_REVENUE },
      refunds: { ...DEFAULT_REFUNDS },
      margins: [],
      payments: [],
      losses: [],
      forecast: { ...DEFAULT_FORECAST, ...(props.config?.forecast ?? {}) },
      highlights: resolveHighlights(props.config),
      dimensions: { channels: [], vendors: [] },
      error: "",
    });

    const filters = reactive({
      granularity: "day",
      lookback: 30,
      channel: "",
      vendor: "",
    });

    const loading = reactive({
      summary: false,
      revenue: false,
      refunds: false,
      margins: false,
      payments: false,
      forecast: false,
      losses: false,
      dimensions: false,
    });

    const trendColor = computed(() => chooseTrendColor(state.forecast?.growth_rate));
    const hasHighlights = computed(() => {
      const highlights = state.highlights;

      if (!highlights) {
        return false;
      }

      if (typeof highlights.summary === "string" && highlights.summary.trim() !== "") {
        return true;
      }

      if (Array.isArray(highlights.items) && highlights.items.length > 0) {
        return true;
      }

      if (Array.isArray(highlights.links) && highlights.links.length > 0) {
        return true;
      }

      if (Array.isArray(highlights.timeline) && highlights.timeline.length > 0) {
        return true;
      }

      return false;
    });

    function withFilters(extra = {}) {
      const query = { ...extra };

      if (filters.channel) {
        query.channel = filters.channel;
      }

      const vendorId = Number.parseInt(filters.vendor, 10);
      if (!Number.isNaN(vendorId) && vendorId > 0) {
        query.vendor_id = vendorId;
      }

      return query;
    }

    async function loadSegment(segment, loader) {
      loading[segment] = true;
      try {
        const result = await loader();
        state[segment] = result;
        state.error = "";
      } catch (error) {
        console.error(`[finance] Failed to load ${segment}`, error);
        state.error = error instanceof Error ? error.message : String(error);
      } finally {
        loading[segment] = false;
      }
    }

    async function refreshDimensions() {
      await loadSegment("dimensions", () => api.getFilters());
    }

    async function refreshSummary() {
      await loadSegment("summary", () => api.getSummary(withFilters()));
    }

    async function refreshRevenue() {
      await loadSegment("revenue", () =>
        api.getRevenue(
          withFilters({
            granularity: filters.granularity,
            lookback: filters.lookback,
          }),
        ),
      );
    }

    async function refreshRefunds() {
      await loadSegment("refunds", () => api.getRefunds(withFilters()));
    }

    async function refreshMargins() {
      await loadSegment("margins", () => api.getMargins(withFilters()));
    }

    async function refreshPayments() {
      await loadSegment("payments", () => api.getPayments(withFilters()));
    }

    async function refreshForecast() {
      await loadSegment("forecast", () => api.getForecast(withFilters()));
    }

    async function refreshLosses() {
      await loadSegment("losses", () => api.getLosses(withFilters()));
    }

    async function refreshAll() {
      await Promise.allSettled([
        refreshSummary(),
        refreshRevenue(),
        refreshRefunds(),
        refreshMargins(),
        refreshPayments(),
        refreshForecast(),
        refreshLosses(),
      ]);
    }

    function onGranularityChange(event) {
      filters.granularity = event.target.value;
      refreshRevenue();
    }

    function onLookbackChange(event) {
      filters.lookback = Number.parseInt(event.target.value, 10) || 30;
      refreshRevenue();
    }

    function onChannelChange(event) {
      filters.channel = event.target.value;
      refreshAll();
    }

    function onVendorChange(event) {
      filters.vendor = event.target.value;
      refreshAll();
    }

    onMounted(async () => {
      await refreshDimensions();
      await refreshAll();
    });

    return {
      state,
      hasHighlights,
      filters,
      loading,
      i18n,
      currency,
      locale,
      trendColor,
      refreshAll,
      refreshSummary,
      refreshRevenue,
      refreshRefunds,
      refreshMargins,
      refreshPayments,
      refreshForecast,
      refreshLosses,
      refreshDimensions,
      onGranularityChange,
      onLookbackChange,
      onChannelChange,
      onVendorChange,
      formatCurrency: (value) => formatCurrency(value, currency, locale),
      formatNumber: (value) => formatNumber(value, locale),
      formatPercent: (value) => formatPercent(value, locale),
    };
  },
  template: `
    <div class="finance-dashboard">
      <div v-if="state.error" class="finance-dashboard__error">
        <strong>{{ state.error }}</strong>
        <button type="button" class="finance-button" @click="refreshAll">{{ i18n.refresh }}</button>
      </div>

      <section class="finance-dashboard__section finance-dashboard__section--top">
        <kpi-cockpit
          :summary="state.summary"
          :loading="loading.summary"
          :formatter="{ currency: formatCurrency, number: formatNumber, percent: formatPercent }"
          :i18n="i18n"
        />
        <forecast-widget
          :forecast="state.forecast"
          :loading="loading.forecast"
          :formatter="{ currency: formatCurrency, number: formatNumber, percent: formatPercent }"
          :trend-color="trendColor"
          :i18n="i18n"
        />
      </section>

      <section v-if="hasHighlights" class="finance-dashboard__section">
        <release-highlights
          :highlights="state.highlights"
          :i18n="i18n"
          :locale="locale"
        />
      </section>

      <section class="finance-dashboard__section">
        <div class="finance-dashboard__section-header">
          <h2>{{ i18n.paymentTitle }}</h2>
          <div class="finance-dashboard__filters">
            <label>
              {{ i18n.channelLabel }}
              <select v-model="filters.channel" @change="onChannelChange">
                <option value="">{{ i18n.allLabel }}</option>
                <option
                  v-for="channel in state.dimensions.channels"
                  :key="channel.value"
                  :value="channel.value"
                >
                  {{ channel.label }}
                </option>
              </select>
            </label>
            <label>
              {{ i18n.vendorLabel }}
              <select v-model="filters.vendor" @change="onVendorChange">
                <option value="">{{ i18n.allLabel }}</option>
                <option
                  v-for="vendor in state.dimensions.vendors"
                  :key="vendor.value"
                  :value="vendor.value"
                >
                  {{ vendor.label }}
                </option>
              </select>
            </label>
            <label>
              {{ i18n.granularityLabel }}
              <select v-model="filters.granularity" @change="onGranularityChange">
                <option value="day">Dag</option>
                <option value="week">Week</option>
                <option value="month">Maand</option>
              </select>
            </label>
            <label>
              {{ i18n.daterangeLabel }}
              <select v-model="filters.lookback" @change="onLookbackChange">
                <option value="7">7</option>
                <option value="14">14</option>
                <option value="30">30</option>
                <option value="60">60</option>
                <option value="90">90</option>
              </select>
            </label>
            <button type="button" class="finance-button" @click="refreshRevenue">{{ i18n.refresh }}</button>
          </div>
        </div>
        <chart-revenue
          :series="state.revenue.series"
          :granularity="filters.granularity"
          :loading="loading.revenue"
          :formatter="{ currency: formatCurrency }"
          :i18n="i18n"
        />
      </section>

      <section class="finance-dashboard__section finance-dashboard__section--grid">
        <chart-refunds
          :refunds="state.refunds"
          :loading="loading.refunds"
          :formatter="{ currency: formatCurrency, percent: formatPercent }"
          :i18n="i18n"
        />
        <payment-breakdown
          :payments="state.payments"
          :loading="loading.payments"
          :formatter="{ currency: formatCurrency, number: formatNumber }"
          :i18n="i18n"
        />
      </section>

      <section class="finance-dashboard__section finance-dashboard__section--grid">
        <margin-table
          :margins="state.margins"
          :loading="loading.margins"
          :formatter="{ currency: formatCurrency, percent: formatPercent }"
          :i18n="i18n"
        />
        <cost-overview
          :margins="state.margins"
          :loading="loading.margins"
          :formatter="{ currency: formatCurrency, percent: formatPercent, number: formatNumber }"
          :i18n="i18n"
        />
      </section>

      <section class="finance-dashboard__section">
        <booking-loss-list
          :losses="state.losses"
          :loading="loading.losses"
          :formatter="{ currency: formatCurrency, number: formatNumber }"
          :i18n="i18n"
        />
      </section>
    </div>
  `,
});
