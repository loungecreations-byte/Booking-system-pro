import { useMemo, useState } from "react";
import { useRouter } from "next/router";
import ComboChip from "../components/ComboChip";
import { getComboSuggestions } from "../lib/getComboSuggestions";
import type { Activity } from "../lib/getComboSuggestions";
import {
  ACTIVITY_PRICING,
  DEFAULT_BASE_ACTIVITY_ID,
  POI_ACTIVITIES,
} from "../data/activities";

const formatDuration = (minutes: number): string => {
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  if (hours && remainder) {
    return `${hours}h ${remainder}m`;
  }
  if (hours) {
    return `${hours}h`;
  }
  return `${remainder}m`;
};

const ProductPlannerPage = () => {
  const router = useRouter();
  const startParam = router.query?.start;
  const startId = typeof startParam === "string" ? startParam : undefined;

  const baseActivity = useMemo(() => {
    return (
      POI_ACTIVITIES.find((activity) => activity.id === startId) ??
      POI_ACTIVITIES.find((activity) => activity.id === DEFAULT_BASE_ACTIVITY_ID) ??
      POI_ACTIVITIES[0]
    );
  }, [startId]);

  const suggestions = useMemo(() => {
    if (!baseActivity) {
      return [];
    }

    return getComboSuggestions(baseActivity, POI_ACTIVITIES).slice(0, 3);
  }, [baseActivity]);

  const [dayPlan, setDayPlan] = useState<Activity[]>([]);

  const handleComboClick = (activity: Activity) => {
    console.log("combo-selected", activity.id);
    setDayPlan((current) => {
      if (current.some((item) => item.id === activity.id)) {
        return current;
      }
      return [...current, activity];
    });
  };

  if (!baseActivity) {
    return (
      <main className="ddb-app ui-scope flex min-h-screen items-center justify-center bg-[color:var(--ui-color-bg)] p-6 text-[color:var(--ui-color-text-muted)]">
        Unable to load planner content.
      </main>
    );
  }

  return (
    <main className="ddb-app ui-scope mx-auto flex min-h-screen max-w-4xl flex-col gap-10 bg-[color:var(--ui-color-bg)] px-4 py-10 text-[color:var(--ui-color-text)]">
      <section className="ui-card ui-motion-surface ui-surface--elevated p-8">
        <p className="text-xs font-semibold uppercase tracking-widest text-[color:var(--ui-color-text-muted)]">
          Base activity
        </p>
        <h1 className="mt-2 text-3xl font-semibold text-[color:var(--ui-color-text)]">{baseActivity.title}</h1>
        <p className="mt-3 text-sm text-[color:var(--ui-color-text-muted)]">
          Duration: {formatDuration(baseActivity.duration)} | Tags: {baseActivity.tags.join(", ")}
        </p>
      </section>

      <section className="ui-summary">
        <div className="flex flex-col gap-1">
          <h2 className="text-lg font-semibold text-[color:var(--ui-color-text)]">Suggested combos</h2>
          <p className="text-sm text-[color:var(--ui-color-text-muted)]">
            Based on upsells, curated bundles, and nearby tagged matches.
          </p>
        </div>
        <div className="flex flex-wrap gap-3">
          {suggestions.length ? (
            suggestions.map((activity) => (
              <ComboChip
                key={activity.id}
                title={activity.title}
                price={ACTIVITY_PRICING[activity.id] ?? "EUR 39"}
                time={formatDuration(activity.duration)}
                onClick={() => handleComboClick(activity)}
              />
            ))
          ) : (
            <span className="text-sm text-[color:var(--ui-color-text-muted)]">
              No combos available within 1 km for this activity.
            </span>
          )}
        </div>
      </section>

      <section className="ui-summary">
        <div className="flex flex-col gap-1">
          <h2 className="text-lg font-semibold text-[color:var(--ui-color-text)]">Day plan</h2>
          <p className="text-sm text-[color:var(--ui-color-text-muted)]">
            Tap a combo to log it. Items stay local to this session.
          </p>
        </div>
        {dayPlan.length === 0 ? (
          <p className="mt-6 text-sm text-[color:var(--ui-color-text-muted)]">
            Nothing planned yet. Choose a combo above to kick things off.
          </p>
        ) : (
          <ul className="mt-6 space-y-3">
            {dayPlan.map((item) => (
              <li
                key={item.id}
                className="flex items-center justify-between rounded-2xl border border-[color:var(--ui-color-border)] bg-[color:var(--ui-color-surface)] px-4 py-3 shadow-sm"
              >
                <span className="text-sm font-medium text-[color:var(--ui-color-text)]">{item.title}</span>
                <span className="text-xs text-[color:var(--ui-color-text-muted)]">{formatDuration(item.duration)}</span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </main>
  );
};

export default ProductPlannerPage;
