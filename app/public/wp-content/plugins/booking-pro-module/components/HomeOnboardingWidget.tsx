import type { FC, ChangeEvent, FormEvent } from "react";
import { useCallback, useEffect, useMemo, useState } from "react";
import StepCard from "./StepCard";

// @ts-ignore - JS utility
import PreferenceManager from "../assets/js/shared/PreferenceManager";

declare global {
  interface Window {
    SBDP_HomeOnboardingRuntime?: {
      planner_url?: string;
      plannerUrl?: string;
      route_intent?: "checkout" | "quote" | "blocked" | string;
      routeIntent?: "checkout" | "quote" | "blocked" | string;
      checkout_url?: string;
      checkoutUrl?: string;
      quote_url?: string;
      quoteUrl?: string;
      blocked_url?: string;
      blockedUrl?: string;
    };
  }
}

type HomeOnboardingRuntime = NonNullable<
  Window["SBDP_HomeOnboardingRuntime"]
>;

type AnswerKey = "visitDate" | "style" | "audience" | "count";

type AnswerState = Record<AnswerKey, string>;

const CUSTOM_DATE_OPTION = "Kies datum...";
const CUSTOM_COUNT_OPTION = "Meer dan 25";
const TOTAL_STEPS = 4;

const STEP_CONFIG: Array<{
  key: AnswerKey;
  question: string;
  options: string[];
}> = [
  {
    key: "visitDate",
    question: "Wanneer wil je Den Bosch bezoeken?",
    options: [
      "Vandaag",
      "Morgen",
      "Dit weekend",
      "Volgende week",
      "Volgende maand",
      CUSTOM_DATE_OPTION,
    ],
  },
  {
    key: "style",
    question: "Wat voor dag heb je in gedachten?",
    options: [
      "Bourgondisch genieten",
      "Actief & buiten",
      "Cultuur & historie",
      "Met de kids",
      "Laat je verrassen",
    ],
  },
  {
    key: "audience",
    question: "Met wie kom je naar Den Bosch?",
    options: ["Alleen", "Stelletje", "Familie", "Team / Groep", "School / Bedrijf"],
  },
  {
    key: "count",
    question: "Hoeveel personen zijn jullie?",
    options: ["1", "2-5", "6-10", "11-25", CUSTOM_COUNT_OPTION],
  },
];

const VISIT_PRESET_OPTIONS = STEP_CONFIG[0].options.filter(
  (option) => option !== CUSTOM_DATE_OPTION,
);

const deriveCountNumber = (value: string): number => {
  if (!value) {
    return 0;
  }
  if (value === CUSTOM_COUNT_OPTION) {
    return 26;
  }
  const rangeMatch = value.match(/^(\d+)\s*-\s*(\d+)$/);
  if (rangeMatch) {
    const upperBound = Number.parseInt(rangeMatch[2], 10);
    return Number.isNaN(upperBound) ? 0 : upperBound;
  }
  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? 0 : parsed;
};

const resolveOnboardingTarget = (
  runtime?: HomeOnboardingRuntime,
): string | null => {
  if (!runtime) {
    return null;
  }

  const routeIntent = runtime.route_intent ?? runtime.routeIntent ?? null;
  const plannerTarget = runtime.planner_url ?? runtime.plannerUrl ?? null;
  const checkoutTarget = runtime.checkout_url ?? runtime.checkoutUrl ?? null;
  const quoteTarget = runtime.quote_url ?? runtime.quoteUrl ?? null;
  const blockedTarget = runtime.blocked_url ?? runtime.blockedUrl ?? null;

  if (routeIntent === "checkout") {
    return checkoutTarget ?? plannerTarget;
  }

  if (routeIntent === "quote") {
    return quoteTarget;
  }

  if (routeIntent === "blocked") {
    return blockedTarget;
  }

  if (routeIntent === "planner") {
    return plannerTarget;
  }

  return plannerTarget ?? checkoutTarget ?? quoteTarget ?? blockedTarget ?? null;
};

const HomeOnboardingWidget: FC = () => {
  const [stepIndex, setStepIndex] = useState(0);
  const [answers, setAnswers] = useState<AnswerState>({
    visitDate: "",
    style: "",
    audience: "",
    count: "",
  });
  const [selectedVisitOption, setSelectedVisitOption] = useState("");
  const [selectedCountOption, setSelectedCountOption] = useState("");
  const [customDateValue, setCustomDateValue] = useState("");
  const [customCountValue, setCustomCountValue] = useState("");
  const [isAnimating, setIsAnimating] = useState(true);

  useEffect(() => {
    if (typeof window === "undefined") {
      setIsAnimating(false);
      return;
    }
    setIsAnimating(true);
    const raf = window.requestAnimationFrame(() => {
      setIsAnimating(false);
    });
    return () => window.cancelAnimationFrame(raf);
  }, [stepIndex]);

  const redirectWithAnswers = useCallback((finalAnswers: AnswerState) => {
    const runtime =
      typeof window !== "undefined" && window.SBDP_HomeOnboardingRuntime
        ? window.SBDP_HomeOnboardingRuntime
        : undefined;
    const target = resolveOnboardingTarget(runtime);

    if (!target || typeof window === "undefined") {
      return;
    }

    // Build preferences object for PreferenceManager
    const preferences = {
      visitDate: finalAnswers.visitDate,
      style: finalAnswers.style, // Will be mapped to 'vibe' by PreferenceManager
      audience: finalAnswers.audience,
      count: finalAnswers.count,
    };

    // Save preferences and build URL using PreferenceManager
    if (window.PreferenceManager) {
      window.PreferenceManager.save(preferences);
      const url = window.PreferenceManager.buildUrl(target, preferences);
      window.location.assign(url);
    } else {
      // Fallback to manual URL building
      const params = new URLSearchParams();
      params.set("visitDate", finalAnswers.visitDate ?? "");
      params.set("style", finalAnswers.style ?? "");
      params.set("audience", finalAnswers.audience ?? "");
      params.set("count", finalAnswers.count ?? "");
      const url = `${target}?${params.toString()}`;
      window.location.assign(url);
    }
  }, []);

  const advance = useCallback(
    (snapshot: AnswerState) => {
      setStepIndex((prev) => {
        if (prev >= TOTAL_STEPS - 1) {
          redirectWithAnswers(snapshot);
          return prev;
        }
        return prev + 1;
      });
    },
    [redirectWithAnswers],
  );

  const handleBack = useCallback(() => {
    setStepIndex((prev) => Math.max(0, prev - 1));
  }, []);

  const updateAnswer = useCallback(
    (key: AnswerKey, value: string, autoAdvance = true) => {
      const nextAnswers = { ...answers, [key]: value };
      setAnswers(nextAnswers);
      if (autoAdvance) {
        advance(nextAnswers);
      }
    },
    [advance, answers],
  );

  const handleVisitDateSelect = (option: string) => {
    setSelectedVisitOption(option);
    if (option === CUSTOM_DATE_OPTION) {
      setCustomDateValue(answers.visitDate || "");
      updateAnswer("visitDate", "", false);
      return;
    }
    setCustomDateValue("");
    updateAnswer("visitDate", option);
  };

  const handleCustomDateChange = (event: ChangeEvent<HTMLInputElement>) => {
    const value = event.target.value;
    setCustomDateValue(value);
    if (value) {
      updateAnswer("visitDate", value);
    }
  };

  const handleCountSelect = (option: string) => {
    setSelectedCountOption(option);
    if (option === CUSTOM_COUNT_OPTION) {
      setCustomCountValue(
        answers.count && deriveCountNumber(answers.count) > 25
          ? answers.count
          : "",
      );
      updateAnswer("count", "", false);
      return;
    }
    const rangeMatch = option.match(/^(\d+)\s*-\s*(\d+)$/);
    if (rangeMatch) {
      updateAnswer("count", rangeMatch[2]);
      return;
    }
    setCustomCountValue("");
    updateAnswer("count", option);
  };

  const handleCustomCountSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const numeric = Number.parseInt(customCountValue, 10);
    if (Number.isNaN(numeric) || numeric <= 25) {
      return;
    }
    updateAnswer("count", String(numeric));
  };

  const activeStep = STEP_CONFIG[stepIndex];
  const currentValue = useMemo(() => {
    if (activeStep.key === "visitDate") {
      if (selectedVisitOption) {
        return selectedVisitOption;
      }
      if (
        answers.visitDate &&
        !VISIT_PRESET_OPTIONS.includes(answers.visitDate)
      ) {
        return CUSTOM_DATE_OPTION;
      }
      return answers.visitDate;
    }
    if (activeStep.key === "count") {
      if (selectedCountOption) {
        return selectedCountOption;
      }
      if (deriveCountNumber(answers.count) > 25) {
        return CUSTOM_COUNT_OPTION;
      }
      return answers.count;
    }
    return answers[activeStep.key];
  }, [
    activeStep.key,
    answers.audience,
    answers.count,
    answers.style,
    answers.visitDate,
    selectedCountOption,
    selectedVisitOption,
  ]);

  const isStepAnswered =
    activeStep.key === "visitDate"
      ? Boolean(answers.visitDate)
      : activeStep.key === "count"
        ? Boolean(answers.count)
        : Boolean(answers[activeStep.key]);

  const supplementalContent = (() => {
    const wantsCustomVisit =
      activeStep.key === "visitDate" &&
      (selectedVisitOption === CUSTOM_DATE_OPTION ||
        (!!answers.visitDate && !VISIT_PRESET_OPTIONS.includes(answers.visitDate)));
    if (wantsCustomVisit) {
      return (
        <label className="ui-field flex flex-col gap-2 text-sm text-[color:var(--ui-color-text-muted)]">
          <span>Kies een datum die voor jou werkt</span>
          <input
            type="date"
            value={customDateValue}
            onChange={handleCustomDateChange}
            className="ui-input w-full rounded-2xl px-4 py-3 text-base"
          />
        </label>
      );
    }
    const wantsCustomCount =
      activeStep.key === "count" &&
      (selectedCountOption === CUSTOM_COUNT_OPTION ||
        deriveCountNumber(answers.count) > 25);
    if (wantsCustomCount) {
      const isValid =
        customCountValue.trim().length > 0 &&
        !Number.isNaN(Number.parseInt(customCountValue, 10)) &&
        Number.parseInt(customCountValue, 10) > 25;
      return (
        <form className="flex flex-col gap-3 text-sm text-[color:var(--ui-color-text-muted)]" onSubmit={handleCustomCountSubmit}>
          <label className="flex flex-col gap-1">
            <span>Vertel ons het exacte aantal personen</span>
            <input
              type="number"
              min={26}
              inputMode="numeric"
              value={customCountValue}
              onChange={(event) => setCustomCountValue(event.target.value.replace(/[^\d]/g, ""))}
              className="ui-input w-full rounded-2xl px-4 py-3 text-base"
              aria-describedby="count-helper"
            />
          </label>
          <span id="count-helper" className="text-xs text-[color:var(--ui-color-text-muted)]">
            Vanaf 26 personen plannen we samen een maatwerkervaring.
          </span>
          <button
            type="submit"
            className="ui-btn ui-btn--primary"
            disabled={!isValid}
          >
            Bevestig aantal
          </button>
        </form>
      );
    }
    return null;
  })();

  const handleContinue = () => {
    if (!isStepAnswered) {
      return;
    }
    advance(answers);
  };

  const progressLabel = `Stap ${stepIndex + 1} van ${TOTAL_STEPS}`;
  const progressValue = ((stepIndex + 1) / TOTAL_STEPS) * 100;

  return (
    <section
      className="ui-summary w-full max-w-md p-5"
      aria-label="Start je Den Bosch beleving"
    >
      <div className="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-[color:var(--ui-color-text-muted)]">
        <span aria-live="polite">{progressLabel}</span>
        <span>{activeStep.question}</span>
      </div>
      <div className="mt-3 h-2 rounded-full bg-[color:var(--ui-color-surface-2)]" aria-hidden="true">
        <div
          className="h-full rounded-full bg-gradient-to-r from-[color:var(--ui-color-primary)] to-[color:var(--ui-color-primary-hover)] transition-all duration-300 ease-out"
          style={{ width: `${progressValue}%` }}
        />
      </div>
      <div
        className={`mt-6 transform transition-all duration-500 ease-out ${
          isAnimating ? "opacity-0 translate-y-4" : "opacity-100 translate-y-0"
        }`}
        key={activeStep.key}
      >
        <StepCard
          question={activeStep.question}
          options={activeStep.options}
          currentValue={currentValue}
          onSelect={
            activeStep.key === "visitDate"
              ? handleVisitDateSelect
              : activeStep.key === "count"
                ? handleCountSelect
                : (value) => updateAnswer(activeStep.key, value)
          }
        >
          {supplementalContent}
        </StepCard>
      </div>
      <div className="mt-6 flex items-center justify-between">
        <button
          type="button"
          onClick={handleBack}
          className="ui-btn ui-btn--secondary inline-flex items-center gap-1"
          disabled={stepIndex === 0}
        >
          ← Terug
        </button>
        <button
          type="button"
          onClick={handleContinue}
          className="ui-btn ui-btn--primary inline-flex items-center gap-2"
          disabled={!isStepAnswered}
        >
          {stepIndex === TOTAL_STEPS - 1 ? "Bekijk advies" : "Ga verder"}
          <span aria-hidden="true">→</span>
        </button>
      </div>
    </section>
  );
};

export default HomeOnboardingWidget;
