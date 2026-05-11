<?php

declare(strict_types=1);

namespace BSPModule\Core\Shortcodes;

/**
 * Shortcode implementations.
 *
 * @package SBDP
 */
final class Shortcodes
{
    /**
     * Register shortcodes.
     */
    public static function init()
    {
        add_shortcode('sbdp_dayplanner', array(__CLASS__, 'render_planner'));
        add_shortcode('sbdp_home_onboarding', array(__CLASS__, 'render_home_onboarding'));
        add_shortcode('sbdp_home_hero', array(__CLASS__, 'render_home_hero'));
        add_shortcode('sbdp_home_composer', array(__CLASS__, 'render_home_composer'));
        add_shortcode('sbdp_home_trust', array(__CLASS__, 'render_home_trust'));
        add_shortcode('sbdp_home_cta', array(__CLASS__, 'render_home_cta'));
        add_shortcode('ddb_featured_activities', array(__CLASS__, 'render_featured_activities'));
        add_shortcode('ddb_combo_deals', array(__CLASS__, 'render_combo_deals'));
    }

    /**
     * Render the frontend day planner scaffold.
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_planner($atts = array())
    {
        unset($atts);

        ob_start();
        ?>
		<section class="sbdp-day-planner-shell" aria-label="<?php echo esc_attr__('Plan je dag', 'sbdp'); ?>">
			<div class="sbdp-day-planner-shell__mounts">
				<div id="sbdp-day-planner-root" data-component="sbdp-day-planner" aria-hidden="true"></div>
			</div>
			<noscript>
				<p class="sbdp-day-planner__noscript">
					<?php esc_html_e('Schakel JavaScript in om te plannen.', 'sbdp'); ?>
				</p>
			</noscript>
		</section>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * Render a compact homepage hero block.
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_home_hero($atts = array())
    {
        $eyebrow = sanitize_text_field($atts['eyebrow'] ?? __('DagjeDenBosch.nl', 'sbdp'));
        $title   = sanitize_text_field($atts['title'] ?? __('Plan je dag in Den Bosch', 'sbdp'));
        $lede    = sanitize_text_field($atts['lede'] ?? __('Ontdek activiteiten en plekken die logisch samenkomen in een sterke dagindeling.', 'sbdp'));

        ob_start();
        ?>
		<section class="ddb-hp-hero" aria-label="<?php echo esc_attr__('Homepage intro', 'sbdp'); ?>">
			<div class="ddb-hp-hero__content">
				<p class="ddb-hp-hero__eyebrow"><?php echo esc_html($eyebrow); ?></p>
				<h1 class="ddb-hp-hero__headline"><?php echo esc_html($title); ?></h1>
				<p class="ddb-hp-hero__lede"><?php echo esc_html($lede); ?></p>
				<div class="ddb-hp-cta-group">
					<a class="ddb-hp-btn ddb-hp-btn--primary" href="<?php echo esc_url($atts['primary_url'] ?? '/plan-je-dag'); ?>">
						<?php echo esc_html($atts['primary_label'] ?? __('Start met plannen', 'sbdp')); ?>
					</a>
					<a class="ddb-hp-btn ddb-hp-btn--secondary" href="<?php echo esc_url($atts['secondary_url'] ?? '/activiteiten'); ?>">
						<?php echo esc_html($atts['secondary_label'] ?? __('Ontdek activiteiten', 'sbdp')); ?>
					</a>
				</div>
			</div>
		</section>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * Render a compact planner/composer block.
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_home_composer($atts = array())
    {
        $title      = sanitize_text_field($atts['title'] ?? __('Kies datum en gezelschap', 'sbdp'));
        $copy       = sanitize_text_field($atts['copy'] ?? __('Vul je datum en gezelschap in. Daarna tonen we rustige, goed combineerbare opties voor jouw dag.', 'sbdp'));
        $count      = isset($atts['count']) ? absint($atts['count']) : 2;
        $visit_date = sanitize_text_field($atts['visitDate'] ?? '');

        ob_start();
        ?>
		<section class="ddb-hp-composer" aria-label="<?php echo esc_attr__('Plan snel je dag', 'sbdp'); ?>">
			<div class="ddb-hp-composer__shell">
				<p class="ddb-hp-composer__label"><?php echo esc_html($atts['label'] ?? __('Snel starten', 'sbdp')); ?></p>
				<div class="ddb-hp-composer__intro">
					<h2 class="ddb-hp-hero__headline ddb-hp-composer__title"><?php echo esc_html($title); ?></h2>
					<p class="ddb-hp-hero__lede ddb-hp-composer__lede"><?php echo esc_html($copy); ?></p>
				</div>
				<div class="ddb-hp-composer__controls">
					<div class="ddb-hp-composer__field">
						<label><?php esc_html_e('Datum', 'sbdp'); ?></label>
						<input type="date" name="visitDate" value="<?php echo esc_attr($visit_date); ?>" />
					</div>
					<div class="ddb-hp-composer__field">
						<label><?php esc_html_e('Aantal personen', 'sbdp'); ?></label>
						<input type="number" name="count" min="1" max="50" value="<?php echo esc_attr($count); ?>" />
					</div>
				</div>
				<div class="ddb-hp-composer__divider"></div>
				<div class="ddb-hp-cta-group">
					<a class="ddb-hp-btn ddb-hp-btn--primary" href="<?php echo esc_url($atts['primary_url'] ?? '/plan-je-dag'); ?>">
						<?php echo esc_html($atts['primary_label'] ?? __('Plan je dag', 'sbdp')); ?>
					</a>
					<a class="ddb-hp-btn ddb-hp-btn--secondary" href="<?php echo esc_url($atts['secondary_url'] ?? '/activiteiten'); ?>">
						<?php echo esc_html($atts['secondary_label'] ?? __('Bekijk activiteiten', 'sbdp')); ?>
					</a>
				</div>
				<p class="ddb-hp-composer__badge"><?php esc_html_e('Je past dit later nog eenvoudig aan in je planning.', 'sbdp'); ?></p>
			</div>
		</section>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * Render the trust block for the homepage.
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_home_trust($atts = array())
    {
        $cards = array(
            array(
                'icon'  => sanitize_text_field($atts['icon_1'] ?? '✓'),
                'title' => sanitize_text_field($atts['trust_title_1'] ?? __('Lokaal samengesteld', 'sbdp')),
                'text'  => sanitize_text_field($atts['trust_text_1'] ?? __('Alle aanbevelingen sluiten aan op Den Bosch en jouw dagindeling.', 'sbdp')),
            ),
            array(
                'icon'  => sanitize_text_field($atts['icon_2'] ?? '⚡'),
                'title' => sanitize_text_field($atts['trust_title_2'] ?? __('Snel kiezen', 'sbdp')),
                'text'  => sanitize_text_field($atts['trust_text_2'] ?? __('Scanbare kaarten en duidelijke CTA’s houden het overzicht rustig.', 'sbdp')),
            ),
            array(
                'icon'  => sanitize_text_field($atts['icon_3'] ?? '★'),
                'title' => sanitize_text_field($atts['trust_title_3'] ?? __('Premium ervaring', 'sbdp')),
                'text'  => sanitize_text_field($atts['trust_text_3'] ?? __('Donkere en lichte thema’s delen dezelfde visuele grammatica.', 'sbdp')),
            ),
        );

        ob_start();
        ?>
		<section class="ddb-hp-trust" aria-label="<?php echo esc_attr__('Waarom DagjeDenBosch', 'sbdp'); ?>">
			<div class="ddb-hp-section-inner">
				<div class="ddb-hp-trust__grid">
					<?php foreach ($cards as $card) : ?>
						<article class="ddb-hp-trust-card">
							<div class="ddb-hp-trust-card__icon" aria-hidden="true"><?php echo esc_html($card['icon']); ?></div>
							<h3 class="ddb-hp-trust-card__title"><?php echo esc_html($card['title']); ?></h3>
							<p class="ddb-hp-trust-card__text"><?php echo esc_html($card['text']); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * Render the closing CTA block for the homepage.
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_home_cta($atts = array())
    {
        $title = sanitize_text_field($atts['title'] ?? __('Stel je dag slim samen', 'sbdp'));
        $lede  = sanitize_text_field($atts['lede'] ?? __('Start met plannen of ontdek eerst welke activiteiten en plekken vandaag bij je passen.', 'sbdp'));

        ob_start();
        ?>
		<section class="ddb-hp-cta ddb-hp-cta--accented" aria-label="<?php echo esc_attr__('Homepage CTA', 'sbdp'); ?>">
			<div class="ddb-hp-cta__inner">
				<h2 class="ddb-hp-cta__headline"><?php echo esc_html($title); ?></h2>
				<p class="ddb-hp-cta__lede"><?php echo esc_html($lede); ?></p>
				<div class="ddb-hp-cta__actions ddb-hp-cta-group">
					<a class="ddb-hp-btn ddb-hp-btn--primary" href="<?php echo esc_url($atts['primary_url'] ?? '/plan-je-dag'); ?>">
						<?php echo esc_html($atts['primary_label'] ?? __('Start met plannen', 'sbdp')); ?>
					</a>
					<a class="ddb-hp-btn ddb-hp-btn--secondary" href="<?php echo esc_url($atts['secondary_url'] ?? '/spots'); ?>">
						<?php echo esc_html($atts['secondary_label'] ?? __('Ontdek plekken', 'sbdp')); ?>
					</a>
				</div>
			</div>
		</section>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * Render the simplified onboarding path for a home page call-to-action.
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_home_onboarding($atts = array())
    {
        $style      = sanitize_text_field($atts['style'] ?? 'dark');
        $mode       = strtolower($style) === 'light' ? 'light' : 'dark';
        $audience   = sanitize_text_field($atts['audience'] ?? '');
        $count      = isset($atts['count']) ? absint($atts['count']) : 2;
        $visit_date = sanitize_text_field($atts['visitDate'] ?? '');
        $runtime    = self::buildHomeOnboardingRuntime();
        $runtimeJson = wp_json_encode($runtime, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        if ($runtimeJson === false) {
            $runtimeJson = 'null';
        }

        ob_start();
        ?>
		<section
			class="sbdp-home-widget sbdp-home-widget--<?php echo esc_attr($mode); ?>"
			aria-label="<?php echo esc_attr__('Plan een dag in Den Bosch', 'sbdp'); ?>"
			data-sbdp-home-widget
			data-component="sbdp-home-onboarding"
			data-sbdp-runtime-ready="<?php echo ! empty($runtime) ? '1' : '0'; ?>"
		>
			<div class="sbdp-home-widget__intro">
				<p class="sbdp-home-widget__eyebrow"><?php esc_html_e('DagjeDenBosch.nl', 'sbdp'); ?></p>
				<h2 class="sbdp-home-widget__title"><?php esc_html_e('Plan je dag in Den Bosch', 'sbdp'); ?></h2>
				<p class="sbdp-home-widget__copy">
					<?php esc_html_e('Selecteer datum en gezelschap. Daarna zie je activiteiten, routes en tickets die logisch op elkaar aansluiten.', 'sbdp'); ?>
				</p>
				<p class="sbdp-home-widget__copy">
					<?php esc_html_e('Beantwoord 3 korte vragen en start met een voorstel dat je daarna zelf verfijnt.', 'sbdp'); ?>
				</p>
				<ul class="sbdp-home-widget__usps">
					<li><?php esc_html_e('Gratis planner', 'sbdp'); ?></li>
					<li><?php esc_html_e('Lokale tips', 'sbdp'); ?></li>
					<li><?php esc_html_e('Tickets direct geregeld', 'sbdp'); ?></li>
				</ul>
			</div>

			<div class="sbdp-home-widget__form">
				<label class="sbdp-home-widget__field">
					<span><?php esc_html_e('Datum', 'sbdp'); ?></span>
					<div class="sbdp-home-widget__date-row">
						<input type="date" name="visitDate" value="<?php echo esc_attr($visit_date); ?>" placeholder="yyyy-mm-dd" />
						<div class="sbdp-home-widget__preset-chips" data-sbdp-date-presets>
							<button type="button" data-date="today"><?php esc_html_e('Vandaag', 'sbdp'); ?></button>
							<button type="button" data-date="tomorrow"><?php esc_html_e('Morgen', 'sbdp'); ?></button>
							<button type="button" data-date="weekend"><?php esc_html_e('Weekend', 'sbdp'); ?></button>
						</div>
					</div>
					<small class="sbdp-home-widget__hint" data-sbdp-hint-date><?php esc_html_e('Je kunt dit later aanpassen.', 'sbdp'); ?></small>
				</label>
				<label class="sbdp-home-widget__field">
					<span><?php esc_html_e('Aantal personen', 'sbdp'); ?></span>
					<input type="number" name="count" min="1" max="50" value="<?php echo esc_attr($count); ?>" />
				</label>
			</div>

			<div class="sbdp-home-widget__actions">
				<a class="sbdp-home-widget__btn sbdp-home-widget__btn--ghost" href="/activiteiten" data-sbdp-activities>
					<?php esc_html_e('Bekijk activiteiten', 'sbdp'); ?>
				</a>
				<button type="button" class="sbdp-home-widget__btn sbdp-home-widget__btn--primary" data-sbdp-open>
					<?php esc_html_e('Plan je dag', 'sbdp'); ?>
				</button>
				<span class="sbdp-home-widget__inline-hint" hidden data-sbdp-inline-hint><?php esc_html_e('Vul een datum en aantal personen in om door te gaan.', 'sbdp'); ?></span>
			</div>

			<div class="sbdp-home-widget__modal" hidden data-sbdp-modal>
				<div class="sbdp-home-widget__modal-card" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Plan je dag', 'sbdp'); ?>">
					<button type="button" class="sbdp-home-widget__close" aria-label="<?php echo esc_attr__('Sluiten', 'sbdp'); ?>" data-sbdp-close>&times;</button>
					<p class="sbdp-home-widget__badge"><?php esc_html_e('Jeroen Bosch vraagt...', 'sbdp'); ?></p>
					<p class="sbdp-home-widget__subtitle"><?php esc_html_e('We stellen je dag samen en reserveren wat nodig is.', 'sbdp'); ?></p>

					<div class="sbdp-home-widget__progress">
						<span data-sbdp-progress>1</span><span>/3</span>
					</div>

					<div class="sbdp-home-widget__question">
						<p class="sbdp-home-widget__question-label"><?php esc_html_e('1. Kies duur', 'sbdp'); ?></p>
						<div class="sbdp-home-widget__chips" data-sbdp-chip-group="duration">
							<button type="button" data-value="hele-dag"><?php esc_html_e('Hele dag', 'sbdp'); ?></button>
							<button type="button" data-value="ochtend"><?php esc_html_e('Ochtend', 'sbdp'); ?></button>
							<button type="button" data-value="middag"><?php esc_html_e('Middag', 'sbdp'); ?></button>
							<button type="button" data-value="avond"><?php esc_html_e('Avond', 'sbdp'); ?></button>
							<button type="button" data-value="weekend"><?php esc_html_e('Weekend', 'sbdp'); ?></button>
						</div>
					</div>

					<div class="sbdp-home-widget__question">
						<p class="sbdp-home-widget__question-label"><?php esc_html_e('2. Met wie ga je?', 'sbdp'); ?></p>
						<div class="sbdp-home-widget__chips" data-sbdp-chip-group="company">
							<button type="button" data-value="partner"><?php esc_html_e('Met partner', 'sbdp'); ?></button>
							<button type="button" data-value="gezin"><?php esc_html_e('Met gezin/kids', 'sbdp'); ?></button>
							<button type="button" data-value="vrienden"><?php esc_html_e('Met vrienden', 'sbdp'); ?></button>
							<button type="button" data-value="collegas"><?php esc_html_e('Met collega\'s', 'sbdp'); ?></button>
							<button type="button" data-value="solo"><?php esc_html_e('Solo', 'sbdp'); ?></button>
						</div>
					</div>

					<div class="sbdp-home-widget__question">
						<p class="sbdp-home-widget__question-label"><?php esc_html_e('3. Wat zoek je vandaag?', 'sbdp'); ?></p>
						<div class="sbdp-home-widget__chips" data-sbdp-chip-group="vibe">
							<button type="button" data-value="cultuur"><?php esc_html_e('Cultuur', 'sbdp'); ?></button>
							<button type="button" data-value="shoppen"><?php esc_html_e('Shoppen', 'sbdp'); ?></button>
							<button type="button" data-value="kidsproof"><?php esc_html_e('Kidsproof', 'sbdp'); ?></button>
							<button type="button" data-value="bourgondisch"><?php esc_html_e('Bourgondisch', 'sbdp'); ?></button>
							<button type="button" data-value="verrassend"><?php esc_html_e('Verrassend', 'sbdp'); ?></button>
						</div>
					</div>

					<div class="sbdp-home-widget__modal-actions">
						<button type="button" class="sbdp-home-widget__btn sbdp-home-widget__btn--ghost" data-sbdp-close>
							<?php esc_html_e('Annuleren', 'sbdp'); ?>
						</button>
						<button type="button" class="sbdp-home-widget__btn sbdp-home-widget__btn--primary" data-sbdp-submit>
							<?php esc_html_e('Start mijn dag', 'sbdp'); ?>
						</button>
					</div>
				</div>
			</div>
		</section>
		<style>
			.sbdp-home-widget{--sbdp-bg:linear-gradient(135deg,var(--ui-color-bg) 0%,color-mix(in srgb,var(--ui-color-surface) 94%,var(--ui-color-primary) 6%) 50%,var(--ui-color-bg) 100%);--sbdp-fg:var(--ui-color-text);--sbdp-sub:var(--ui-color-text-muted);--sbdp-border:var(--ui-color-border);--sbdp-card:var(--ui-color-surface);--sbdp-muted:var(--ui-color-text-muted);--sbdp-accent:linear-gradient(120deg,var(--ui-color-primary) 0%,var(--ui-color-primary-hover) 100%);--sbdp-accent-plain:var(--ui-color-primary);--sbdp-ghost:color-mix(in srgb,var(--ui-color-primary) 6%,transparent);--sbdp-ghost-border:color-mix(in srgb,var(--ui-color-primary) 14%,var(--ui-color-border));--sbdp-chip-bg:var(--ui-color-surface-2);--sbdp-chip-border:var(--ui-color-border);--sbdp-shadow:var(--ui-shadow-lg);--sbdp-highlight:var(--ui-color-primary)}
			.sbdp-home-widget--light{--sbdp-bg:linear-gradient(135deg,var(--ui-color-bg) 0%,color-mix(in srgb,var(--ui-color-surface) 94%,var(--ui-color-primary) 6%) 50%,var(--ui-color-surface-2) 100%);--sbdp-fg:var(--ui-color-text);--sbdp-sub:var(--ui-color-text-muted);--sbdp-border:var(--ui-color-border);--sbdp-card:var(--ui-color-surface);--sbdp-muted:var(--ui-color-text-muted);--sbdp-accent:linear-gradient(120deg,var(--ui-color-primary) 0%,var(--ui-color-primary-hover) 100%);--sbdp-accent-plain:var(--ui-color-primary);--sbdp-ghost:color-mix(in srgb,var(--ui-color-primary) 4%,transparent);--sbdp-ghost-border:color-mix(in srgb,var(--ui-color-primary) 12%,var(--ui-color-border));--sbdp-chip-bg:var(--ui-color-surface);--sbdp-chip-border:var(--ui-color-border);--sbdp-shadow:var(--ui-shadow-md);--sbdp-highlight:var(--ui-color-primary)}
			.sbdp-home-widget{background:radial-gradient(circle at 20% 20%,color-mix(in srgb,var(--ui-color-primary) 14%,transparent),transparent 32%),radial-gradient(circle at 80% 10%,color-mix(in srgb,var(--ui-color-primary) 18%,transparent),transparent 28%),var(--sbdp-bg);color:var(--sbdp-fg);border-radius:18px;padding:28px;border:1px solid var(--sbdp-border);position:relative;overflow:hidden;box-shadow:var(--sbdp-shadow)}
			.sbdp-home-widget__intro{max-width:640px}
			.sbdp-home-widget__eyebrow{letter-spacing:0.06em;font-size:12px;text-transform:uppercase;margin:0 0 6px;color:var(--sbdp-highlight)}
			.sbdp-home-widget__title{margin:0 0 8px;font-size:28px;line-height:1.2}
			.sbdp-home-widget__copy{margin:0 0 12px;color:var(--sbdp-sub)}
			.sbdp-home-widget__usps{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px;padding:0;list-style:none;color:var(--sbdp-sub);font-size:13px}
			.sbdp-home-widget__usps li{background:var(--sbdp-chip-bg);border:1px solid var(--sbdp-chip-border);border-radius:999px;padding:6px 10px}
			.sbdp-home-widget__form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
			.sbdp-home-widget__field{display:flex;flex-direction:column;font-size:14px;color:var(--sbdp-muted);gap:6px}
			.sbdp-home-widget__date-row{display:flex;flex-direction:column;gap:8px}
			.sbdp-home-widget__preset-chips{display:flex;flex-wrap:wrap;gap:6px}
			.sbdp-home-widget__preset-chips button{border:1px solid var(--sbdp-chip-border);background:var(--sbdp-chip-bg);color:var(--sbdp-fg);border-radius:999px;padding:6px 10px;font-size:13px;cursor:pointer;transition:all 120ms ease}
			.sbdp-home-widget__preset-chips button:hover{border-color:var(--sbdp-accent-plain);color:var(--ui-color-primary-contrast);background:var(--sbdp-accent)}
			.sbdp-home-widget__field input{background:var(--sbdp-card);border:1px solid var(--sbdp-border);color:var(--sbdp-fg);border-radius:10px;padding:10px 12px;font-size:15px}
			.sbdp-home-widget__hint{color:var(--sbdp-muted);font-size:12px}
			.sbdp-home-widget__field input:focus{outline:2px solid var(--sbdp-accent-plain);box-shadow:0 0 0 2px color-mix(in srgb,var(--ui-color-primary) 20%,transparent)}
			.sbdp-home-widget__actions{display:flex;flex-wrap:wrap;gap:10px}
			.sbdp-home-widget__btn{border:none;cursor:pointer;border-radius:12px;padding:12px 16px;font-size:15px;font-weight:600;transition:all 160ms ease;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}
			.sbdp-home-widget__btn--primary{background:var(--sbdp-accent);color:var(--ui-color-primary-contrast);box-shadow:0 8px 30px color-mix(in srgb,var(--ui-color-primary) 35%,transparent)}
			.sbdp-home-widget__btn--ghost{background:var(--sbdp-ghost);color:var(--sbdp-sub);border:1px solid var(--sbdp-ghost-border)}
			.sbdp-home-widget__btn:hover{transform:translateY(-1px);box-shadow:0 10px 24px color-mix(in srgb,var(--ui-color-primary) 18%,transparent)}
			.sbdp-home-widget__inline-hint{font-size:13px;color:#fca5a5}
			.sbdp-home-widget__modal{position:fixed;inset:0;background:color-mix(in srgb,var(--ui-color-bg) 82%,transparent);display:grid;place-items:center;z-index:9999;padding:16px}
			.sbdp-home-widget__modal[hidden]{display:none }
			.sbdp-home-widget__modal-card{background:var(--sbdp-card);border:1px solid var(--sbdp-border);border-radius:18px;max-width:640px;width:100%;padding:22px 22px 18px;position:relative;box-shadow:0 24px 80px color-mix(in srgb,var(--ui-color-primary) 22%,transparent);animation: sbdpSlideUp 200ms ease-out;color:var(--sbdp-fg)}
			.sbdp-home-widget__badge{display:inline-flex;padding:6px 10px;border-radius:999px;background:color-mix(in srgb,var(--ui-color-primary) 12%,transparent);color:var(--sbdp-highlight);font-weight:700;letter-spacing:0.02em;margin:0 0 14px;font-size:12px;text-transform:uppercase}
			.sbdp-home-widget__subtitle{margin:0 0 10px;color:var(--sbdp-muted);font-size:14px}
			.sbdp-home-widget__progress{display:flex;align-items:center;gap:4px;font-weight:700;color:var(--sbdp-highlight);margin-bottom:10px}
			.sbdp-home-widget__question{margin:0 0 16px}
			.sbdp-home-widget__question-label{margin:0 0 8px;font-weight:700;color:var(--sbdp-fg)}
			.sbdp-home-widget__chips{display:flex;flex-wrap:wrap;gap:8px}
			.sbdp-home-widget__chips button{border:1px solid var(--sbdp-chip-border);background:var(--sbdp-chip-bg);color:var(--sbdp-fg);border-radius:999px;padding:8px 12px;font-size:14px;cursor:pointer;transition:all 120ms ease}
			.sbdp-home-widget__chips button.is-active{background:var(--sbdp-accent);border-color:transparent;color:var(--ui-color-primary-contrast);box-shadow:0 6px 16px color-mix(in srgb,var(--ui-color-primary) 35%,transparent);transform:translateY(-1px) scale(1.01)}
			.sbdp-home-widget__chips button:hover{border-color:var(--sbdp-accent-plain);color:var(--ui-color-primary-contrast)}
			.sbdp-home-widget__modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:6px}
			.sbdp-home-widget__close{position:absolute;right:12px;top:8px;font-size:22px;color:var(--sbdp-muted);background:none;border:none;cursor:pointer}
			@media (max-width:640px){.sbdp-home-widget{padding:22px}.sbdp-home-widget__title{font-size:22px}.sbdp-home-widget__modal{padding:0}.sbdp-home-widget__modal-card{padding:20px 18px;max-width:100%;height:100%;border-radius:0;overflow:auto}.sbdp-home-widget__chips{gap:6px}.sbdp-home-widget__modal-actions{position:sticky;bottom:0;background:var(--sbdp-card);padding:10px 0;margin-top:16px}}
			@keyframes sbdpSlideUp{from{transform:translateY(12px);opacity:0}to{transform:translateY(0);opacity:1}}
		</style>
		<script>
			window.SBDP_HomeOnboardingRuntime = <?php echo $runtimeJson; ?>;
		</script>
		<script>
			(function() {
				const widgets = document.querySelectorAll('[data-sbdp-home-widget]');
				widgets.forEach(function(root) {
					const modal = root.querySelector('[data-sbdp-modal]');
					const openBtn = root.querySelector('[data-sbdp-open]');
					const closeBtns = root.querySelectorAll('[data-sbdp-close]');
					const submitBtn = root.querySelector('[data-sbdp-submit]');
					const activitiesLink = root.querySelector('[data-sbdp-activities]');
					const dateInput = root.querySelector('input[name="visitDate"]');
					const countInput = root.querySelector('input[name="count"]');
					const inlineHint = root.querySelector('[data-sbdp-inline-hint]');
					const progress = root.querySelector('[data-sbdp-progress]');
					const hintDate = root.querySelector('[data-sbdp-hint-date]');
					const presetContainer = root.querySelector('[data-sbdp-date-presets]');
					const state = { duration: '', company: '', vibe: '' };
					const runtime =
						window.SBDP_HomeOnboardingRuntime &&
						typeof window.SBDP_HomeOnboardingRuntime === 'object'
							? window.SBDP_HomeOnboardingRuntime
							: null;

					function resolveRuntimeTarget() {
						if (!runtime) {
							return null;
						}

						const routeIntent = runtime.route_intent || runtime.routeIntent || null;
						if (routeIntent === 'checkout') {
							return runtime.checkout_url || runtime.checkoutUrl || null;
						}
						if (routeIntent === 'quote') {
							return runtime.quote_url || runtime.quoteUrl || null;
						}
						if (routeIntent === 'blocked') {
							return runtime.blocked_url || runtime.blockedUrl || null;
						}

						return null;
					}

					function pad2(val) {
						return String(val).padStart(2, '0');
					}

					function toLocalISO(date) {
						const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
						return local.toISOString().split('T')[0];
					}

					function normalizeDateValue(input) {
						if (!input) return '';
						const raw = String(input).trim();
						if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
							return raw;
						}
						const match = raw.match(/^(\d{1,2})[-\/]?(\d{1,2})(?:[-\/]?(\d{2,4}))?$/);
						if (match) {
							const day = parseInt(match[1], 10);
							const month = parseInt(match[2], 10);
							const year = match[3]
								? parseInt(match[3].length === 2 ? `20${match[3]}` : match[3], 10)
								: new Date().getFullYear();
							if (
								Number.isFinite(day) && day >= 1 && day <= 31 &&
								Number.isFinite(month) && month >= 1 && month <= 12 &&
								Number.isFinite(year) && year > 1900
							) {
								return `${year}-${pad2(month)}-${pad2(day)}`;
							}
						}
						return '';
					}

					function setTodayIfEmpty() {
						if (!dateInput || dateInput.value) {
							return;
						}
						const today = new Date();
						dateInput.value = toLocalISO(today);
					}

					function applyPreset(code) {
						if (!dateInput) {
							return;
						}
						const today = new Date();
						if (code === 'tomorrow') {
							today.setDate(today.getDate() + 1);
						}
						if (code === 'weekend') {
							const day = today.getDay();
							const offset = day === 0 ? 6 : 6 - day;
							today.setDate(today.getDate() + offset);
						}
						dateInput.value = toLocalISO(today);
					}

					function toggleModal(show) {
						if (! modal) {
							return;
						}
						if (show) {
							modal.hidden = false;
							document.body.style.overflow = 'hidden';
						} else {
							modal.hidden = true;
							document.body.style.overflow = '';
						}
					}

					function bindChips() {
						root.querySelectorAll('[data-sbdp-chip-group]').forEach(function(group) {
							const key = group.getAttribute('data-sbdp-chip-group');
							let index = 1;
							group.querySelectorAll('button').forEach(function(btn) {
								btn.addEventListener('click', function() {
									group.querySelectorAll('button').forEach(function(other) {
										other.classList.remove('is-active');
									});
									btn.classList.add('is-active');
									state[key] = btn.getAttribute('data-value') || '';
									index = Array.from(group.querySelectorAll('button')).indexOf(btn) + 1;
									if (progress) {
										const step = key === 'duration' ? 1 : key === 'company' ? 2 : 3;
										progress.textContent = String(step);
									}
								});
							});
						});
					}

					function showInlineHint(show) {
						if (!inlineHint) {
							return;
						}
						inlineHint.hidden = !show;
						if (show && dateInput) {
							dateInput.focus();
						}
					}

					function buildUrl(path) {
						try {
							if (!path || typeof path !== 'string') {
								return null;
							}
							const url = new URL(path, window.location.origin);
							const normalizedDate = normalizeDateValue(dateInput?.value || '');
							if (normalizedDate) {
								if (dateInput) {
									dateInput.value = normalizedDate;
								}
								url.searchParams.set('visitDate', normalizedDate);
								url.searchParams.set('date', normalizedDate);
							}
						const count = countInput?.value ? parseInt(countInput.value, 10) : 0;
						if (count > 0) {
							url.searchParams.set('count', String(count));
							url.searchParams.set('participants', String(count));
						}
						if (state.duration) {
							url.searchParams.set('duration', state.duration);
						}
						if (state.company) {
							url.searchParams.set('audience', state.company);
						}
						if (state.vibe) {
							url.searchParams.set('vibe', state.vibe);
						}
						return url;
						} catch (e) {
							console.warn('[SBDP] URL construction failed:', e);
							return null;
						}
					}

					openBtn?.addEventListener('click', function() {
						const normalizedDate = normalizeDateValue(dateInput?.value || '');
						const runtimeTarget = resolveRuntimeTarget();
						if (!normalizedDate || !countInput?.value || parseInt(countInput.value, 10) <= 0) {
							showInlineHint(true);
							return;
						}
						if (!runtimeTarget) {
							if (inlineHint) {
								inlineHint.textContent = 'Starten is tijdelijk niet beschikbaar.';
							}
							showInlineHint(true);
							return;
						}
						if (dateInput) {
							dateInput.value = normalizedDate;
						}
						showInlineHint(false);
						toggleModal(true);
					});

					closeBtns.forEach(function(btn) {
						btn.addEventListener('click', function() {
							toggleModal(false);
						});
					});

					modal?.addEventListener('click', function(evt) {
						if (evt.target === modal) {
							toggleModal(false);
						}
					});

					submitBtn?.addEventListener('click', function() {
						const runtimeTarget = resolveRuntimeTarget();
						const url = buildUrl(runtimeTarget);
						if (!url) {
							return;
						}
						window.location.href = url.pathname + url.search;
					});

					activitiesLink?.addEventListener('click', function(evt) {
						const url = buildUrl('/activiteiten');
						activitiesLink.setAttribute('href', url.pathname + url.search);
					});

					presetContainer?.addEventListener('click', function(evt) {
						const target = evt.target;
						if (!(target instanceof HTMLElement) || !target.dataset.date) {
							return;
						}
						applyPreset(target.dataset.date);
						if (hintDate) {
							hintDate.textContent = target.textContent || hintDate.textContent;
						}
					});

					// Simple tracking hooks (custom events)
					activitiesLink?.addEventListener('click', function() {
						try { window.dispatchEvent(new CustomEvent('sbdp:widget', { detail: { action: 'click_activities' } })); } catch (e) {}
					});
					openBtn?.addEventListener('click', function() {
						try { window.dispatchEvent(new CustomEvent('sbdp:widget', { detail: { action: 'open_modal' } })); } catch (e) {}
					});
					submitBtn?.addEventListener('click', function() {
						try { window.dispatchEvent(new CustomEvent('sbdp:widget', { detail: { action: 'submit_modal', duration: state.duration, company: state.company, vibe: state.vibe } })); } catch (e) {}
					});

					bindChips();
					setTodayIfEmpty();
					if (!resolveRuntimeTarget() && openBtn) {
						openBtn.setAttribute('disabled', 'true');
						openBtn.setAttribute('aria-disabled', 'true');
					}
				});
			})();
		</script>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * @return array<string, string>
     */
    private static function buildHomeOnboardingRuntime(): array
    {
        $bookingFlow = (string) get_option('sbdp_booking_flow', 'pay');
        $plannerUrl = self::resolvePlannerUrl();
        $quoteUrl = self::resolveQuoteUrl();

        $runtime = array();
        if ($bookingFlow === 'request') {
            if ($quoteUrl !== '') {
                $runtime = array(
                    'route_intent' => 'quote',
                    'quote_url'    => $quoteUrl,
                );
            }
        } elseif ($plannerUrl !== '') {
            $runtime = array(
                'route_intent' => 'checkout',
                'checkout_url' => $plannerUrl,
            );
        }

        /**
         * Allow environment-specific runtime publication without recreating
         * client-side fallback truth.
         *
         * @param array<string, string> $runtime
         */
        return (array) apply_filters('sbdp_home_onboarding_runtime', $runtime);
    }

    private static function resolvePlannerUrl(): string
    {
        $pageId = (int) get_option('sbdp_planner_page_id', 0);
        if ($pageId > 0) {
            $link = get_permalink($pageId);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        $page = get_page_by_path('plan-je-dag');
        if ($page instanceof \WP_Post) {
            $link = get_permalink($page);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        return '';
    }

    private static function resolveQuoteUrl(): string
    {
        $page = get_page_by_path('offerte');
        if ($page instanceof \WP_Post) {
            $link = get_permalink($page);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        return '';
    }

    /**
     * Render a featured activities grid for the homepage.
     *
     * Usage: [ddb_featured_activities ids="1,2,3" limit="4" display="grid"]
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_featured_activities($atts = array())
    {
        if (! function_exists('wc_get_products')) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'ids'     => '',
                'limit'   => '4',
                'display' => 'grid',
            ),
            $atts,
            'ddb_featured_activities'
        );

        $limit = min(12, max(1, absint($atts['limit'])));

        $raw_ids = array_filter(array_map('absint', explode(',', (string) $atts['ids'])));

        if (! empty($raw_ids)) {
            $query_args = array(
                'status'  => 'publish',
                'include' => $raw_ids,
                'limit'   => count($raw_ids),
                'orderby' => 'include',
            );
        } else {
            $query_args = array(
                'status'   => 'publish',
                'limit'    => $limit,
                'orderby'  => 'menu_order',
                'order'    => 'ASC',
                'featured' => true,
            );
        }

        $products = wc_get_products($query_args);
        if (empty($products)) {
            $query_args_fallback = array(
                'status'  => 'publish',
                'limit'   => $limit,
                'orderby' => 'date',
                'order'   => 'DESC',
            );
            $products = wc_get_products($query_args_fallback);
        }

        if (empty($products)) {
            return '';
        }

        $display = sanitize_html_class($atts['display']);

        ob_start();
        ?>
		<div class="ddb-hp-activities ddb-hp-activities--<?php echo esc_attr($display); ?>" aria-label="<?php echo esc_attr__('Uitgelichte activiteiten', 'sbdp'); ?>">
			<?php foreach ($products as $product) :
                if (! $product instanceof \WC_Product) {
                    continue;
                }
                $product_id   = $product->get_id();
                $title        = $product->get_name();
                $permalink    = get_permalink($product_id);
                $price_html   = $product->get_price_html();
                $thumb_id     = $product->get_image_id();
                $thumb_html   = $thumb_id > 0 ? wp_get_attachment_image((int) $thumb_id, 'medium_large', false, array('class' => 'ui-listing-card__image', 'loading' => 'lazy')) : '';
                $cats         = wc_get_product_category_list($product_id, '|');
                $cat_label    = wp_strip_all_tags($cats ? wp_kses_post($cats) : '');
                $cat_first    = explode('|', $cat_label)[0] ?? '';
                $duration_raw = (string) get_post_meta($product_id, '_sbdp_duration_label', true);
                $duration     = '' !== $duration_raw ? sanitize_text_field($duration_raw) : '';
                ?>
			<article class="ui-listing-card ddb-card ddb-hp-activity-card">
				<a class="ddb-card__link" href="<?php echo esc_url($permalink); ?>">
					<div class="ui-listing-card__media ddb-card__media">
						<?php if ('' !== $thumb_html) : ?>
							<?php echo $thumb_html; ?>
						<?php else : ?>
							<div class="ui-listing-card__placeholder"></div>
						<?php endif; ?>
					</div>
					<div class="ui-listing-card__overlay ddb-card__body">
						<header class="ui-listing-card__header">
							<div class="ui-listing-card__header-main">
								<?php if ('' !== $cat_first) : ?>
									<p class="ui-listing-card__eyebrow"><?php echo esc_html($cat_first); ?></p>
								<?php endif; ?>
								<h3 class="ui-listing-card__title ddb-card__title"><?php echo esc_html($title); ?></h3>
							</div>
							<?php if ('' !== $price_html) : ?>
								<span class="ui-listing-card__price"><?php echo wp_kses_post($price_html); ?></span>
							<?php endif; ?>
						</header>
						<?php if ('' !== $duration) : ?>
							<ul class="ui-listing-card__meta">
								<li class="ui-listing-card__meta-item"><?php echo esc_html($duration); ?></li>
							</ul>
						<?php endif; ?>
					</div>
				</a>
				<div class="ui-listing-card__actions ddb-card__actions">
					<a class="ui-listing-card__cta ui-listing-card__cta--primary ddb-card__cta" href="<?php echo esc_url($permalink); ?>">
						<?php esc_html_e('Bekijk activiteit', 'sbdp'); ?>
					</a>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<?php
        return trim(ob_get_clean());
    }

    /**
     * Render a combo deals section for the homepage.
     *
     * Usage: [ddb_combo_deals ids="1,2,3" limit="3" style="cards"]
     *
     * @param array<string,string> $atts Shortcode attributes.
     *
     * @return string
     */
    public static function render_combo_deals($atts = array())
    {
        if (! function_exists('wc_get_products')) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'ids'   => '',
                'limit' => '3',
                'style' => 'cards',
            ),
            $atts,
            'ddb_combo_deals'
        );

        $limit   = min(12, max(1, absint($atts['limit'])));
        $raw_ids = array_filter(array_map('absint', explode(',', (string) $atts['ids'])));

        if (! empty($raw_ids)) {
            $query_args = array(
                'status'  => 'publish',
                'include' => $raw_ids,
                'limit'   => count($raw_ids),
                'orderby' => 'include',
            );
            $products = wc_get_products($query_args);
        } else {
            // Try combo category slugs first, fall back to featured.
            $combo_slugs = array('combideal', 'pakket', 'combo');
            $products    = array();
            foreach ($combo_slugs as $slug) {
                $products = wc_get_products(
                    array(
                        'status'   => 'publish',
                        'limit'    => $limit,
                        'orderby'  => 'menu_order',
                        'order'    => 'ASC',
                        'category' => array($slug),
                    )
                );
                if (! empty($products)) {
                    break;
                }
            }
            if (empty($products)) {
                $products = wc_get_products(
                    array(
                        'status'   => 'publish',
                        'limit'    => $limit,
                        'orderby'  => 'menu_order',
                        'order'    => 'ASC',
                        'featured' => true,
                    )
                );
            }
        }

        if (empty($products)) {
            return '';
        }

        $style = sanitize_html_class($atts['style']);

        ob_start();
        ?>
		<div class="ddb-hp-combo-deals ddb-hp-combo-deals--<?php echo esc_attr($style); ?>" aria-label="<?php echo esc_attr__('Combideals', 'sbdp'); ?>">
			<?php foreach ($products as $product) :
                if (! $product instanceof \WC_Product) {
                    continue;
                }
                $product_id = $product->get_id();
                $title      = $product->get_name();
                $permalink  = get_permalink($product_id);
                $price_html = $product->get_price_html();
                $thumb_id   = $product->get_image_id();
                $thumb_html = $thumb_id > 0 ? wp_get_attachment_image((int) $thumb_id, 'medium_large', false, array('class' => 'ui-listing-card__image', 'loading' => 'lazy')) : '';
                $summary    = $product->get_short_description();
                $summary    = '' !== $summary ? wp_trim_words(wp_strip_all_tags($summary), 16, '...') : '';
                ?>
			<article class="ui-listing-card ddb-card ddb-hp-combo-card">
				<a class="ddb-card__link" href="<?php echo esc_url($permalink); ?>">
					<div class="ui-listing-card__media ddb-card__media">
						<?php if ('' !== $thumb_html) : ?>
							<?php echo $thumb_html; ?>
						<?php else : ?>
							<div class="ui-listing-card__placeholder"></div>
						<?php endif; ?>
					</div>
					<div class="ui-listing-card__overlay ddb-card__body">
						<header class="ui-listing-card__header">
							<div class="ui-listing-card__header-main">
								<h3 class="ui-listing-card__title ddb-card__title"><?php echo esc_html($title); ?></h3>
								<?php if ('' !== $summary) : ?>
									<p class="ddb-card__summary"><?php echo esc_html($summary); ?></p>
								<?php endif; ?>
							</div>
							<?php if ('' !== $price_html) : ?>
								<span class="ui-listing-card__price"><?php echo wp_kses_post($price_html); ?></span>
							<?php endif; ?>
						</header>
					</div>
				</a>
				<div class="ui-listing-card__actions ddb-card__actions">
					<a class="ui-listing-card__cta ui-listing-card__cta--primary ddb-card__cta" href="<?php echo esc_url($permalink); ?>">
						<?php esc_html_e('Bekijk pakket', 'sbdp'); ?>
					</a>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<?php
        return trim(ob_get_clean());
    }
}
