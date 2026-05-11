export type Coordinates = {
  lat: number;
  lng: number;
};

const EARTH_RADIUS_METERS = 6_371_000;

const toRadians = (value: number): number => (value * Math.PI) / 180;

/**
 * Returns the distance in meters between two points using the haversine formula.
 */
export function getDistance(a: Coordinates, b: Coordinates): number {
  const dLat = toRadians(b.lat - a.lat);
  const dLng = toRadians(b.lng - a.lng);
  const lat1 = toRadians(a.lat);
  const lat2 = toRadians(b.lat);

  const haversine =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);

  const c = 2 * Math.atan2(Math.sqrt(haversine), Math.sqrt(1 - haversine));
  return EARTH_RADIUS_METERS * c;
}
