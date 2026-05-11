import { defineComponent } from "vue";

export default defineComponent({
  name: "KPICockpit",
  props: {
    summary: {
      type: Object,
      required: true,
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
  computed: {
    cards() {
      const dictionary = {
        revenueTodayLabel: this.i18n.revenueTodayLabel ?? "Omzet vandaag",
        revenueMonthLabel: this.i18n.revenueMonthLabel ?? "Omzet maand",
        bookingsLabel: this.i18n.bookingsLabel ?? "Boekingen",
        averageOrderLabel: this.i18n.averageOrderLabel ?? "Gemiddelde orderwaarde",
        refundRateLabel: this.i18n.refundRateLabel ?? "Refund ratio",
        noShowLabel: this.i18n.noShowLabel ?? "No-shows",
      };

      return [
        {
          label: dictionary.revenueTodayLabel,
          value: this.formatter.currency(this.summary.revenue_today ?? 0),
        },
        {
          label: dictionary.revenueMonthLabel,
          value: this.formatter.currency(this.summary.revenue_month ?? 0),
        },
        {
          label: dictionary.bookingsLabel,
          value: this.formatter.number(this.summary.bookings ?? 0),
        },
        {
          label: dictionary.averageOrderLabel,
          value: this.formatter.currency(this.summary.average_order_value ?? 0),
        },
        {
          label: dictionary.refundRateLabel,
          value: this.formatter.percent(this.summary.refund_rate ?? 0),
        },
        {
          label: dictionary.noShowLabel,
          value: this.formatter.number(this.summary.no_show_estimate ?? 0),
        },
      ];
    },
  },
  template: `
    <article class="finance-card finance-card--kpi">
      <header class="finance-card__header">
        <h2>{{ i18n.summaryTitle }}</h2>
        <span v-if="summary.generated_at" class="finance-card__timestamp">
          {{ summary.generated_at }}
        </span>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>
        <ul v-else class="finance-kpi-grid">
          <li v-for="card in cards" :key="card.label" class="finance-kpi-grid__item">
            <span class="finance-kpi-grid__label">{{ card.label }}</span>
            <span class="finance-kpi-grid__value">{{ card.value }}</span>
          </li>
        </ul>
      </div>
    </article>
  `,
});
