export type DayPlanItem = {
  id: string;
  title: string;
  time: string;
  duration: number;
  location: string;
  price?: number;
  bookable: boolean;
  selected: boolean;
};
