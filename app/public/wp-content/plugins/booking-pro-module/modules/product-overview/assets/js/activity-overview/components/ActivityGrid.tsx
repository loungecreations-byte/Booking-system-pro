import React from "react";
import ActivityCard from "./ActivityCard";
import type { Activity } from "../types";

interface ActivityGridProps {
  activities: Activity[];
  loading: boolean;
  error?: string | null;
  emptyMessage?: string;
  onSelect?: (activity: Activity) => void;
  savedIds?: Set<number>;
  selectedId?: number | null;
  onToggleSave?: (activity: Activity) => void;
  variant?: "default" | "archive";
  ctaLabel?: string;
}

export default function ActivityGrid({
  activities,
  loading,
  error,
  emptyMessage = "Geen activiteiten gevonden voor deze filters.",
  onSelect,
  savedIds = new Set<number>(),
  selectedId = null,
  onToggleSave,
  variant = "default",
  ctaLabel,
}: ActivityGridProps) {
  if (error) {
    return <p className="ao-state ao-state--error">{error}</p>;
  }

  if (loading && activities.length === 0) {
    return <p className="ao-state">Activiteiten laden…</p>;
  }

  if (!loading && activities.length === 0) {
    return <p className="ao-state">{emptyMessage}</p>;
  }

  return (
    <div className="ao-grid">
      {activities.map((activity) => (
        <ActivityCard
          key={activity.id}
          activity={activity}
          onSelect={onSelect}
          isSaved={savedIds.has(activity.id)}
          isSelected={selectedId === activity.id}
          onToggleSave={onToggleSave}
          variant={variant}
          ctaLabel={ctaLabel}
        />
      ))}
    </div>
  );
}
