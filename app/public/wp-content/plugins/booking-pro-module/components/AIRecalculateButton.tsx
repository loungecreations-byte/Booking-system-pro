import { useCallback, useState, type FC } from "react";

type AIRecalculateButtonProps = {
  onRecalculate: () => Promise<void> | void;
};

const AIRecalculateButton: FC<AIRecalculateButtonProps> = ({ onRecalculate }) => {
  const [status, setStatus] = useState<"idle" | "loading" | "done">("idle");

  const handleClick = useCallback(async () => {
    if (status === "loading") {
      return;
    }
    setStatus("loading");
    try {
      await Promise.resolve(onRecalculate());
      setStatus("done");
      setTimeout(() => setStatus("idle"), 1600);
    } catch (error) {
      console.error("AI recalculation failed", error);
      setStatus("idle");
    }
  }, [onRecalculate, status]);

  return (
    <div className="flex flex-col gap-2">
      <button
        type="button"
        onClick={handleClick}
        className="ui-btn ui-btn--primary"
        disabled={status === "loading"}
      >
        Laat Bosch opnieuw plannen
      </button>
      <span className="text-xs text-[color:var(--ui-color-text-muted)]">
        {status === "loading"
          ? "AI herberekent jouw volgorde…"
          : status === "done"
            ? "Nieuwe volgorde klaar! Controleer de suggesties."
            : "Gebruik AI om reistijd te beperken en boekbare blokken te bundelen."}
      </span>
    </div>
  );
};

export default AIRecalculateButton;
