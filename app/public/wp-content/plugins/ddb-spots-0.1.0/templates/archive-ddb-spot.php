<?php
if (! defined('ABSPATH')) {
	exit;
}

// Canonical DDB shell: header → main → footer.
// get_header() fires elementor_theme_do_location('header') via hello-biz/header.php.
// get_footer() fires elementor_theme_do_location('footer') via hello-biz/footer.php.
// This file intentionally does NOT use get_header('shop') so the Elementor
// header/footer location system always resolves correctly.
get_header();
?>
<main id="content" class="site-main ddb-spots-archive" tabindex="-1">
	<div class="ddb-spots-archive__inner">
		<h1 class="screen-reader-text"><?php echo esc_html(post_type_archive_title('', false)); ?></h1>
		<?php echo do_shortcode('[ddb_spots per_page="24"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</main>
<?php
get_footer();
