import { defineComponent, computed } from "vue";

export default defineComponent({
  name: "ReleaseHighlights",
  props: {
    highlights: {
      type: Object,
      required: true,
    },
    i18n: {
      type: Object,
      required: true,
    },
    locale: {
      type: String,
      default: "nl-NL",
    },
  },
  setup(props) {
    const fallbackTitle =
      typeof props.i18n?.highlightTitleFallback === "string"
        ? props.i18n.highlightTitleFallback
        : "Nieuw in WooCommerce";

    const title = computed(() => {
      const label = props.highlights?.title;
      return typeof label === "string" && label.trim() !== "" ? label : fallbackTitle;
    });

    const summary = computed(() => {
      const value = props.highlights?.summary;
      return typeof value === "string" ? value : "";
    });

    const versionBadge = computed(() => {
      const version = props.highlights?.version;
      return typeof version === "string" && version.trim() !== "" ? `WooCommerce ${version}` : "";
    });

    const releaseLabel =
      typeof props.i18n?.highlightReleaseLabel === "string"
        ? props.i18n.highlightReleaseLabel
        : "Release datum";

    const audienceLabel =
      typeof props.highlights?.audience?.label === "string" && props.highlights.audience.label.trim() !== ""
        ? props.highlights.audience.label
        : typeof props.i18n?.highlightAudienceLabel === "string"
        ? props.i18n.highlightAudienceLabel
        : "Impact voor klanten";

    const clientsLabel =
      typeof props.i18n?.highlightClientsLabel === "string"
        ? props.i18n.highlightClientsLabel
        : "Focus-klanten";

    const readMoreLabel =
      typeof props.i18n?.highlightReadMore === "string" ? props.i18n.highlightReadMore : "Lees verder";

    const timelineLabel =
      typeof props.i18n?.highlightTimelineLabel === "string"
        ? props.i18n.highlightTimelineLabel
        : "Recente releases";

    const timelineEmpty =
      typeof props.i18n?.highlightTimelineEmpty === "string"
        ? props.i18n.highlightTimelineEmpty
        : "Geen recente releases gevonden.";

    const releaseDate = computed(() => {
      const value = props.highlights?.releaseDate;
      return typeof value === "string" ? value : "";
    });

    const releaseInfo = computed(() => {
      const raw = releaseDate.value;
      if (!raw) {
        return "";
      }

      try {
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) {
          return raw;
        }

        return new Intl.DateTimeFormat(props.locale || "nl-NL", {
          dateStyle: "long",
        }).format(date);
      } catch (error) {
        console.warn("[finance] Failed to format release date", error);
        return raw;
      }
    });

    const items = computed(() => {
      if (!Array.isArray(props.highlights?.items)) {
        return [];
      }

      return props.highlights.items
        .map((item, index) => {
          if (item && typeof item === "object") {
            const headline = typeof item.headline === "string" ? item.headline : "";
            const description = typeof item.description === "string" ? item.description : "";

            if (headline.trim() === "" && description.trim() === "") {
              return null;
            }

            return {
              key: typeof item.key === "string" && item.key !== "" ? item.key : `highlight-${index}`,
              headline,
              description,
            };
          }

          return null;
        })
        .filter(Boolean);
    });

    const links = computed(() => {
      if (!Array.isArray(props.highlights?.links)) {
        return [];
      }

      return props.highlights.links
        .map((entry, index) => {
          if (!entry || typeof entry !== "object") {
            return null;
          }

          const url = typeof entry.url === "string" ? entry.url : "";
          if (url.trim() === "") {
            return null;
          }

          return {
            key: typeof entry.key === "string" && entry.key !== "" ? entry.key : `link-${index}`,
            url,
            label: typeof entry.label === "string" && entry.label.trim() !== "" ? entry.label : readMoreLabel,
          };
        })
        .filter(Boolean);
    });

    const audienceDescription = computed(() => {
      const value = props.highlights?.audience?.description;
      return typeof value === "string" ? value : "";
    });

    const clients = computed(() => {
      if (!Array.isArray(props.highlights?.audience?.clients)) {
        return [];
      }

      return props.highlights.audience.clients
        .map((client) => (typeof client === "string" ? client : ""))
        .filter((client) => client.trim() !== "");
    });

    const timeline = computed(() => {
      if (!Array.isArray(props.highlights?.timeline)) {
        return [];
      }

      return props.highlights.timeline
        .map((entry, index) => {
          if (!entry || typeof entry !== "object") {
            return null;
          }

          const title = typeof entry.title === "string" ? entry.title : "";
          const url = typeof entry.url === "string" ? entry.url : "";
          const date = typeof entry.date === "string" ? entry.date : "";

          if (title.trim() === "" || url.trim() === "") {
            return null;
          }

          return {
            key: typeof entry.key === "string" && entry.key !== "" ? entry.key : `timeline-${index}`,
            title,
            url,
            date,
          };
        })
        .filter(Boolean);
    });

    function formatTimelineDate(value) {
      if (typeof value !== "string" || value.trim() === "") {
        return "";
      }

      try {
        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
          return value;
        }

        return new Intl.DateTimeFormat(props.locale || "nl-NL", {
          dateStyle: "medium",
        }).format(parsed);
      } catch (error) {
        console.warn("[finance] Failed to format timeline date", error);
        return value;
      }
    }

    return {
      title,
      summary,
      versionBadge,
      releaseLabel,
      releaseInfo,
      items,
      links,
      audienceLabel,
      audienceDescription,
      clientsLabel,
      clients,
      readMoreLabel,
      timelineLabel,
      timelineEmpty,
      timeline,
      formatTimelineDate,
    };
  },
  template: `
    <article class="finance-card finance-highlight">
      <header class="finance-card__header">
        <div class="finance-highlight__title">
          <h2>{{ title }}</h2>
          <p v-if="releaseInfo" class="finance-card__meta">{{ releaseLabel }}: {{ releaseInfo }}</p>
        </div>
        <span v-if="versionBadge" class="finance-highlight__badge">{{ versionBadge }}</span>
      </header>

      <p v-if="summary" class="finance-highlight__summary">{{ summary }}</p>

      <ul v-if="items.length" class="finance-highlight__list">
        <li v-for="item in items" :key="item.key" class="finance-highlight__item">
          <h3 v-if="item.headline" class="finance-highlight__item-title">{{ item.headline }}</h3>
          <p v-if="item.description" class="finance-highlight__item-copy">{{ item.description }}</p>
        </li>
      </ul>

      <div v-if="audienceDescription || clients.length" class="finance-highlight__audience">
        <strong class="finance-highlight__audience-label">{{ audienceLabel }}</strong>
        <p v-if="audienceDescription" class="finance-highlight__audience-copy">{{ audienceDescription }}</p>
        <ul v-if="clients.length" class="finance-highlight__audience-list">
          <li v-for="client in clients" :key="client" class="finance-highlight__audience-client">{{ client }}</li>
        </ul>
      </div>

      <div v-if="links.length" class="finance-highlight__links">
        <a
          v-for="link in links"
          :key="link.key"
          :href="link.url"
          class="finance-highlight__link"
          target="_blank"
          rel="noopener noreferrer"
        >
          {{ link.label }}
        </a>
      </div>

      <section class="finance-highlight__timeline">
        <strong class="finance-highlight__timeline-label">{{ timelineLabel }}</strong>
        <ol v-if="timeline.length" class="finance-highlight__timeline-list">
          <li v-for="entry in timeline" :key="entry.key" class="finance-highlight__timeline-item">
            <span class="finance-highlight__timeline-date">{{ formatTimelineDate(entry.date) }}</span>
            <a
              class="finance-highlight__timeline-link"
              :href="entry.url"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ entry.title }}
            </a>
          </li>
        </ol>
        <p v-else class="finance-highlight__timeline-empty">{{ timelineEmpty }}</p>
      </section>
    </article>
  `,
});
