<?php
add_action('wp_enqueue_scripts', function() {
    if (!is_singular()) {
        return;
    }

    global $post;
    if (!$post || empty($post->post_content)) {
        return;
    }

    if (!has_shortcode($post->post_content, 'ddb_activiteiten_filter')) {
        return;
    }

    wp_enqueue_style(
        'ddb-activiteiten-filter',
        WP_PLUGIN_URL . '/booking-pro-module/assets/css/ddb-activiteiten-filter.css',
        array(),
        filemtime(WP_PLUGIN_DIR . '/booking-pro-module/assets/css/ddb-activiteiten-filter.css')
    );
}, 25);

// Voeg filter shortcode toe voor activiteiten
add_shortcode('ddb_activiteiten_filter', function() {
    ob_start();
    ?>
    <div class="ddb-filter-container ddb-single-row">
        <!-- Search Bar -->
        <div class="ddb-search-wrapper">
            <span class="ddb-search-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" id="ddb-activity-search" placeholder="Zoek..." autocomplete="off" />
        </div>

        <div class="ddb-filter-divider"></div>

        <!-- Category Chips -->
        <div class="ddb-chips-group ddb-category-chips">
            <span class="ddb-chip ddb-chip-primary active" data-filter="all">Alle</span>
            <span class="ddb-chip ddb-chip-primary" data-category="actief">Actief</span>
            <span class="ddb-chip ddb-chip-primary" data-category="culinair">Culinair</span>
            <span class="ddb-chip ddb-chip-primary" data-category="creatief">Creatief</span>
            <span class="ddb-chip ddb-chip-primary" data-category="games">Games</span>
        </div>

        <div class="ddb-filter-divider"></div>

        <!-- People/Group Chips (Voorkeuren) -->
        <div class="ddb-chips-group ddb-group-chips">
            <span class="ddb-chip-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </span>
            <span class="ddb-chip ddb-chip-outline" data-people="1-10">1-10p</span>
            <span class="ddb-chip ddb-chip-outline" data-people="11-20">11-20p</span>
            <span class="ddb-chip ddb-chip-outline" data-people="21-50">21-50p</span>
            <span class="ddb-chip ddb-chip-outline" data-people="51-250">51-250p</span>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const searchInput = document.getElementById("ddb-activity-search");
        const categoryChips = document.querySelectorAll(".ddb-category-chips .ddb-chip");
        const groupChips = document.querySelectorAll(".ddb-group-chips .ddb-chip");
        
        let activeSearch = "";
        let activeCategory = "all";
        let activeGroup = "all";

        function runDDBFilter() {
            const productGrid = document.querySelector(".woocommerce ul.products");
            if (!productGrid) return;

            const products = productGrid.querySelectorAll("li.product");
            let visibleCount = 0;

            products.forEach(product => {
                let showMe = true;

                if (activeSearch !== "") {
                    const titleText = (product.querySelector(".woocommerce-loop-product__title")?.innerText || "").toLowerCase();
                    // Fallback to searching element text globally if .sbdp-people-range is structured differently
                    const productText = product.innerText.toLowerCase();
                    
                    if (!productText.includes(activeSearch)) {
                        showMe = false;
                    }
                }

                if (showMe && activeCategory !== "all") {
                    const hasCategoryClass = Array.from(product.classList).some(className => className.includes("product_cat-" + activeCategory) || className.includes("category-" + activeCategory));
                    if (!hasCategoryClass) showMe = false;
                }

                if (showMe && activeGroup !== "all") {
                    const productText = product.innerText.toLowerCase().replace(/\s/g, "");
                    const activeGroupText = activeGroup.toLowerCase().replace(/\s/g, "");
                    const hasGroupClass = Array.from(product.classList).some(className => className.includes(activeGroup));

                    if (!hasGroupClass && !productText.includes(activeGroupText)) {
                        showMe = false;
                    }
                }

                if (showMe) {
                    product.style.display = ""; 
                    product.style.opacity = "1";
                    visibleCount++;
                } else {
                    product.style.display = "none";
                }
            });

            let noResultsMsg = document.getElementById("ddb-no-results");
            if (visibleCount === 0) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement("div");
                    noResultsMsg.id = "ddb-no-results";
                    noResultsMsg.style.width = "100%";
                    noResultsMsg.style.textAlign = "center";
                    noResultsMsg.style.padding = "40px 20px";
                    noResultsMsg.style.color = "var(--ddb-text-secondary)";
                    noResultsMsg.style.fontFamily = "var(--ddb-font, var(--ddb-font-body, 'Quattrocento Sans', 'Segoe UI', sans-serif))";
                    noResultsMsg.innerText = "Geen activiteiten gevonden met deze filters. Probeer iets anders te zoeken!";
                    productGrid.parentNode.insertBefore(noResultsMsg, productGrid.nextSibling);
                }
                noResultsMsg.style.display = "block";
            } else if (noResultsMsg) {
                noResultsMsg.style.display = "none";
            }
        }

        if (searchInput) searchInput.addEventListener("input", e => { activeSearch = e.target.value.toLowerCase().trim(); runDDBFilter(); });
        categoryChips.forEach(chip => chip.addEventListener("click", function() {
            categoryChips.forEach(c => c.classList.remove("active"));
            this.classList.add("active");
            activeCategory = this.getAttribute("data-filter") || this.getAttribute("data-category") || "all";
            runDDBFilter();
        }));
        groupChips.forEach(chip => chip.addEventListener("click", function() {
            if (this.classList.contains("active")) {
                this.classList.remove("active");
                activeGroup = "all";
            } else {
                groupChips.forEach(c => c.classList.remove("active"));
                this.classList.add("active");
                activeGroup = this.getAttribute("data-people") || "all";
            }
            runDDBFilter();
        }));
    });
    </script>
    <?php
    return ob_get_clean();
});
