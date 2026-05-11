<?php
if (!defined("ABSPATH")) {
    exit;
}

class DDB_Spots_Integrations_Spot_Vendor_Bridge
{
    public function init(): void
    {
        add_shortcode("ddb_spot_products", [$this, "render_spot_products"]);
        add_action("add_meta_boxes", [$this, "add_spot_link_meta_box"]);
        add_action("save_post_product", [$this, "save_spot_link_meta"]);
        add_filter("parse_query", [$this, "filter_products_by_owner"]);
    }

    public function add_spot_link_meta_box()
    {
        add_meta_box("ddb_product_spot_link", "Gekoppelde Spot", [$this, "render_spot_link_field"], "product", "side", "default");
    }

    public function render_spot_link_field($post)
    {
        $selected_spot = get_post_meta($post->ID, "_ddb_linked_spot_id", true);
        $spots = get_posts(["post_type" => "ddb_spot", "posts_per_page" => -1, "orderby" => "title", "order" => "ASC"]);
        wp_nonce_field("ddb_save_spot_link", "ddb_spot_link_nonce");
        echo "<select name='ddb_linked_spot_id' style='width:100%;'>";
        echo "<option value=''>-- Geen spot gekoppeld --</option>";
        foreach ($spots as $spot) {
            printf("<option value='%s' %s>%s</option>", esc_attr($spot->ID), selected($selected_spot, $spot->ID, false), esc_html($spot->post_title));
        }
        echo "</select>";
    }

    public function save_spot_link_meta($post_id)
    {
        if (!isset($_POST["ddb_spot_link_nonce"]) || !wp_verify_nonce($_POST["ddb_spot_link_nonce"], "ddb_save_spot_link")) {
            return;
        }
        if (isset($_POST["ddb_linked_spot_id"])) {
            update_post_meta($post_id, "_ddb_linked_spot_id", sanitize_text_field($_POST["ddb_linked_spot_id"]));
        }
    }

    public function filter_products_by_owner($query)
    {
        global $pagenow, $post_type;
        if (is_admin() && "edit.php" === $pagenow && "product" === $post_type && !current_user_can("manage_options")) {
            $query->query_vars["author"] = get_current_user_id();
        }
    }

    public function render_spot_products($atts)
    {
        $spot_id = get_the_ID();
        $args = [
            "post_type" => "product",
            "posts_per_page" => -1,
            "meta_query" => [
                [
                    "key" => "_ddb_linked_spot_id",
                    "value" => $spot_id,
                ],
            ],
        ];

        $products = new WP_Query($args);
        $template_path = DDB_SPOTS_PATH . "templates/oled-card.php";

        ob_start();

        if ($products->have_posts()) {
            echo '<div class="ddb-oled-grid">';
            while ($products->have_posts()) {
                $products->the_post();
                $product = wc_get_product(get_the_ID());

                $title = get_the_title();
                $price = $product ? $product->get_price() : "";
                $image_url = get_the_post_thumbnail_url(get_the_ID(), "medium_large");
                $link = get_the_permalink();
                $meta = "Dagje Den Bosch Partner";

                if (file_exists($template_path)) {
                    include $template_path;
                } else {
                    echo "Card template missing at " . esc_html($template_path);
                }
            }
            echo "</div>";
        } else {
            echo '<p style="color:var(--ui-color-text-muted); opacity:0.6;">Momenteel geen activiteiten beschikbaar voor deze spot.</p>';
        }

        wp_reset_postdata();
        return ob_get_clean();
    }
}
