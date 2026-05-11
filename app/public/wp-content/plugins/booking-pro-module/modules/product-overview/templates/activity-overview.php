<?php

declare(strict_types=1);

$configJson = wp_json_encode($component, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($configJson === false) {
    $configJson = '{}';
}

$componentId = isset($component['componentId']) && is_string($component['componentId'])
    ? $component['componentId']
    : uniqid('sbdp-ao-', false);
?>
<section
    class="sbdp-activity-overview"
    data-component="sbdp-activity-overview"
    data-component-id="<?php echo esc_attr($componentId); ?>"
    data-config="<?php echo esc_attr($configJson); ?>"
>
    <div
        class="sbdp-activity-overview__app"
        id="<?php echo esc_attr($componentId); ?>-root"
        data-role="activity-overview-root"
    ></div>
    <noscript class="sbdp-activity-overview__noscript">
        <?php esc_html_e('Activeer JavaScript om activiteiten te bekijken.', 'sbdp'); ?>
    </noscript>
</section>
