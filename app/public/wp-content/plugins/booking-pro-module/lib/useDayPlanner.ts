import { useCallback, useMemo, useState } from "react";
import type { DayPlanItem } from "../types/DayPlanItem";

const minutesFromTime = (time: string): number => {
  const [hours, minutes] = time.split(":").map((value) => Number(value) || 0);
  return hours * 60 + minutes;
};

const sortPlan = (items: DayPlanItem[]): DayPlanItem[] =>
  [...items].sort((a, b) => minutesFromTime(a.time) - minutesFromTime(b.time));

const clonePlan = (items: DayPlanItem[]): DayPlanItem[] => items.map((item) => ({ ...item }));

export type UseDayPlannerReturn = {
  dayPlan: DayPlanItem[];
  selectedItems: DayPlanItem[];
  addItem: (item: DayPlanItem) => void;
  removeItem: (id: string) => void;
  toggleSelect: (id: string) => void;
  replaceItem: (id: string, newItem: DayPlanItem) => void;
  resetPlan: (items: DayPlanItem[]) => void;
};

export const useDayPlanner = (initialPlan: DayPlanItem[] = []): UseDayPlannerReturn => {
  const [dayPlan, setDayPlan] = useState<DayPlanItem[]>(() =>
    sortPlan(clonePlan(initialPlan))
  );

  const addItem = useCallback((item: DayPlanItem) => {
    setDayPlan((current) => {
      const exists = current.find((entry) => entry.id === item.id);
      if (exists) {
        return sortPlan(
          current.map((entry) =>
            entry.id === item.id ? { ...entry, ...item } : entry
          )
        );
      }
      return sortPlan([...current, { ...item }]);
    });
  }, []);

  const removeItem = useCallback((id: string) => {
    setDayPlan((current) => current.filter((item) => item.id !== id));
  }, []);

  const toggleSelect = useCallback((id: string) => {
    setDayPlan((current) =>
      current.map((item) =>
        item.id === id ? { ...item, selected: !item.selected } : item
      )
    );
  }, []);

  const replaceItem = useCallback((id: string, newItem: DayPlanItem) => {
    setDayPlan((current) => {
      const trimmed = current.filter(
        (item) => item.id !== id && item.id !== newItem.id
      );
      return sortPlan([...trimmed, { ...newItem }]);
    });
  }, []);

  const resetPlan = useCallback((items: DayPlanItem[]) => {
    setDayPlan(sortPlan(clonePlan(items)));
  }, []);

  const selectedItems = useMemo(
    () => dayPlan.filter((item) => item.selected),
    [dayPlan]
  );

  return {
    dayPlan,
    selectedItems,
    addItem,
    removeItem,
    toggleSelect,
    replaceItem,
    resetPlan,
  };
};
