<?php
/**
 * Elementor widget to render private tour steps.
 *
 * @package Booking_Pro_Module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Outputs the linked steps for the current private tour.
 */
class SBDP_Elementor_Tour_Steps_Widget extends Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'sbdp_tour_steps';
	}

	/**
	 * Widget label.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Privétour stappen', 'sbdp' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-bullet-list';
	}

	/**
	 * Widget category.
	 *
	 * @return array<int, string>
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Register widget controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Inhoud', 'sbdp' ),
			)
		);

		$this->add_control(
			'show_excerpt',
			array(
				'label'        => __( 'Stapbeschrijving tonen', 'sbdp' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Ja', 'sbdp' ),
				'label_off'    => __( 'Nee', 'sbdp' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_points',
			array(
				'label'        => __( 'Punten tonen', 'sbdp' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Ja', 'sbdp' ),
				'label_off'    => __( 'Nee', 'sbdp' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_type_badge',
			array(
				'label'        => __( 'Type-badge tonen', 'sbdp' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Ja', 'sbdp' ),
				'label_off'    => __( 'Nee', 'sbdp' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output.
	 */
	protected function render() {
		$post_id = get_the_ID();
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || 'sbdp_private_tour' !== $post->post_type ) {
			echo '<div class="sbdp-elementor-tour-steps sbdp-elementor-tour-steps--notice">';
			esc_html_e( 'Deze widget werkt alleen binnen een privétour template.', 'sbdp' );
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();
		$steps    = SBDP_Private_Tours_Tickets::get_steps_for_tour( $post_id );

		if ( empty( $steps ) ) {
			echo '<div class="sbdp-elementor-tour-steps sbdp-elementor-tour-steps--empty">';
			esc_html_e( 'Geen stappen gevonden voor deze tour.', 'sbdp' );
			echo '</div>';
			return;
		}

		echo '<div class="sbdp-elementor-tour-steps">';

		foreach ( $steps as $index => $step ) {
			$type_label = self::map_step_type_to_label( $step['type'] ?? '' );
			$points     = isset( $step['points'] ) ? (int) $step['points'] : 0;
			$content    = isset( $step['content'] ) ? (string) $step['content'] : '';

			printf(
				'<article class="sbdp-tour-step-card" data-step-id="%d">',
				(int) $step['id']
			);

			printf(
				'<header class="sbdp-tour-step-card__header"><span class="sbdp-tour-step-card__index">%s</span><h3 class="sbdp-tour-step-card__title">%s</h3></header>',
				esc_html( (string) ( $index + 1 ) ),
				esc_html( $step['title'] ?? __( 'Naamloze stap', 'sbdp' ) )
			);

			$meta_chips = array();

			if ( 'yes' === $settings['show_type_badge'] && '' !== $type_label ) {
				$meta_chips[] = sprintf(
					'<span class="sbdp-tour-step-card__chip">%s</span>',
					esc_html( $type_label )
				);
			}

			if ( 'yes' === $settings['show_points'] && $points > 0 ) {
				$meta_chips[] = sprintf(
					'<span class="sbdp-tour-step-card__chip">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: number of points. */
							__( '%d punten', 'sbdp' ),
							$points
						)
					)
				);
			}

			if ( ! empty( $meta_chips ) ) {
				printf(
					'<div class="sbdp-tour-step-card__meta">%s</div>',
					implode( '', $meta_chips )
				);
			}

			if ( 'yes' === $settings['show_excerpt'] && '' !== trim( $content ) ) {
				printf(
					'<div class="sbdp-tour-step-card__content">%s</div>',
					wp_kses_post( $content )
				);
			}

			echo '</article>';
		}

		echo '</div>';
	}

	/**
	 * Map step type slugs to readable labels.
	 *
	 * @param string $type Step type.
	 *
	 * @return string
	 */
	private static function map_step_type_to_label( string $type ): string {
		switch ( $type ) {
			case 'text':
				return __( 'Tekst', 'sbdp' );
			case 'audio':
				return __( 'Audio', 'sbdp' );
			case 'video':
				return __( 'Video', 'sbdp' );
			case 'vr':
				return __( 'VR / AR', 'sbdp' );
			case 'game':
				return __( 'Game', 'sbdp' );
			default:
				return '';
		}
	}
}

