import { useEffect, useRef } from "react";
import PropTypes from "prop-types";

import { usePlanner } from "../../day-planner/store/PlannerProvider.jsx";

export default function PlannerStateSync({ autoSaveSeconds }) {
  const {
    state,
    actions: { savePlan },
  } = usePlanner();

  const debounceRef = useRef(null);

  const hasPlan = state.plan.days.length > 0;

  useEffect(() => {
    if (!hasPlan) {
      return undefined;
    }

    const interval = setInterval(() => {
      savePlan({ silent: true }).catch(() => {});
    }, Math.max(5, autoSaveSeconds) * 1000);

    return () => clearInterval(interval);
  }, [hasPlan, autoSaveSeconds, savePlan]);

  useEffect(() => {
    if (!hasPlan) {
      return undefined;
    }

    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }

    debounceRef.current = setTimeout(() => {
      savePlan({ silent: true }).catch(() => {});
    }, 1500);

    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
        debounceRef.current = null;
      }
    };
  }, [hasPlan, state.plan.items, state.plan.participants, savePlan]);

  useEffect(() => {
    if (!hasPlan) {
      return undefined;
    }

    const handleBeforeUnload = () => {
      try {
        savePlan({ silent: true }).catch(() => {});
      } catch (error) {
        // ignore
      }
    };

    window.addEventListener("beforeunload", handleBeforeUnload);

    return () => {
      window.removeEventListener("beforeunload", handleBeforeUnload);
      savePlan({ silent: true }).catch(() => {});
    };
  }, [hasPlan, savePlan]);

  return null;
}

PlannerStateSync.propTypes = {
  autoSaveSeconds: PropTypes.number,
};

PlannerStateSync.defaultProps = {
  autoSaveSeconds: 15,
};

