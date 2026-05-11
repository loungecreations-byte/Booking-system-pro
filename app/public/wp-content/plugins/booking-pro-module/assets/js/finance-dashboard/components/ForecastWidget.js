import { defineComponent } from "vue";

export default defineComponent({
  name: "ForecastWidget",
  props: {
    forecast: {
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
        number: (value) => value,
      }),
    },
    trendColor: {
      type: String,
      default: "neutral",
    },
    i18n: {
      type: Object,
      default: () => ({}),
    },
  },
  computed: {
    slope() {
      return Number.parseFloat(this.forecast?.trend?.slope ?? 0).toFixed(2);
    },
    determination() {
      return Number.parseFloat(this.forecast?.trend?.r_squared ?? 0).toFixed(2);
    },
  },
  template: `
    <article class="finance-card finance-card--forecast" :data-trend="trendColor">
      <header class="finance-card__header">
        <h2>{{ i18n.forecastTitle ?? "Voorspelling" }}</h2>
      </header>

      <div class="finance-card__body">
        <div v-if="loading" class="finance-card__loading">
          {{ i18n.loadingLabel ?? "Bezig met laden..." }}
        </div>

        <div v-else class="finance-forecast">
          <div class="finance-forecast__metrics">
            <div>
              <span class="finance-forecast__label">Verwachte maand</span>
              <strong class="finance-forecast__value">
                {{ formatter.currency(forecast.expected_month_total ?? 0) }}
              </strong>
            </div>
            <div>
              <span class="finance-forecast__label">Groei tov vorige maand</span>
              <strong class="finance-forecast__value">
                {{ formatter.percent(forecast.growth_rate ?? 0) }}
              </strong>
            </div>
          </div>

          <dl class="finance-forecast__details">
            <div>
              <dt>Slope</dt>
              <dd>{{ slope }}</dd>
            </div>
            <div>
              <dt>R²</dt>
              <dd>{{ determination }}</dd>
            </div>
            <div>
              <dt>Datapunten</dt>
              <dd>{{ formatter.number(forecast.trend?.n ?? 0) }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </article>
  `,
});
