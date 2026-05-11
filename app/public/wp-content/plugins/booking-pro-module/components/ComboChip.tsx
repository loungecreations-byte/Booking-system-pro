import type { FC } from "react";

type ComboChipProps = {
  title: string;
  price: string;
  time: string;
  isSelected?: boolean;
  onAdd?: () => void;
  onClick?: () => void;
  className?: string;
};

const ComboChip: FC<ComboChipProps> = ({
  title,
  price,
  time,
  isSelected = false,
  onAdd,
  onClick,
  className,
}) => {
  const handleClick = () => {
    if (onAdd) {
      onAdd();
      return;
    }
    onClick?.();
  };

  const classes = [
    "ui-chip flex min-w-[240px] items-center gap-4 px-4 py-3 text-left text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--ui-color-focus)]",
    isSelected ? "ui-chip--selected shadow-md" : "shadow-sm",
    className,
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <button
      type="button"
      onClick={handleClick}
      aria-pressed={isSelected}
      className={classes}
    >
      <span className="flex flex-col leading-tight text-left">
        <span className="font-semibold">{title}</span>
        <span className="text-xs opacity-80">{time}</span>
      </span>
      <span className="flex items-center gap-3 text-sm font-semibold">
        <span>{price}</span>
        <span
          className={`grid h-8 w-8 place-items-center rounded-full border text-base ${
            isSelected
              ? "border-white bg-white/10 text-white"
              : "border-[color:var(--ui-color-border)] bg-[color:var(--ui-color-surface)] text-[color:var(--ui-color-text)]"
          }`}
        >
          {isSelected ? "✓" : "+"}
        </span>
      </span>
    </button>
  );
};

export default ComboChip;
