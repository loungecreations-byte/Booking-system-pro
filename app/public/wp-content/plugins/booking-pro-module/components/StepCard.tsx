import { type FC, type ReactNode, useEffect, useId, useRef } from "react";

type StepCardProps = {
  question: string;
  options: string[];
  currentValue?: string;
  onSelect: (value: string) => void;
  children?: ReactNode;
};

const focusableSelector =
  'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

const StepCard: FC<StepCardProps> = ({
  question,
  options,
  currentValue,
  onSelect,
  children,
}) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const questionId = useId();

  useEffect(() => {
    const node = containerRef.current;
    if (!node) {
      return;
    }

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Tab") {
        return;
      }
      const focusable = node.querySelectorAll<HTMLElement>(focusableSelector);
      if (!focusable.length) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey) {
        if (document.activeElement === first) {
          event.preventDefault();
          last.focus();
        }
        return;
      }
      if (document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    node.addEventListener("keydown", handleKeyDown);
    return () => node.removeEventListener("keydown", handleKeyDown);
  }, [options]);

  useEffect(() => {
    const node = containerRef.current;
    if (!node) {
      return;
    }
    const firstButton = node.querySelector<HTMLButtonElement>("button");
    firstButton?.focus();
  }, [question]);

  return (
    <div
      ref={containerRef}
      className="ui-card ui-motion-surface ui-surface--elevated rounded-3xl p-5"
      tabIndex={-1}
      aria-labelledby={questionId}
    >
      <h2 id={questionId} className="text-lg font-semibold text-[color:var(--ui-color-text)]">
        {question}
      </h2>
      <div
        className="mt-4 flex flex-wrap gap-2"
        role="group"
        aria-label={question}
      >
        {options.map((option) => {
          const isSelected = currentValue === option;
          return (
            <button
              key={option}
              type="button"
              onClick={() => onSelect(option)}
              className={`ui-chip rounded-full border px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--ui-color-focus)] ${
                isSelected
                  ? "ui-chip--selected shadow-sm"
                  : "bg-[color:var(--ui-color-surface)] text-[color:var(--ui-color-text-muted)]"
              }`}
              aria-pressed={isSelected}
            >
              {option}
            </button>
          );
        })}
      </div>
      {children ? <div className="mt-4">{children}</div> : null}
    </div>
  );
};

export default StepCard;
