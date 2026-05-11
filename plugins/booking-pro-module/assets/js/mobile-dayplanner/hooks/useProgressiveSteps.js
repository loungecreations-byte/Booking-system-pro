import { useMemo } from "react";

const STEP_KEYS = ["form", "activities", "timeline", "summary", "actions"];

export default function useProgressiveSteps({ hasPlan, hasItems }) {
  return useMemo(() => {
    const visibility = {
      form: true,
      activities: hasPlan,
      timeline: hasPlan,
      summary: hasPlan,
      actions: hasPlan && (hasItems || true),
    };

    return {
      isVisible: (key) => Boolean(visibility[key]),
      order: STEP_KEYS.slice(),
      visibility,
    };
  }, [hasPlan, hasItems]);
}

