(() => {
  "use strict";

  const config = window.DDBMegaMenuAdminConfig || {};
  const root = document.getElementById("ddb-mega-menu-builder");
  const textarea = document.getElementById("custom_menu_structure_json");

  if (!root || !textarea) {
    return;
  }

  const deepClone = (value) => JSON.parse(JSON.stringify(value || []));
  const toText = (value) => String(value || "").trim();
  const toSlug = (value) =>
    toText(value)
      .toLowerCase()
      .replace(/[^a-z0-9-]+/g, "-")
      .replace(/^-+|-+$/g, "");

  let state = [];
  let syncingTextarea = false;

  const h = (tag, className = "", text = "") => {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text) {
      node.textContent = text;
    }
    return node;
  };

  const syncTextarea = () => {
    syncingTextarea = true;
    textarea.value = JSON.stringify(state, null, 2);
    syncingTextarea = false;
  };

  const rebuild = () => {
    root.innerHTML = "";

    const topBar = h("div", "ddb-mm-topbar");
    const addButton = h("button", "button button-primary", "Add top item");
    addButton.type = "button";
    addButton.addEventListener("click", () => {
      state.push({
        id: "new-item",
        label: "Nieuw item",
        url: "/",
        kind: "mega",
        columns: [
          {
            title: "Kolom",
            links: [{ label: "Link", url: "/" }],
          },
        ],
      });
      rebuild();
    });

    const resetButton = h("button", "button", "Reset defaults");
    resetButton.type = "button";
    resetButton.addEventListener("click", () => {
      state = deepClone(config.defaultItems || []);
      rebuild();
    });

    topBar.appendChild(addButton);
    topBar.appendChild(resetButton);
    root.appendChild(topBar);

    state.forEach((item, itemIndex) => {
      const card = h("details", "ddb-mm-item");
      card.open = true;

      const summary = h("summary", "ddb-mm-item__summary");
      summary.textContent = `${item.label || "Menu item"} (${item.kind || "link"})`;
      card.appendChild(summary);

      const body = h("div", "ddb-mm-item__body");

      const metaGrid = h("div", "ddb-mm-grid");
      const idInput = renderInput("ID", item.id || "", (val) => {
        item.id = toSlug(val) || `item-${itemIndex + 1}`;
        rebuild();
      });
      const labelInput = renderInput("Label", item.label || "", (val) => {
        item.label = toText(val);
        rebuild();
      });
      const urlInput = renderInput("URL", item.url || "", (val) => {
        item.url = toText(val);
      });
      const kindInput = renderSelect(
        "Type",
        item.kind || "link",
        [
          ["mega", "mega"],
          ["dropdown", "dropdown"],
          ["link", "link"],
        ],
        (val) => {
          item.kind = val;
          if (val === "mega" && !Array.isArray(item.columns)) {
            item.columns = [{ title: "Kolom", links: [{ label: "Link", url: "/" }] }];
          }
          if (val === "dropdown" && !Array.isArray(item.links)) {
            item.links = [{ label: "Link", url: "/" }];
          }
          rebuild();
        }
      );

      metaGrid.appendChild(idInput);
      metaGrid.appendChild(labelInput);
      metaGrid.appendChild(urlInput);
      metaGrid.appendChild(kindInput);
      body.appendChild(metaGrid);

      if ((item.kind || "link") === "mega") {
        body.appendChild(renderColumnsEditor(item));
        body.appendChild(renderHighlightEditor(item));
        body.appendChild(renderFooterCtaEditor(item));
      }

      if ((item.kind || "link") === "dropdown") {
        body.appendChild(renderLinksEditor(item, "links", "Dropdown links"));
      }

      const actions = h("div", "ddb-mm-actions");
      const upButton = h("button", "button", "Up");
      upButton.type = "button";
      upButton.disabled = itemIndex === 0;
      upButton.addEventListener("click", () => {
        if (itemIndex <= 0) return;
        const current = state[itemIndex];
        state[itemIndex] = state[itemIndex - 1];
        state[itemIndex - 1] = current;
        rebuild();
      });

      const downButton = h("button", "button", "Down");
      downButton.type = "button";
      downButton.disabled = itemIndex >= state.length - 1;
      downButton.addEventListener("click", () => {
        if (itemIndex >= state.length - 1) return;
        const current = state[itemIndex];
        state[itemIndex] = state[itemIndex + 1];
        state[itemIndex + 1] = current;
        rebuild();
      });

      const duplicateButton = h("button", "button", "Duplicate");
      duplicateButton.type = "button";
      duplicateButton.addEventListener("click", () => {
        const copy = deepClone(item);
        copy.id = `${toSlug(copy.id || copy.label || "item")}-copy`;
        state.splice(itemIndex + 1, 0, copy);
        rebuild();
      });

      const removeButton = h("button", "button button-link-delete", "Remove");
      removeButton.type = "button";
      removeButton.addEventListener("click", () => {
        state.splice(itemIndex, 1);
        rebuild();
      });

      actions.appendChild(upButton);
      actions.appendChild(downButton);
      actions.appendChild(duplicateButton);
      actions.appendChild(removeButton);
      body.appendChild(actions);

      card.appendChild(body);
      root.appendChild(card);
    });

    syncTextarea();
  };

  const renderInput = (labelText, value, onChange) => {
    const wrap = h("label", "ddb-mm-field");
    wrap.appendChild(h("span", "ddb-mm-field__label", labelText));
    const input = h("input", "regular-text");
    input.type = "text";
    input.value = value;
    input.addEventListener("input", () => {
      onChange(input.value);
      syncTextarea();
    });
    wrap.appendChild(input);
    return wrap;
  };

  const renderSelect = (labelText, value, options, onChange) => {
    const wrap = h("label", "ddb-mm-field");
    wrap.appendChild(h("span", "ddb-mm-field__label", labelText));
    const select = h("select", "");
    options.forEach(([val, label]) => {
      const option = h("option", "", label);
      option.value = val;
      if (value === val) {
        option.selected = true;
      }
      select.appendChild(option);
    });
    select.addEventListener("change", () => {
      onChange(select.value);
      syncTextarea();
    });
    wrap.appendChild(select);
    return wrap;
  };

  const openMediaFrame = (onSelect) => {
    if (!window.wp || !wp.media) {
      return;
    }

    const frame = wp.media({
      title: "Selecteer afbeelding",
      button: { text: "Gebruik afbeelding" },
      library: { type: "image" },
      multiple: false,
    });

    frame.on("select", () => {
      const attachment = frame.state().get("selection").first();
      if (!attachment) {
        return;
      }

      onSelect(attachment.toJSON() || {});
    });

    frame.open();
  };

  const renderLinksEditor = (item, key, title) => {
    if (!Array.isArray(item[key])) {
      item[key] = [];
    }

    const section = h("section", "ddb-mm-block");
    section.appendChild(h("h4", "ddb-mm-block__title", title));

    item[key].forEach((link, index) => {
      const row = h("div", "ddb-mm-link");
      row.appendChild(
        renderInput("Label", link.label || "", (val) => {
          link.label = toText(val);
        })
      );
      row.appendChild(
        renderInput("URL", link.url || "", (val) => {
          link.url = toText(val);
        })
      );
      const remove = h("button", "button button-link-delete", "Remove link");
      remove.type = "button";
      remove.addEventListener("click", () => {
        item[key].splice(index, 1);
        rebuild();
      });
      row.appendChild(remove);
      section.appendChild(row);
    });

    const add = h("button", "button", "Add link");
    add.type = "button";
    add.addEventListener("click", () => {
      item[key].push({ label: "Nieuwe link", url: "/" });
      rebuild();
    });
    section.appendChild(add);

    return section;
  };

  const renderColumnsEditor = (item) => {
    if (!Array.isArray(item.columns)) {
      item.columns = [];
    }

    const section = h("section", "ddb-mm-block");
    section.appendChild(h("h4", "ddb-mm-block__title", "Mega columns"));

    item.columns.forEach((column, columnIndex) => {
      const col = h("div", "ddb-mm-column");
      col.appendChild(
        renderInput("Column title", column.title || "", (val) => {
          column.title = toText(val);
        })
      );
      col.appendChild(renderLinksEditor(column, "links", "Column links"));
      const remove = h("button", "button button-link-delete", "Remove column");
      remove.type = "button";
      remove.addEventListener("click", () => {
        item.columns.splice(columnIndex, 1);
        rebuild();
      });
      col.appendChild(remove);
      section.appendChild(col);
    });

    const add = h("button", "button", "Add column");
    add.type = "button";
    add.addEventListener("click", () => {
      item.columns.push({ title: "Nieuwe kolom", links: [{ label: "Link", url: "/" }] });
      rebuild();
    });
    section.appendChild(add);

    return section;
  };

  const renderHighlightEditor = (item) => {
    if (!item.highlight || typeof item.highlight !== "object") {
      item.highlight = {};
    }

    const section = h("section", "ddb-mm-block");
    section.appendChild(h("h4", "ddb-mm-block__title", "Highlight card"));
    section.appendChild(
      renderInput("Eyebrow", item.highlight.eyebrow || "", (val) => {
        item.highlight.eyebrow = toText(val);
      })
    );
    section.appendChild(
      renderInput("Title", item.highlight.title || "", (val) => {
        item.highlight.title = toText(val);
      })
    );
    section.appendChild(
      renderInput("Text", item.highlight.text || "", (val) => {
        item.highlight.text = toText(val);
      })
    );
    section.appendChild(
      renderInput("Image URL", item.highlight.image_url || "", (val) => {
        item.highlight.image_url = toText(val);
      })
    );
    section.appendChild(
      renderInput("Image alt", item.highlight.image_alt || "", (val) => {
        item.highlight.image_alt = toText(val);
      })
    );
    const mediaActions = h("div", "ddb-mm-media-actions");
    const chooseImageButton = h("button", "button", "Select image");
    chooseImageButton.type = "button";
    chooseImageButton.addEventListener("click", () => {
      openMediaFrame((attachment) => {
        item.highlight.image_url = toText(attachment.url || "");
        if (!toText(item.highlight.image_alt)) {
          item.highlight.image_alt = toText(attachment.alt || attachment.title || "");
        }
        rebuild();
      });
    });
    mediaActions.appendChild(chooseImageButton);

    const clearImageButton = h("button", "button", "Remove image");
    clearImageButton.type = "button";
    clearImageButton.disabled = !toText(item.highlight.image_url);
    clearImageButton.addEventListener("click", () => {
      item.highlight.image_url = "";
      item.highlight.image_alt = "";
      rebuild();
    });
    mediaActions.appendChild(clearImageButton);
    section.appendChild(mediaActions);

    if (toText(item.highlight.image_url)) {
      const preview = h("div", "ddb-mm-media-preview");
      const previewImg = h("img", "");
      previewImg.src = toText(item.highlight.image_url);
      previewImg.alt = toText(item.highlight.image_alt || "Highlight image");
      preview.appendChild(previewImg);
      section.appendChild(preview);
    }
    section.appendChild(
      renderInput("CTA label", item.highlight.cta_label || "", (val) => {
        item.highlight.cta_label = toText(val);
      })
    );
    section.appendChild(
      renderInput("CTA URL", item.highlight.cta_url || "", (val) => {
        item.highlight.cta_url = toText(val);
      })
    );
    return section;
  };

  const renderFooterCtaEditor = (item) => {
    if (!item.footer_cta || typeof item.footer_cta !== "object") {
      item.footer_cta = {};
    }

    const section = h("section", "ddb-mm-block");
    section.appendChild(h("h4", "ddb-mm-block__title", "Footer CTA"));
    section.appendChild(
      renderInput("Label", item.footer_cta.label || "", (val) => {
        item.footer_cta.label = toText(val);
      })
    );
    section.appendChild(
      renderInput("URL", item.footer_cta.url || "", (val) => {
        item.footer_cta.url = toText(val);
      })
    );
    return section;
  };

  const parseTextarea = () => {
    const value = toText(textarea.value);
    if (!value) {
      return [];
    }
    try {
      const parsed = JSON.parse(value);
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  };

  const seed = Array.isArray(config.storedItems) && config.storedItems.length
    ? deepClone(config.storedItems)
    : parseTextarea().length
    ? parseTextarea()
    : deepClone(config.defaultItems || []);

  state = Array.isArray(seed) ? seed : [];
  rebuild();

  textarea.addEventListener("change", () => {
    if (syncingTextarea) {
      return;
    }
    const parsed = parseTextarea();
    if (parsed.length) {
      state = parsed;
      rebuild();
    }
  });
})();
