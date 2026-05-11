import { getDistance } from "../utils/getDistance";

export type Activity = {
  id: string;
  title: string;
  tags: string[];
  location: { lat: number; lng: number };
  duration: number;
  priorityUpsells?: string[];
  comboCandidates?: string[];
};

const MAX_DISTANCE_METERS = 1_000;

const pickMatches = (
  ids: string[] | undefined,
  baseId: string,
  catalog: Activity[],
): Activity[] => {
  if (!ids?.length) {
    return [];
  }

  const byId = new Map(catalog.map((activity) => [activity.id, activity]));
  const seen = new Set<string>();

  return ids.reduce<Activity[]>((acc, id) => {
    if (seen.has(id) || id === baseId) {
      return acc;
    }

    const match = byId.get(id);
    if (match) {
      acc.push(match);
      seen.add(id);
    }

    return acc;
  }, []);
};

export function getComboSuggestions(base: Activity, all: Activity[]): Activity[] {
  if (!base || !all.length) {
    return [];
  }

  const priorityMatches = pickMatches(base.priorityUpsells, base.id, all);
  if (priorityMatches.length) {
    return priorityMatches;
  }

  const comboMatches = pickMatches(base.comboCandidates, base.id, all);
  if (comboMatches.length) {
    return comboMatches;
  }

  const baseTags = new Set(base.tags);

  return all
    .filter((activity) => activity.id !== base.id)
    .map((activity) => {
      const sharedTags = activity.tags.reduce(
        (count, tag) => count + (baseTags.has(tag) ? 1 : 0),
        0,
      );
      const distance = getDistance(base.location, activity.location);

      return { activity, sharedTags, distance };
    })
    .filter(
      ({ sharedTags, distance }) => sharedTags > 0 && distance <= MAX_DISTANCE_METERS,
    )
    .sort((a, b) => {
      if (b.sharedTags !== a.sharedTags) {
        return b.sharedTags - a.sharedTags;
      }

      return a.distance - b.distance;
    })
    .slice(0, 3)
    .map(({ activity }) => activity);
}
