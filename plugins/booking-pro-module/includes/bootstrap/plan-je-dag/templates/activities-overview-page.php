<?php

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main id="content" class="ddb-semantic-main site-main" tabindex="-1">
    <?php echo sbdp_render_canonical_activities_overview(); ?>
</main>
<?php
get_footer();
