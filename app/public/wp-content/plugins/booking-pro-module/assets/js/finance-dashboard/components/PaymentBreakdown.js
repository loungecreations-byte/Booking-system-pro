import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "PaymentBreakdown",
  props: {
    payments: {
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
    const rows = computed(() => props.payments ?? []);
    const hasRows = computed(() => rows.value.length > 0);

    return {
      rows,
      hasRows,
    };
  },
  template: `
    <article class="finance-card finance-card--table">
      <header class="finance-card__header">
        <h2>{{ i18n.paymentTitle ?? "Betaalmethodes" }}</h2>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>
        <table v-else-if="hasRows" class="finance-table">
          <thead>
            <tr>
              <th>Methode</th>
              <th>Transacties</th>
              <th>Gemiddelde order</th>
              <th>Totaal betaald</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.method ?? row.label">
              <td>{{ row.label ?? row.method }}</td>
              <td>{{ formatter.number(row.count ?? 0) }}</td>
              <td>{{ formatter.currency(row.avg_order ?? 0) }}</td>
              <td>{{ formatter.currency(row.total_paid ?? 0) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="finance-table__empty">
          {{ i18n.paymentEmpty ?? "Geen betalingen gevonden." }}
        </p>
      </div>
    </article>
  `,
});
