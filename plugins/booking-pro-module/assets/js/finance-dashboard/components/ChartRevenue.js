import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "ChartRevenue",
  props: {
    series: {
      type: Array,
      default: () => [],
    },
    granularity: {
      type: String,
      default: "day",
    },
    loading: {
      type: Boolean,
      default: false,
    },
    formatter: {
      type: Object,
      default: () => ({
        currency: (value) => value,
      }),
    },
    i18n: {
      type: Object,
      default: () => ({}),
    },
  },
  setup(props) {
    const maxValue = computed(() => {
      if (!props.series.length) {
        return 0;
      }

      return props.series.reduce((max, entry) => Math.max(max, entry.gross ?? 0), 0);
    });

    const safeSeries = computed(() =>
      props.series.map((entry) => ({
        period: entry.period ?? "",
        gross: Number.parseFloat(entry.gross ?? 0),
      })),
    );

    return {
      maxValue,
      safeSeries,
    };
  },
  template: `
    <article class="finance-card finance-card--chart">
      <header class="finance-card__header">
        <h2>{{ i18n.revenueTitle ?? "Omzet" }} · {{ granularity }}</h2>
      </header>

      <div class="finance-card__body finance-chart">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>

        <div v-else-if="!safeSeries.length" class="finance-chart__empty">
          {{ i18n.noData }}
        </div>

        <div v-else class="finance-chart__grid">
          <div
            v-for="point in safeSeries"
            :key="point.period"
            class="finance-chart__bar"
          >
            <div
              class="finance-chart__bar-fill"
              :style="{ height: maxValue > 0 ? (point.gross / maxValue * 100) + '%' : '0%' }"
            ></div>
            <span class="finance-chart__bar-value">
              {{ formatter.currency(point.gross ?? 0) }}
            </span>
            <span class="finance-chart__bar-label">{{ point.period }}</span>
          </div>
        </div>
      </div>
    </article>
  `,
});
