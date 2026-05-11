<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$restBase        = isset($context['rest_base']) ? (string) $context['rest_base'] : '';
$nonce           = isset($context['nonce']) ? (string) $context['nonce'] : '';
$publicNonce     = isset($context['public_nonce']) ? (string) $context['public_nonce'] : '';
$pricingPreview  = isset($context['pricing_preview']) ? (string) $context['pricing_preview'] : '';
$session         = isset($context['session_id']) ? (string) $context['session_id'] : '';

$attributes = array(
    'data-rest-base'        => $restBase !== '' ? esc_url($restBase) : '',
    'data-rest'             => $restBase !== '' ? esc_url($restBase) : '',
    'data-nonce'            => $nonce !== '' ? esc_attr($nonce) : '',
    'data-public-nonce'     => $publicNonce !== '' ? esc_attr($publicNonce) : '',
    'data-public'           => $publicNonce !== '' ? esc_attr($publicNonce) : '',
    'data-pricing-preview'  => $pricingPreview !== '' ? esc_url($pricingPreview) : '',
    'data-pricing'          => $pricingPreview !== '' ? esc_url($pricingPreview) : '',
);

if ($session !== '') {
    $attributes['data-session-id'] = esc_attr($session);
}

$attributeString = '';
foreach ($attributes as $attribute => $value) {
    if ($value === '') {
        continue;
    }

    $attributeString .= sprintf(' %s="%s"', $attribute, $value);
}

printf('<div id="bpm-planner"%s></div>', $attributeString);
