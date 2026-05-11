import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "ChartRefunds",
  props: {
    refunds: {
      type: Object,
      default: () => ({}),
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
      }),
    },
    i18n: {
      type: Object,
      default: () => ({}),
    },
  },
  setup(props) {
    const topProducts = computed(() => props.refunds?.top_refunded_products ?? []);
    return {
      topProducts,
    };
  },
  template: `
    <article class="finance-card finance-card--refunds">
      <header class="finance-card__header">
        <h2>{{ i18n.refundTitle ?? "Refunds" }}</h2>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>
        <div v-else class="finance-refund">
          <div class="finance-refund__headline">
            <div>
              <span class="finance-refund__label">
                {{ i18n.refundTotalLabel ?? "Totaal" }}
              </span>
              <strong class="finance-refund__value">
                {{ formatter.currency(refunds.total_refunds ?? 0) }}
              </strong>
            </div>
            <div>
              <span class="finance-refund__label">
                {{ i18n.refundRatioLabel ?? "Refund ratio" }}
              </span>
              <strong class="finance-refund__value">
                {{ formatter.percent(refunds.refund_rate ?? 0) }}
              </strong>
            </div>
          </div>

          <div class="finance-refund__list">
            <h3>{{ i18n.refundTopLabel ?? "Top producten" }}</h3>
            <ul v-if="topProducts.length">
              <li v-for="product in topProducts" :key="product.product_id">
                <span class="finance-refund__product">{{ product.label }}</span>
                <span class="finance-refund__amount">{{ formatter.currency(product.total ?? 0) }}</span>
              </li>
            </ul>
            <p v-else class="finance-refund__empty">
              {{ i18n.noData }}
            </p>
          </div>
        </div>
      </div>
    </article>
  `,
});
