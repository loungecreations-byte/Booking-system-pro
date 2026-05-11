import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "BookingLossList",
  props: {
    losses: {
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
        number: (value) => value,
      }),
    },
    i18n: {
      type: Object,
      default: () => ({}),
    },
  },
  setup(props) {
    const rows = computed(() => props.losses ?? []);
    const totalLoss = computed(() =>
      rows.value.reduce((sum, row) => sum + (Number.parseFloat(row.order_total ?? 0) || 0), 0),
    );

    return {
      rows,
      totalLoss,
    };
  },
  template: `
    <article class="finance-card finance-card--losses">
      <header class="finance-card__header">
        <h2>{{ i18n.lossesTitle ?? "Gemiste omzet" }}</h2>
        <span class="finance-card__meta">
          {{ formatter.currency(totalLoss) }}
        </span>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>
        <table v-else-if="rows.length" class="finance-table finance-table--compact">
          <thead>
            <tr>
              <th>Order</th>
              <th>Status</th>
              <th>Datum</th>
              <th>Bedrag</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.order_id">
              <td>#{{ row.order_id }}</td>
              <td>{{ row.status }}</td>
              <td>{{ row.date }}</td>
              <td>{{ formatter.currency(row.order_total ?? 0) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="finance-table__empty">
          {{ i18n.lossesEmpty ?? "Geen gemiste omzet gemeld." }}
        </p>
      </div>
    </article>
  `,
});
