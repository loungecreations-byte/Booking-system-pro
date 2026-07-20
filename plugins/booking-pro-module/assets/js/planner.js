(function (window, document) {
  "use strict";
  const DEFAULT_TOTALS = {
    subtotal: 0,
    fee: 0,
    total: 0,
  };
  const FILTER_LABELS = {
    duration: {
      any: "Alle duren",
      60: "Tot 60 min",
      90: "Tot 90 min",
      longer: "Langer dan 90 min",
    },
    price: {
      all: "Alle prijzen",
      low: "€0 - €25",
      mid: "€25 - €75",
      high: "€75+",
    },
  };
  const CATEGORY_DEFAULT_LABEL = "Alle categorieën";
  const Planner = {
    state: {
      root: null,
      restBase: "",
      nonce: "",
      date: null,
      participants: null,
      products: [],
      filtered: [],
      items: [],
      planId: null,
      sessionId: null,
      totals: {
        ...DEFAULT_TOTALS,
      },
      availability: {},
      filter: {
        query: "",
        duration: "any",
        category: "all",
        price: "all",
      },
      filterLabels: {
        duration: FILTER_LABELS.duration.any,
        category: CATEGORY_DEFAULT_LABEL,
        price: FILTER_LABELS.price.all,
      },
      customer: {
        name: "",
        email: "",
        phone: "",
      },
    },
    ui: {},
    priceTimer: null,
    init(config) {
      if (!config || !config.root) {
        throw new Error("BPMPlanner requires a root element.");
      }
      this.state.root = config.root;
      this.state.restBase = (config.restBase || config.rest_base || "").replace(
        /\/$/,
        "",
      );
      this.state.nonce = config.nonce || "";
      this.state.sessionId =
        config.sessionId ||
        config.session_id ||
        config.root.dataset.sessionId ||
        null;
      this.state.date = config.defaultDate || this.getToday();
      this.buildLayout();
      this.bindEvents();
      console.log(
        "[BPMPlanner] Initialising (session=%s)",
        this.state.sessionId,
      );
      this.loadProducts().then(() => {
        if (this.state.sessionId) {
          this.loadPlan(this.state.sessionId);
        } else {
          this.render();
        }
      });
    },
    setCustomerInfo(name, email, phone) {
      this.state.customer = {
        name: typeof name === "string" ? name.trim() : "",
        email: typeof email === "string" ? email.trim() : "",
        phone: typeof phone === "string" ? phone.trim() : "",
      };
      if (this.ui.customerNameInput) {
        this.ui.customerNameInput.value = this.state.customer.name;
      }
      if (this.ui.customerEmailInput) {
        this.ui.customerEmailInput.value = this.state.customer.email;
      }
      if (this.ui.customerPhoneInput) {
        this.ui.customerPhoneInput.value = this.state.customer.phone;
      }
      this.setupFilterControls();
      this.updateSubmitState();
    },
    setupFilterControls() {
      if (!this.ui.filterBar) {
        return;
      }
      this.ui.filterTriggers = Array.from(
        this.ui.filterBar.querySelectorAll(".bpm-filter__trigger"),
      );
      this.ui.filterMenus = {};
      this.ui.filterTriggers.forEach((trigger) => {
        const filterType = trigger.getAttribute("data-filter");
        if (!filterType) {
          return;
        }
        const menu = this.ui.filterBar.querySelector(
          `.bpm-filter__menu[data-filter-menu="${filterType}"]`,
        );
        if (menu) {
          this.ui.filterMenus[filterType] = menu;
          menu.addEventListener("click", (event) => {
            const target = event.target;
            if (!target || target.tagName !== "BUTTON") {
              return;
            }
            const value = target.getAttribute("data-value");
            if (!value) {
              return;
            }
            this.applyFilterSelection(
              filterType,
              value,
              target.textContent || "",
            );
          });
        }
        trigger.addEventListener("click", (event) => {
          event.preventDefault();
          this.toggleFilterMenu(filterType);
        });
      });
      if (!this.ui.boundFilterDismiss) {
        this.ui.boundFilterDismiss = (event) => {
          if (this.ui.filterBar && this.ui.filterBar.contains(event.target)) {
            return;
          }
          this.closeAllFilterMenus();
        };
        document.addEventListener("click", this.ui.boundFilterDismiss);
      }
      this.renderCategoryFilter();
      this.updateFilterTriggerLabels();
      this.renderFilterTags();
    },
    renderCategoryFilter() {
      if (!this.ui.filterMenus || !this.ui.filterMenus.category) {
        return;
      }
      const menu = this.ui.filterMenus.category;
      const categories = this.collectCategories();
      const options = [
        `<button type="button" data-value="all">${CATEGORY_DEFAULT_LABEL}</button>`,
      ];
      categories.forEach((entry) => {
        options.push(
          `<button type="button" data-value="${entry.slug}">${entry.label}</button>`,
        );
      });
      menu.innerHTML = options.join("");
    },
    collectCategories() {
      const map = new Map();
      this.state.products.forEach((product) => {
        const names = Array.isArray(product.categories)
          ? product.categories
          : [];
        const slugs = Array.isArray(product.category_slugs)
          ? product.category_slugs
          : [];
        slugs.forEach((slug, index) => {
          const normalisedSlug = typeof slug === "string" ? slug.trim() : "";
          if (!normalisedSlug || map.has(normalisedSlug)) {
            return;
          }
          const label =
            typeof names[index] === "string" && names[index].trim() !== ""
              ? names[index].trim()
              : normalisedSlug.replace(/[-_]/g, " ");
          map.set(normalisedSlug, { slug: normalisedSlug, label });
        });
      });
      return Array.from(map.values()).sort((a, b) =>
        a.label.localeCompare(b.label),
      );
    },
    toggleFilterMenu(filterKey) {
      if (
        !filterKey ||
        !this.ui.filterMenus ||
        !this.ui.filterMenus[filterKey]
      ) {
        return;
      }
      if (this.ui.openFilter === filterKey) {
        this.closeAllFilterMenus();
        return;
      }
      this.closeAllFilterMenus();
      const trigger = this.ui.filterTriggers.find(
        (button) => button.getAttribute("data-filter") === filterKey,
      );
      const menu = this.ui.filterMenus[filterKey];
      if (trigger && menu) {
        this.ui.openFilter = filterKey;
        trigger.classList.add("is-open");
        trigger.setAttribute("aria-expanded", "true");
        const parent = trigger.parentElement;
        if (parent) {
          parent.classList.add("is-open");
        }
        menu.hidden = false;
      }
    },
    closeAllFilterMenus() {
      if (!this.ui.filterTriggers || !this.ui.filterMenus) {
        return;
      }
      this.ui.filterTriggers.forEach((trigger) => {
        trigger.classList.remove("is-open");
        trigger.setAttribute("aria-expanded", "false");
        const parent = trigger.parentElement;
        if (parent) {
          parent.classList.remove("is-open");
        }
      });
      Object.keys(this.ui.filterMenus).forEach((key) => {
        const menu = this.ui.filterMenus[key];
        if (menu) {
          menu.hidden = true;
        }
      });
      this.ui.openFilter = null;
    },
    applyFilterSelection(filterType, value, label) {
      if (!filterType) {
        return;
      }
      if (filterType === "duration") {
        this.state.filter.duration = value;
        this.state.filterLabels.duration =
          FILTER_LABELS.duration[value] || FILTER_LABELS.duration.any;
      } else if (filterType === "category") {
        this.state.filter.category = value;
        this.state.filterLabels.category =
          value === "all"
            ? CATEGORY_DEFAULT_LABEL
            : (label && label.trim()) || CATEGORY_DEFAULT_LABEL;
      } else if (filterType === "price") {
        this.state.filter.price = value;
        this.state.filterLabels.price =
          FILTER_LABELS.price[value] || FILTER_LABELS.price.all;
      }
      this.updateFilterTriggerLabels();
      this.renderFilterTags();
      this.closeAllFilterMenus();
      this.filter(this.state.filter);
    },
    resetFilter(filterType) {
      if (filterType === "duration") {
        this.state.filter.duration = "any";
        this.state.filterLabels.duration = FILTER_LABELS.duration.any;
      } else if (filterType === "category") {
        this.state.filter.category = "all";
        this.state.filterLabels.category = CATEGORY_DEFAULT_LABEL;
      } else if (filterType === "price") {
        this.state.filter.price = "all";
        this.state.filterLabels.price = FILTER_LABELS.price.all;
      }
      this.updateFilterTriggerLabels();
      this.renderFilterTags();
      this.filter(this.state.filter);
    },
    updateFilterTriggerLabels() {
      if (!this.ui.filterBar) {
        return;
      }
      if (!this.ui.filterTriggers) {
        this.ui.filterTriggers = Array.from(
          this.ui.filterBar.querySelectorAll(".bpm-filter__trigger"),
        );
      }
      this.ui.filterTriggers.forEach((trigger) => {
        const filterType = trigger.getAttribute("data-filter");
        const labelNode = trigger.querySelector(".bpm-filter__label");
        if (!filterType || !labelNode) {
          return;
        }
        let text = "";
        if (filterType === "duration") {
          text = this.state.filterLabels.duration || FILTER_LABELS.duration.any;
        } else if (filterType === "category") {
          text = this.state.filterLabels.category || CATEGORY_DEFAULT_LABEL;
        } else if (filterType === "price") {
          text = this.state.filterLabels.price || FILTER_LABELS.price.all;
        }
        labelNode.textContent = text;
        trigger.setAttribute(
          "aria-expanded",
          this.ui.openFilter === filterType ? "true" : "false",
        );
      });
    },
    renderFilterTags() {
      if (!this.ui.filterTags) {
        return;
      }
      const tags = [];
      if (this.state.filter.duration && this.state.filter.duration !== "any") {
        tags.push({
          type: "duration",
          label: this.state.filterLabels.duration,
        });
      }
      if (this.state.filter.category && this.state.filter.category !== "all") {
        tags.push({
          type: "category",
          label: this.state.filterLabels.category,
        });
      }
      if (this.state.filter.price && this.state.filter.price !== "all") {
        tags.push({ type: "price", label: this.state.filterLabels.price });
      }
      if (tags.length === 0) {
        this.ui.filterTags.innerHTML = "";
        this.ui.filterTags.classList.remove("has-tags");
        return;
      }
      this.ui.filterTags.classList.add("has-tags");
      this.ui.filterTags.innerHTML = tags
        .map(
          (tag) =>
            `<button type="button" class="bpm-filter-tag" data-filter-remove="${tag.type}">${tag.label}<span aria-hidden="true">×</span></button>`,
        )
        .join("");
      Array.from(
        this.ui.filterTags.querySelectorAll("button[data-filter-remove]"),
      ).forEach((button) => {
        button.addEventListener("click", (event) => {
          event.preventDefault();
          const filterType = button.getAttribute("data-filter-remove");
          if (!filterType) {
            return;
          }
          this.resetFilter(filterType);
        });
      });
    },
    getCustomerInfo() {
      return {
        name: (this.state.customer.name || "").trim(),
        email: (this.state.customer.email || "").trim(),
        phone: (this.state.customer.phone || "").trim(),
      };
    },
    canSubmit() {
      const info = this.getCustomerInfo();
      return (
        info.name !== "" && info.email !== "" && this.state.items.length > 0
      );
    },
    updateSubmitState() {
      const allowSubmit = this.canSubmit();
      if (this.ui.submitButton) {
        this.ui.submitButton.disabled = !allowSubmit;
        if (!allowSubmit) {
          this.ui.submitButton.setAttribute("aria-disabled", "true");
        } else {
          this.ui.submitButton.removeAttribute("aria-disabled");
        }
      }
    },
    buildLayout() {
      const root = this.state.root;
      root.classList.add("bpm-planner");
      const grid = document.createElement("div");
      grid.className = "bpm-planner__grid";
      const productsColumn = document.createElement("section");
      productsColumn.className =
        "bpm-planner__column bpm-planner__column--products";
      productsColumn.innerHTML = `                <header class="bpm-planner__header">                    <h2>Activiteiten</h2>                    <div class="bpm-filter-bar">                        <input type="search" class="bpm-planner__search" placeholder="Zoek product..." aria-label="Zoek product">                        <div class="bpm-filter" data-filter="duration">                            <button type="button" class="bpm-filter__trigger" data-filter="duration" aria-expanded="false">                                <span class="bpm-filter__label">Alle duren</span>                            </button>                            <div class="bpm-filter__menu" data-filter-menu="duration" hidden>                                <button type="button" data-value="any">Alle duren</button>                                <button type="button" data-value="60">Tot 60 min</button>                                <button type="button" data-value="90">Tot 90 min</button>                                <button type="button" data-value="longer">Langer dan 90 min</button>                            </div>                        </div>                        <div class="bpm-filter" data-filter="category">                            <button type="button" class="bpm-filter__trigger" data-filter="category" aria-expanded="false">                                <span class="bpm-filter__label">Alle categorieën</span>                            </button>                            <div class="bpm-filter__menu" data-filter-menu="category" hidden></div>                        </div>                        <div class="bpm-filter" data-filter="price">                            <button type="button" class="bpm-filter__trigger" data-filter="price" aria-expanded="false">                                <span class="bpm-filter__label">Alle prijzen</span>                            </button>                            <div class="bpm-filter__menu" data-filter-menu="price" hidden>                                <button type="button" data-value="all">Alle prijzen</button>                                <button type="button" data-value="low">€0 - €25</button>                                <button type="button" data-value="mid">€25 - €75</button>                                <button type="button" data-value="high">€75+</button>                            </div>                        </div>                    </div>                    <div class="bpm-filter-tags" aria-live="polite"></div>                </header>                <div class="bpm-planner__product-list"></div>            `;
      const scheduleColumn = document.createElement("section");
      scheduleColumn.className =
        "bpm-planner__column bpm-planner__column--schedule";
      scheduleColumn.innerHTML = `                <header class="bpm-planner__header">                    <h2>Dagindeling</h2>                    <label class="bpm-planner__field">                        <span>Datum</span>                        <input type="date" class="bpm-planner__date">                    </label>                </header>                <ul class="bpm-planner__items"></ul>            `;
      const overviewColumn = document.createElement("section");
      overviewColumn.className =
        "bpm-planner__column bpm-planner__column--overview";
      overviewColumn.innerHTML = `                <header class="bpm-planner__header">                    <h2>Overzicht</h2>                </header>                <div class="bpm-planner__field">                    <label for="bpm-participants">Deelnemers</label>                    <input id="bpm-participants" class="bpm-planner__participants" type="number" min="1" value="">                </div>                <div class="bpm-planner__field">                    <label for="bpm-customer-name">Contactnaam</label>                    <input id="bpm-customer-name" class="bpm-planner__customer-name" type="text" placeholder="Naam contactpersoon">                </div>                <div class="bpm-planner__field">                    <label for="bpm-customer-email">E-mail</label>                    <input id="bpm-customer-email" class="bpm-planner__customer-email" type="email" placeholder="voorbeeld@mail.com">                </div>                <div class="bpm-planner__field">                    <label for="bpm-customer-phone">Telefoon</label>                    <input id="bpm-customer-phone" class="bpm-planner__customer-phone" type="tel" placeholder="06 12345678">                </div>                <div class="bpm-planner__totals">                    <div><span>Subtotaal</span><strong class="bpm-planner__total-subtotal">€0,00</strong></div>                    <div><span>Kosten</span><strong class="bpm-planner__total-fee">€0,00</strong></div>                    <div><span>Totaal</span><strong class="bpm-planner__total-total">€0,00</strong></div>                </div>                <div class="bpm-planner__actions">                    <button type="button" class="bpm-planner__btn bpm-planner__btn--calc">Herbereken</button>                    <button type="button" class="bpm-planner__btn bpm-planner__btn--save">Plan opslaan</button>                    <button type="button" class="bpm-planner__btn bpm-planner__btn--submit">Indienen</button>                </div>            `;
      grid.appendChild(productsColumn);
      grid.appendChild(scheduleColumn);
      grid.appendChild(overviewColumn);
      root.appendChild(grid);
      this.ui = {
        grid,
        productsColumn,
        scheduleColumn,
        overviewColumn,
        productList: productsColumn.querySelector(".bpm-planner__product-list"),
        search: productsColumn.querySelector(".bpm-planner__search"),
        filterBar: productsColumn.querySelector(".bpm-filter-bar"),
        filterTags: productsColumn.querySelector(".bpm-filter-tags"),
        itemsList: scheduleColumn.querySelector(".bpm-planner__items"),
        dateInput: scheduleColumn.querySelector(".bpm-planner__date"),
        participantsInput: overviewColumn.querySelector(
          ".bpm-planner__participants",
        ),
        customerNameInput: overviewColumn.querySelector(
          ".bpm-planner__customer-name",
        ),
        customerEmailInput: overviewColumn.querySelector(
          ".bpm-planner__customer-email",
        ),
        customerPhoneInput: overviewColumn.querySelector(
          ".bpm-planner__customer-phone",
        ),
        totalSubtotal: overviewColumn.querySelector(
          ".bpm-planner__total-subtotal",
        ),
        totalFee: overviewColumn.querySelector(".bpm-planner__total-fee"),
        totalTotal: overviewColumn.querySelector(".bpm-planner__total-total"),
        calcButton: overviewColumn.querySelector(".bpm-planner__btn--calc"),
        saveButton: overviewColumn.querySelector(".bpm-planner__btn--save"),
        submitButton: overviewColumn.querySelector(".bpm-planner__btn--submit"),
      };
      this.ui.dateInput.value = this.state.date;
      this.ui.participantsInput.value = this.state.participants;
      if (this.ui.customerNameInput) {
        this.ui.customerNameInput.value = this.state.customer.name || "";
      }
      if (this.ui.customerEmailInput) {
        this.ui.customerEmailInput.value = this.state.customer.email || "";
      }
      if (this.ui.customerPhoneInput) {
        this.ui.customerPhoneInput.value = this.state.customer.phone || "";
      }
    },
    bindEvents() {
      this.ui.search.addEventListener("input", (event) => {
        this.state.filter.query = event.target.value || "";
        this.filter(this.state.filter);
      });
      this.ui.dateInput.addEventListener("change", (event) => {
        const value = event.target.value || "";
        this.setPlannerDate(value);
      });
      this.ui.participantsInput.addEventListener("change", (event) => {
        const value = parseInt(event.target.value, 10);
        this.state.participants =
          Number.isNaN(value) || value < 1 ? null : value;
        event.target.value =
          this.state.participants === null
            ? ""
            : String(this.state.participants);
        console.log("[BPMPlanner] Participants -> %s", this.state.participants);
        this.renderItems();
        this.renderTotals();
        this.filter(this.state.filter);
        this.queuePriceUpdate();
        this.loadProducts();
      });
      if (this.ui.customerNameInput) {
        this.ui.customerNameInput.addEventListener("input", (event) => {
          this.state.customer.name = (event.target.value || "").trim();
          this.updateSubmitState();
        });
      }
      if (this.ui.customerEmailInput) {
        this.ui.customerEmailInput.addEventListener("input", (event) => {
          this.state.customer.email = (event.target.value || "").trim();
          this.updateSubmitState();
        });
      }
      if (this.ui.customerPhoneInput) {
        this.ui.customerPhoneInput.addEventListener("input", (event) => {
          this.state.customer.phone = (event.target.value || "").trim();
        });
      }
      this.ui.calcButton.addEventListener("click", () => this.calcPrice());
      this.ui.saveButton.addEventListener("click", () => this.savePlan());
      this.ui.submitButton.addEventListener("click", () => this.submitPlan());
      this.updateSubmitState();
    },
    setPlannerDate(newDate, options = {}) {
      const normalised = newDate && newDate !== "" ? newDate : null;
      if (this.state.date === normalised) {
        this.syncProductDateInputs();
        return;
      }
      this.state.date = normalised;
      if (this.ui.dateInput && this.ui.dateInput.value !== (normalised || "")) {
        this.ui.dateInput.value = normalised || "";
      }
      console.log("[BPMPlanner] Date changed -> %s", this.state.date || "leeg");
      this.syncProductDateInputs();
      if (!options.skipFilter) {
        this.filter(this.state.filter);
      }
      if (!options.skipPrice) {
        this.queuePriceUpdate();
      }
      if (!options.skipProducts) {
        this.loadProducts();
      }
    },
    syncProductDateInputs() {
      const inputs = Array.isArray(this.ui.productDateInputs)
        ? this.ui.productDateInputs
        : [];
      inputs.forEach((input) => {
        if (input && input.value !== this.state.date) {
          input.value = this.state.date || "";
        }
      });
    },
    loadProducts() {
      const params = new URLSearchParams();
      if (this.state.date) {
        params.append("date", this.state.date);
      }
      const participants =
        this.state.participants && this.state.participants > 0
          ? this.state.participants
          : null;
      if (participants !== null) {
        params.append("participants", String(participants));
      }
      const query = params.toString();
      const endpoint = `/products${query ? `?${query}` : ""}`;
      return this.request(endpoint)
        .then((products) => {
          this.state.products = Array.isArray(products) ? products : [];
          this.renderCategoryFilter();
          this.updateFilterTriggerLabels();
          this.filter(this.state.filter);
          console.log(
            "[BPMPlanner] Products loaded (%d) voor %s",
            this.state.products.length,
            this.state.date || "alle dagen",
          );
        })
        .catch((error) => {
          this.showError("Kon producten niet laden.", error);
        });
    },
    loadPlan(sessionId) {
      if (!sessionId) {
        return;
      }
      this.request(`/plan?session_id=${encodeURIComponent(sessionId)}`)
        .then((plan) => {
          if (!plan) {
            return;
          }
          this.state.planId = plan.plan_id || null;
          this.state.sessionId = plan.session_id || sessionId;
          this.applySessionId(this.state.sessionId);
          this.state.date = plan.date || this.state.date;
          const loadedParticipants = Number.parseInt(plan.participants, 10);
          if (Number.isFinite(loadedParticipants) && loadedParticipants > 0) {
            this.state.participants = loadedParticipants;
          }
          this.state.items = Array.isArray(plan.items)
            ? plan.items.map((item) => ({
                product_id: item.product_id,
                start: item.start,
                end: item.end,
                duration: item.duration || 0,
                title: this.getProductTitle(item.product_id),
                price_pp:
                  typeof item.price_pp === "number"
                    ? item.price_pp
                    : this.getProductPrice(item.product_id),
              }))
            : [];
          if (plan.totals) {
            this.state.totals = {
              subtotal: plan.totals.subtotal || 0,
              fee: plan.totals.fee || 0,
              total: plan.totals.total || 0,
            };
          }
          if (plan.customer) {
            this.setCustomerInfo(
              plan.customer.name || "",
              plan.customer.email || "",
              plan.customer.phone || "",
            );
          }
          console.log(
            "[BPMPlanner] Plan geladen (plan_id=%s)",
            this.state.planId,
          );
          this.render();
        })
        .catch((error) => {
          console.warn("[BPMPlanner] Kan plan niet laden", error);
        });
    },
    filter(options) {
      const query = (options.query || "").toLowerCase();
      const durationFilter = options.duration || "any";
      const categoryFilter = options.category || "all";
      const priceFilter = options.price || "all";
      this.state.filtered = this.state.products.filter((product) => {
        const matchesQuery =
          query === "" || (product.title || "").toLowerCase().includes(query);
        let matchesDuration = true;
        const duration = product.duration_minutes || 0;
        if (durationFilter === "60") {
          matchesDuration = duration <= 60;
        } else if (durationFilter === "90") {
          matchesDuration = duration <= 90;
        } else if (durationFilter === "longer") {
          matchesDuration = duration > 90;
        }
        let matchesCategory = categoryFilter === "all";
        if (!matchesCategory) {
          const slugs = Array.isArray(product.category_slugs)
            ? product.category_slugs
            : [];
          matchesCategory = slugs.some(
            (slug) =>
              typeof slug === "string" &&
              slug.toLowerCase() === categoryFilter.toLowerCase(),
          );
        }
        let matchesPrice = true;
        const price =
          typeof product.price_pp === "number"
            ? product.price_pp
            : parseFloat(product.price_pp || "0");
        if (priceFilter === "low") {
          matchesPrice = price <= 25;
        } else if (priceFilter === "mid") {
          matchesPrice = price > 25 && price <= 75;
        } else if (priceFilter === "high") {
          matchesPrice = price > 75;
        }
        return (
          matchesQuery && matchesDuration && matchesCategory && matchesPrice
        );
      });
      this.renderFilterTags();
      this.updateFilterTriggerLabels();
      this.renderProducts();
    },
    loadAvailability(productId, date) {
      const cacheKey = `${productId}:${date}`;
      if (this.state.availability[cacheKey]) {
        return Promise.resolve(this.state.availability[cacheKey]);
      }
      return this.request(
        `/availability?product_id=${productId}&date=${encodeURIComponent(date)}`,
      )
        .then((result) => {
          const slots =
            result && Array.isArray(result.time_slots) ? result.time_slots : [];
          this.state.availability[cacheKey] = slots;
          return slots;
        })
        .catch((error) => {
          this.showError("Beschikbaarheid kon niet worden opgehaald.", error);
          return [];
        });
    },
    addItem(productId, start, end = null) {
      const product = this.state.products.find(
        (entry) => entry.id === productId,
      );
      if (!product) {
        this.showError("Onbekend product geselecteerd.");
        return;
      }
      if (!this.state.date) {
        this.showError("Selecteer eerst een datum.");
        return;
      }
      if (!start) {
        this.showError("Selecteer een starttijd.");
        return;
      }
      const duration = product.duration_minutes || 0;
      const computedEnd = end || this.calculateEndTime(start, duration);
      const price =
        typeof product.price_pp === "number"
          ? product.price_pp
          : parseFloat(product.price_pp || "0");
      this.state.items.push({
        product_id: productId,
        start,
        end: computedEnd,
        duration,
        title: product.title || `Product ${productId}`,
        price_pp: Number.isFinite(price) ? price : 0,
      });
      this.state.items.sort((a, b) => a.start.localeCompare(b.start));
      console.log(
        "[BPMPlanner] Item toegevoegd (product=%s, start=%s)",
        productId,
        start,
      );
      this.renderItems();
      this.renderTotals();
      this.queuePriceUpdate();
    },
    removeItem(index) {
      if (index < 0 || index >= this.state.items.length) {
        return;
      }
      this.state.items.splice(index, 1);
      console.log("[BPMPlanner] Item verwijderd (%s)", index);
      this.renderItems();
      this.renderTotals();
      this.queuePriceUpdate();
    },
    reorderItems(fromIndex, toIndex) {
      if (fromIndex === toIndex) {
        return;
      }
      const item = this.state.items.splice(fromIndex, 1)[0];
      this.state.items.splice(toIndex, 0, item);
      console.log("[BPMPlanner] Items herordend", this.state.items);
      this.renderItems();
      this.renderTotals();
      this.queuePriceUpdate();
    },
    calcPrice(silent) {
      if (!this.state.date || this.state.items.length === 0) {
        if (!silent) {
          this.showError("Voeg activiteiten toe en kies een datum.");
        }
        return Promise.resolve();
      }
      const isSilent = Boolean(silent);
      return this.validatePlan({
        silent: isSilent,
        updateTotals: true,
      }).then((validation) => {
        if (validation.ok && !isSilent) {
          console.log("[BPMPlanner] Totals updated", this.state.totals);
        }
      });
    },
    async validatePlan(options = {}) {
      const { silent = false, updateTotals = false } = options;
      try {
        const result = await this.request("/validate-plan", {
          method: "POST",
          body: this.buildPayload(),
        });
        if (!result) {
          return {
            ok: false,
            errors: [
              {
                message: "Onbekende fout bij validatie.",
              },
            ],
          };
        }
        const errors = Array.isArray(result.errors) ? result.errors : [];
        if (errors.length > 0) {
          if (!silent) {
            const message = errors
              .map((error) =>
                error && error.message ? error.message : "Plan bevat fouten",
              )
              .join(", ");
            this.showError(message);
          }
          return {
            ok: false,
            errors,
          };
        }
        if (updateTotals && result.totals) {
          this.state.totals = {
            subtotal: result.totals.subtotal || 0,
            fee: result.totals.fee || 0,
            total: result.totals.total || 0,
          };
        }
        if (updateTotals && result.plan) {
          if (result.plan.plan_id) {
            this.state.planId = result.plan.plan_id;
          }
          if (result.plan.session_id) {
            this.state.sessionId = result.plan.session_id;
            this.applySessionId(result.plan.session_id);
          }
        }
        if (updateTotals) {
          this.renderTotals();
        }
        return {
          ok: true,
          result,
        };
      } catch (error) {
        if (!silent) {
          this.showError("Validatie mislukt.", error);
        } else {
          console.warn("[BPMPlanner] Validatie mislukt", error);
        }
        return {
          ok: false,
          errors: [
            {
              message: (error && error.message) || "Validatie mislukt.",
            },
          ],
        };
      }
    },
    savePlan() {
      if (!this.prepareBeforeSubmit()) {
        return;
      }
      this.request("/plan", {
        method: "POST",
        body: this.buildPayload(),
      })
        .then((result) => {
          if (!result) {
            return;
          }
          this.state.planId = result.plan_id || this.state.planId;
          this.state.sessionId = result.session_id || this.state.sessionId;
          if (result.totals) {
            this.state.totals = {
              subtotal: result.totals.subtotal || 0,
              fee: result.totals.fee || 0,
              total: result.totals.total || 0,
            };
          }
          this.applySessionId(this.state.sessionId);
          this.render();
          console.log(
            "[BPMPlanner] Plan opgeslagen (plan_id=%s)",
            this.state.planId,
          );
          this.toast("Plan opgeslagen.");
        })
        .catch((error) => {
          this.showError("Opslaan is mislukt.", error);
        });
    },
    async submitPlan() {
      if (!this.prepareBeforeSubmit()) {
        return;
      }
      if (!this.canSubmit()) {
        this.showError("Vul de contactgegevens in en voeg activiteiten toe.");
        this.updateSubmitState();
        return;
      }
      const validation = await this.validatePlan({
        silent: false,
        updateTotals: true,
      });
      if (!validation.ok) {
        const errors = validation.errors || [];
        const message = errors.length
          ? errors
              .map((error) =>
                error && error.message ? error.message : "Onbekende fout",
              )
              .join(", ")
          : "Plan bevat fouten.";
        window.alert(`Plan bevat fouten: ${message}`);
        return;
      }
      try {
        const result = await this.request("/submit", {
          method: "POST",
          body: this.buildPayload(),
        });
        if (!result) {
          return;
        }
        this.state.planId = result.plan_id || this.state.planId;
        this.state.sessionId = result.session_id || this.state.sessionId;
        if (result.totals) {
          this.state.totals = {
            subtotal: result.totals.subtotal || 0,
            fee: result.totals.fee || 0,
            total: result.totals.total || 0,
          };
        }
        this.applySessionId(this.state.sessionId);
        this.renderTotals();
        console.log(
          "[BPMPlanner] Plan verzonden (plan_id=%s)",
          this.state.planId,
        );
        this.toast("Plan succesvol ingediend.");
      } catch (error) {
        this.showError("Indienen is mislukt.", error);
      }
    },
    render() {
      this.renderProducts();
      this.renderItems();
      this.renderTotals();
      if (this.ui.dateInput) {
        this.ui.dateInput.value = this.state.date || "";
      }
      if (this.ui.participantsInput) {
        this.ui.participantsInput.value =
          this.state.participants === null
            ? ""
            : String(this.state.participants);
      }
      if (this.ui.customerNameInput) {
        this.ui.customerNameInput.value = this.state.customer.name || "";
      }
      if (this.ui.customerEmailInput) {
        this.ui.customerEmailInput.value = this.state.customer.email || "";
      }
      if (this.ui.customerPhoneInput) {
        this.ui.customerPhoneInput.value = this.state.customer.phone || "";
      }
      this.updateSubmitState();
    },
    renderProducts() {
      const container = this.ui.productList;
      container.innerHTML = "";
      if (!this.state.filtered.length) {
        const message =
          this.state.products.length === 0
            ? "Geen activiteiten beschikbaar voor deze dag."
            : "Geen producten gevonden.";
        container.innerHTML = `<p class="bpm-planner__empty">${message}</p>`;
        return;
      }
      this.state.filtered.forEach((product) => {
        const card = document.createElement("article");
        card.className = "bpm-product";
        const duration = product.duration_minutes || 0;
        const durationLabel = duration > 0 ? `${duration} min` : "Flexibel";
        const pricePerPerson =
          typeof product.price_pp === "number"
            ? product.price_pp
            : parseFloat(product.price_pp || "0");
        const participantCount =
          Number.isFinite(this.state.participants) &&
          this.state.participants > 0
            ? this.state.participants
            : null;
        const participantLabel = participantCount
          ? `${participantCount}×`
          : "Kies deelnemers";
        const categoryBadges = Array.isArray(product.categories)
          ? product.categories
              .filter(
                (category) =>
                  typeof category === "string" && category.trim() !== "",
              )
              .map(
                (category) =>
                  `<span class="bpm-product__badge">${category}</span>`,
              )
              .join("")
          : "";
        card.innerHTML = `                    <header class="bpm-product__header">                        <div class="bpm-product__title">                            <h3>${product.title || "Onbekend product"}</h3>                            ${categoryBadges ? `<div class="bpm-product__badges">${categoryBadges}</div>` : ""}                        </div>                        <span class="bpm-product__meta">${durationLabel}</span>                    </header>                    <div class="bpm-product__pricing">                        <span class="bpm-product__price-pp">${this.formatCurrency(pricePerPerson)} <small>p.p.</small></span>                        <span class="bpm-product__price-total"><small>${participantLabel}; totaal via server</small></span>                    </div>                    <div class="bpm-product__availability">                        <label class="bpm-product__date">                            <span class="screen-reader-text">Datum</span>                            <input type="date" class="bpm-product__date-picker" value="${this.state.date || ""}">                        </label>                        <div class="bpm-product__slot">                            <button type="button" class="bpm-product__slot-trigger">Beschikbare tijden</button>                            <div class="bpm-product__slot-menu" hidden></div>                        </div>                    </div>`;
        const dateInput = card.querySelector(".bpm-product__date-picker");
        const slotTrigger = card.querySelector(".bpm-product__slot-trigger");
        const slotMenu = card.querySelector(".bpm-product__slot-menu");
        if (!this.ui.productDateInputs) {
          this.ui.productDateInputs = [];
        }
        if (dateInput) {
          this.ui.productDateInputs.push(dateInput);
          dateInput.addEventListener("change", (event) => {
            const value = event.target.value || "";
            this.setPlannerDate(value);
          });
        }
        if (slotTrigger && slotMenu) {
          slotTrigger.addEventListener("click", (event) => {
            event.preventDefault();
            const dateValue = dateInput
              ? dateInput.value || this.state.date
              : this.state.date;
            if (!dateValue) {
              this.showError("Selecteer eerst een datum.");
              return;
            }
            if (slotTrigger.classList.contains("is-open")) {
              slotTrigger.classList.remove("is-open");
              slotMenu.hidden = true;
              return;
            }
            slotTrigger.classList.add("is-loading");
            slotTrigger.disabled = true;
            this.loadAvailability(product.id, dateValue)
              .then((slots) => {
                this.populateSlotMenu(slotMenu, slots, product);
                slotMenu.hidden = false;
                slotTrigger.classList.add("is-open");
              })
              .finally(() => {
                slotTrigger.disabled = false;
                slotTrigger.classList.remove("is-loading");
              });
          });
        }
        container.appendChild(card);
      });
    },
    populateSlotMenu(slotMenu, slots, product) {
      if (!slotMenu) {
        return;
      }
      slotMenu.innerHTML = "";
      if (!Array.isArray(slots) || slots.length === 0) {
        slotMenu.innerHTML =
          '<p class="bpm-product__slots-empty">Geen vrije tijdsloten.</p>';
        return;
      }
      slots.forEach((slot) => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "bpm-product__slot-btn";
        const labelParts = [`${slot.start} - ${slot.end}`];
        const capacityLeft =
          typeof slot.capacity_left === "number" ? slot.capacity_left : null;
        if (capacityLeft !== null) {
          labelParts.push(`(${capacityLeft} vrij)`);
          if (capacityLeft > 5) {
            button.classList.add("bpm-product__slot-btn--plenty");
          } else if (capacityLeft <= 2) {
            button.classList.add("bpm-product__slot-btn--low");
          }
        }
        if (
          slot.display_total !== undefined &&
          Number.isFinite(Number(slot.display_total))
        ) {
          labelParts.push(this.formatCurrency(Number(slot.display_total)));
        }
        button.textContent = labelParts.join(" ");
        button.addEventListener("click", () => {
          this.addItem(product.id, slot.start, slot.end);
          slotMenu.hidden = true;
          const trigger = slotMenu.previousElementSibling;
          if (trigger && trigger.classList) {
            trigger.classList.remove("is-open");
          }
        });
        slotMenu.appendChild(button);
      });
    },
    renderItems() {
      const list = this.ui.itemsList;
      list.innerHTML = "";
      if (!this.state.items.length) {
        list.innerHTML =
          '<li class="bpm-planner__empty">Nog geen activiteiten ingepland.</li>';
        this.updateSubmitState();
        return;
      }
      this.state.items.forEach((item, index) => {
        const li = document.createElement("li");
        li.className = "bpm-item";
        li.draggable = true;
        li.dataset.index = String(index);
        const pricePerPerson =
          typeof item.price_pp === "number"
            ? item.price_pp
            : this.getProductPrice(item.product_id);
        const serverLineTotal = Number(
          item.line_total ?? item.total ?? item.display_total,
        );
        const lineTotalLabel = Number.isFinite(serverLineTotal)
          ? this.formatCurrency(serverLineTotal)
          : "Prijs wordt berekend";
        li.innerHTML = `                    <div class="bpm-item__times">                        <strong>${item.start}</strong>                        <span>${item.end}</span>                    </div>                    <div class="bpm-item__details">                        <span class="bpm-item__title">${item.title || this.getProductTitle(item.product_id)}</span>                        <small>${item.duration || 0}
min</small>                    </div>                    <div class="bpm-item__price">                        <strong>${lineTotalLabel}</strong>                        <span>${this.formatCurrency(pricePerPerson)} p.p.</span>                    </div>                    <div class="bpm-item__actions">                        <button type="button" class="bpm-item__remove" aria-label="Verwijder item">&times;</button>                    </div>                `;
        li.addEventListener("dragstart", (event) => {
          event.dataTransfer.setData("text/plain", String(index));
          li.classList.add("bpm-item--dragging");
        });
        li.addEventListener("dragend", () => {
          li.classList.remove("bpm-item--dragging");
        });
        li.addEventListener("dragover", (event) => {
          event.preventDefault();
        });
        li.addEventListener("drop", (event) => {
          event.preventDefault();
          const from = parseInt(event.dataTransfer.getData("text/plain"), 10);
          const to = index;
          if (!Number.isNaN(from) && !Number.isNaN(to)) {
            this.reorderItems(from, to);
          }
        });
        li.querySelector(".bpm-item__remove").addEventListener("click", () =>
          this.removeItem(index),
        );
        list.appendChild(li);
      });
      this.updateSubmitState();
    },
    renderTotals() {
      const storedTotals = this.state.totals || DEFAULT_TOTALS;
      const subtotal = Number.isFinite(Number(storedTotals.subtotal))
        ? Number(storedTotals.subtotal)
        : 0;
      const fee =
        storedTotals.fee && storedTotals.fee > 0 ? storedTotals.fee : 0;
      const total = Number.isFinite(Number(storedTotals.total))
        ? Number(storedTotals.total)
        : 0;
      this.ui.totalSubtotal.textContent = this.formatCurrency(subtotal);
      this.ui.totalFee.textContent = this.formatCurrency(fee);
      this.ui.totalTotal.textContent = this.formatCurrency(total);
    },
    queuePriceUpdate() {
      if (this.priceTimer) {
        window.clearTimeout(this.priceTimer);
      }
      this.priceTimer = window.setTimeout(() => {
        this.calcPrice(true);
      }, 400);
    },
    prepareBeforeSubmit() {
      if (!this.state.date) {
        this.showError("Selecteer een datum voor het plan.");
        return false;
      }
      if (
        !Number.isFinite(this.state.participants) ||
        this.state.participants < 1
      ) {
        this.showError("Vul het canonieke aantal deelnemers in.");
        return false;
      }
      if (this.state.items.length === 0) {
        this.showError("Voeg minimaal één activiteit toe.");
        return false;
      }
      return true;
    },
    buildPayload() {
      return {
        plan_id: this.state.planId,
        session_id: this.state.sessionId,
        date: this.state.date,
        participants: this.state.participants,
        items: this.state.items.map((item) => ({
          product_id: item.product_id,
          start: item.start,
          end: item.end,
        })),
        customer: this.getCustomerInfo(),
      };
    },
    request(path, options) {
      const url = this.state.restBase + path;
      const fetchOptions = Object.assign(
        {
          credentials: "same-origin",
        },
        options || {},
      );
      fetchOptions.headers = fetchOptions.headers || {};
      if (fetchOptions.method && fetchOptions.method.toUpperCase() !== "GET") {
        fetchOptions.headers["Content-Type"] = "application/json";
        if (this.state.nonce) {
          fetchOptions.headers["X-WP-Nonce"] = this.state.nonce;
        }
        if (fetchOptions.body && typeof fetchOptions.body !== "string") {
          fetchOptions.body = JSON.stringify(fetchOptions.body);
        }
      }
      return fetch(url, fetchOptions).then(async (response) => {
        const contentType = response.headers.get("Content-Type") || "";
        const isJson = contentType.includes("application/json");
        const payload = isJson ? await response.json() : await response.text();
        if (!response.ok) {
          const message =
            payload && payload.message ? payload.message : response.statusText;
          const error = new Error(message || "Onbekende fout");
          error.payload = payload;
          error.status = response.status;
          throw error;
        }
        return payload;
      });
    },
    calculateEndTime(start, duration) {
      if (!start || typeof start !== "string") {
        return start;
      }
      const [hours, minutes] = start
        .split(":")
        .map((part) => parseInt(part, 10));
      if (Number.isNaN(hours) || Number.isNaN(minutes)) {
        return start;
      }
      const startMinutes = hours * 60 + minutes;
      const endMinutes = Math.min(startMinutes + (duration || 0), 24 * 60 - 1);
      const endHours = Math.floor(endMinutes / 60);
      const endMins = endMinutes % 60;
      return `${String(endHours).padStart(2, "0")}:${String(endMins).padStart(2, "0")}`;
    },
    getProductPrice(productId) {
      const product = this.state.products.find(
        (entry) => entry.id === productId,
      );
      if (product && product.price_pp !== undefined) {
        const price =
          typeof product.price_pp === "number"
            ? product.price_pp
            : parseFloat(product.price_pp || "0");
        return Number.isNaN(price) ? 0 : price;
      }
      return 0;
    },
    getProductTitle(productId) {
      const product = this.state.products.find(
        (entry) => entry.id === productId,
      );
      return product ? product.title : `Product ${productId}`;
    },
    getToday() {
      const now = new Date();
      const month = String(now.getMonth() + 1).padStart(2, "0");
      const day = String(now.getDate()).padStart(2, "0");
      return `${now.getFullYear()}-${month}-${day}`;
    },
    setCookie(name, value) {
      if (!name) {
        return;
      }
      const expires = new Date(
        Date.now() + 7 * 24 * 60 * 60 * 1000,
      ).toUTCString();
      document.cookie = `${name}=${value};
expires=${expires};
path=/`;
    },
    applySessionId(sessionId) {
      if (!sessionId) {
        return;
      }
      this.state.sessionId = sessionId;
      this.state.root.dataset.sessionId = sessionId;
      this.setCookie("bpm_plan_session", sessionId);
    },
    formatCurrency(amount) {
      const value =
        typeof amount === "number" ? amount : parseFloat(amount || "0");
      return `€${value.toFixed(2)}`;
    },
    toast(message) {
      if (!message) {
        return;
      }
      if (window.wp && window.wp && window.wp.a11y && window.wp.a11y.speak) {
        window.wp.a11y.speak(message);
      }
      console.log("[BPMPlanner]", message);
    },
    showError(message, error) {
      console.error("[BPMPlanner]", message, error || "");
      window.alert(message);
    },
  };
  window.BPMPlanner = Planner;
  const bootstrapPlanner = () => {
    const root = document.getElementById("bpm-planner");
    if (!root || root.dataset.sbdpPlannerMounted === "1") {
      return;
    }
    root.dataset.sbdpPlannerMounted = "1";
    const config = window.BPMPlannerConfig || {};
    config.root = root;
    config.restBase =
      config.rest_base || root.dataset.restBase || "/wp-json/booking/v1";
    config.nonce = config.nonce || root.dataset.nonce || "";
    config.sessionId = config.session_id || root.dataset.sessionId || "";
    try {
      Planner.init(config);
    } catch (error) {
      console.error("[BPMPlanner] Initialisatie mislukt", error);
    }
  };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootstrapPlanner);
  } else {
    bootstrapPlanner();
  }
})(window, document);
