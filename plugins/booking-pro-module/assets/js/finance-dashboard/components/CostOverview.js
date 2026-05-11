import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "CostOverview",
  props: {
    margins: {
      type: Array,
      default: () => [],
    },
    loading: {
      type: Boolean,
      default: false,
    },
    formatter: {
      type: Object,
      default: () => ({
        currency: (value) => value,
        percent: (value) => value,
        number: (value) => value,
      }),
    },
    i18n: {
      type: Object,
      default: () => ({}),
    },
  },
  setup(props) {
    const totals = computed(() => {
      const base = {
        revenue: 0,
        costs: 0,
        margin: 0,
        averagePercent: 0,
      };

      if (!props.margins.length) {
        return base;
      }

      const aggregates = props.margins.reduce(
        (acc, entry) => {
          const price = Number.parseFloat(entry.price ?? 0) || 0;
          const purchase = Number.parseFloat(entry._purchase_price ?? 0) || 0;
          const marginAbs = price - purchase;

          return {
            revenue: acc.revenue + price,
            costs: acc.costs + purchase,
            margin: acc.margin + marginAbs,
          };
        },
        { revenue: 0, costs: 0, margin: 0 },
      );

      return {
        ...aggregates,
        averagePercent: aggregates.revenue > 0 ? (aggregates.margin / aggregates.revenue) * 100 : 0,
      };
    });

    const topMargin = computed(() => {
      if (!props.margins.length) {
        return null;
      }

      return [...props.margins].sort((a, b) => (b.margin_abs ?? 0) - (a.margin_abs ?? 0))[0];
    });

    return {
      totals,
      topMargin,
    };
  },
  template: `
    <article class="finance-card finance-card--summary">
      <header class="finance-card__header">
        <h2>Kosten & marge</h2>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>
        <div v-else class="finance-summary">
          <ul class="finance-summary__grid">
            <li>
              <span class="finance-summary__label">Totale omzet</span>
              <strong class="finance-summary__value">
                {{ formatter.currency(totals.revenue) }}
              </strong>
            </li>
            <li>
              <span class="finance-summary__label">Totale kosten</span>
              <strong class="finance-summary__value">
                {{ formatter.currency(totals.costs) }}
              </strong>
            </li>
            <li>
              <span class="finance-summary__label">Totaal marge</span>
              <strong class="finance-summary__value">
                {{ formatter.currency(totals.margin) }}
              </strong>
            </li>
            <li>
              <span class="finance-summary__label">Gemiddelde marge %</span>
              <strong class="finance-summary__value">
                {{ formatter.percent(totals.averagePercent) }}
              </strong>
            </li>
          </ul>

          <div v-if="topMargin" class="finance-summary__highlight">
            <h3>Top product</h3>
            <p>
              {{ topMargin.product_name ?? ('#' + topMargin.product_id) }}
              –
              {{ formatter.currency(topMargin.margin_abs ?? 0) }}
              ({{ formatter.percent(topMargin.margin_percent ?? 0) }})
            </p>
          </div>
        </div>
      </div>
    </article>
  `,
});
