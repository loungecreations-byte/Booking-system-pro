import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "MarginTable",
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
      }),
    },
    i18n: {
      type: Object,
      default: () => ({}),
    },
  },
  setup(props) {
    const topMargins = computed(() => props.margins.slice(0, 8));

    return {
      topMargins,
    };
  },
  template: `
    <article class="finance-card finance-card--table">
      <header class="finance-card__header">
        <h2>{{ i18n.marginTitle ?? "Marges" }}</h2>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>
        <table v-else-if="topMargins.length" class="finance-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Verkoop</th>
              <th>Inkoop</th>
              <th>Margin</th>
              <th>Margin %</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in topMargins" :key="item.product_id">
              <td>{{ item.product_name ?? ('#' + item.product_id) }}</td>
              <td>{{ formatter.currency(item.price ?? 0) }}</td>
              <td>{{ formatter.currency(item._purchase_price ?? 0) }}</td>
              <td>{{ formatter.currency(item.margin_abs ?? 0) }}</td>
              <td>{{ formatter.percent(item.margin_percent ?? 0) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="finance-table__empty">
          {{ i18n.noData }}
        </p>
      </div>
    </article>
  `,
});
