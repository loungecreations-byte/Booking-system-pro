export function buildPlannerCtaModel({
  plannerActionState = {},
  formattedTotal = "",
  queuePending = false,
  planPending = false,
  surfaceUpdating = false,
} = {}) {
  const actionMode = plannerActionState?.action_mode || "blocked";
  const checkoutLabel = formattedTotal ? `Boek mijn dag · ${formattedTotal}` : "Boek mijn dag";
  const checkoutEnabled =
    actionMode === "direct" &&
    Boolean(plannerActionState?.primary_cta_enabled) &&
    !queuePending &&
    !surfaceUpdating;
  const quoteEnabled =
    actionMode === "request" &&
    Boolean(plannerActionState?.secondary_quote_enabled) &&
    !planPending &&
    !surfaceUpdating;
  const busy = queuePending || planPending || surfaceUpdating;
  const directPriceLabel = "Indicatieve prijs. Winkelwagen en checkout blijven de definitieve commerciële waarheid.";
  const requestPriceLabel = "Voorlopige richtprijs. Beschikbaarheid en offerte worden eerst gecontroleerd.";

  if (actionMode === "direct") {
    return {
      mode: "direct",
      primary: {
        key: "checkout",
        label: queuePending
          ? "Bezig met boeken..."
          : surfaceUpdating
          ? "Planner wordt bijgewerkt..."
          : checkoutLabel,
        ariaLabel: "Boek mijn dag direct via de winkelwagen",
        enabled: checkoutEnabled,
        busy: queuePending || surfaceUpdating,
        variant: "primary",
      },
      secondary: {
        key: "quote",
        label: planPending
          ? "Even geduld..."
          : surfaceUpdating
          ? "Planner wordt bijgewerkt..."
          : "Vraag beschikbaarheid & offerte aan",
        ariaLabel: "Vraag een offerte aan voor deze planning",
        enabled: Boolean(plannerActionState?.secondary_quote_enabled) && !planPending && !surfaceUpdating,
        busy: planPending || surfaceUpdating,
        variant: "secondary",
      },
      priceLabel: directPriceLabel,
    };
  }

  if (actionMode === "request") {
    return {
      mode: "request",
      primary: {
        key: "quote",
        label: planPending
          ? "Even geduld..."
          : surfaceUpdating
          ? "Planner wordt bijgewerkt..."
          : "Vraag beschikbaarheid & offerte aan",
        ariaLabel: "Vraag een offerte aan voor deze planning",
        enabled: quoteEnabled,
        busy: planPending || surfaceUpdating,
        variant: "primary",
      },
      secondary: {
        key: "review",
        label: "Bekijk aandachtspunten",
        ariaLabel: "Bekijk de aandachtspunten in je planning",
        enabled: !busy,
        busy: false,
        variant: "secondary",
      },
      priceLabel: requestPriceLabel,
    };
  }

  if (actionMode === "empty") {
    return {
      mode: "empty",
      primary: {
        key: "add",
        label: "Voeg activiteiten toe",
        ariaLabel: "Voeg activiteiten toe aan je dagplanning",
        enabled: !busy,
        busy: false,
        variant: "primary",
      },
      secondary: null,
      priceLabel: directPriceLabel,
    };
  }

  return {
    mode: "blocked",
    primary: {
      key: "resolve",
      label: "Los planning op",
      ariaLabel: "Los de blokkade in je planning op",
      enabled: !busy,
      busy: false,
      variant: "primary",
    },
    secondary: null,
    priceLabel: directPriceLabel,
  };
}
