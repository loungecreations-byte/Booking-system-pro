import type { Activity } from "../lib/getComboSuggestions";

export const POI_ACTIVITIES: Activity[] = [
  {
    id: "bosch-tour",
    title: "Bosch Immersive Gallery",
    tags: ["art", "heritage", "indoor"],
    location: { lat: 52.359997, lng: 4.885218 },
    duration: 90,
    priorityUpsells: ["canal-cruise", "rijks-private"],
  },
  {
    id: "canal-cruise",
    title: "Evening Canal Cruise",
    tags: ["water", "relax", "family"],
    location: { lat: 52.360998, lng: 4.902222 },
    duration: 60,
  },
  {
    id: "rijks-private",
    title: "Rijksmuseum Private Tour",
    tags: ["art", "exclusive", "heritage"],
    location: { lat: 52.360402, lng: 4.885247 },
    duration: 75,
  },
  {
    id: "bike-escape",
    title: "Canal Rim Bike Escape",
    tags: ["outdoors", "fitness", "local"],
    location: { lat: 52.365062, lng: 4.892154 },
    duration: 120,
    comboCandidates: ["street-food-loop", "jordaan-walk"],
  },
  {
    id: "street-food-loop",
    title: "Street Food Loop",
    tags: ["food", "local", "outdoors"],
    location: { lat: 52.372004, lng: 4.889678 },
    duration: 80,
  },
  {
    id: "jordaan-walk",
    title: "Jordaan Hidden Walk",
    tags: ["history", "local", "outdoors"],
    location: { lat: 52.373332, lng: 4.883512 },
    duration: 50,
  },
  {
    id: "craft-brew-hop",
    title: "Craft Brew Hop",
    tags: ["food", "nightlife", "local"],
    location: { lat: 52.374812, lng: 4.900871 },
    duration: 110,
  },
];

export const ACTIVITY_PRICING: Record<string, string> = {
  "bosch-tour": "EUR 54",
  "canal-cruise": "EUR 42",
  "rijks-private": "EUR 89",
  "bike-escape": "EUR 65",
  "street-food-loop": "EUR 48",
  "jordaan-walk": "EUR 32",
  "craft-brew-hop": "EUR 58",
};

export const DEFAULT_BASE_ACTIVITY_ID = "bosch-tour";
