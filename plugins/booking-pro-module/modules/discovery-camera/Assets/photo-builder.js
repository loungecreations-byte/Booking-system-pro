(function ($) {
  "use strict";

  $(document).on("click", "[data-ddb-select-media]", function () {
    const button = this;
    const type = button.dataset.ddbSelectMedia || "image";
    const target = button.dataset.ddbTarget || "";
    const field = document.querySelector(`[name="ddb_photo_challenge[${target}]"]`);
    if (!field || !window.wp?.media) return;

    const frame = window.wp.media({
      title: type === "audio" ? "Kies voice intro" : "Kies voorbeeldfoto",
      button: { text: "Gebruik dit bestand" },
      library: { type },
      multiple: false,
    });
    frame.on("select", function () {
      const attachment = frame.state().get("selection").first()?.toJSON();
      if (!attachment?.id) return;
      field.value = String(attachment.id);
      field.dispatchEvent(new Event("change", { bubbles: true }));
      button.textContent = type === "audio" ? "Voice intro geselecteerd" : "Voorbeeldfoto geselecteerd";
    });
    frame.open();
  });
}(jQuery));
