<?php
/** @var array $seeds */
/** @var string $msg */
?>
<section class="bsp-claim-shell" aria-labelledby="bsp-claim-title">
    <div class="bsp-claim-card">
        <div class="bsp-claim-header">
            <p class="bsp-claim-kicker"><?php esc_html_e('Partnerportaal', 'sbdp'); ?></p>
            <h1 id="bsp-claim-title"><?php esc_html_e('Claim uw locatie', 'sbdp'); ?></h1>
            <p><?php esc_html_e('Koppel uw bedrijf aan DagjeDenBosch.nl. Na verificatie beheren wij de partnerkoppeling in het portaal.', 'sbdp'); ?></p>
        </div>

        <?php if ($msg === 'sent') : ?>
            <div class="bsp-portal-notice bsp-portal-notice--success">
                <strong><?php esc_html_e('Aanvraag ingediend!', 'sbdp'); ?></strong>
                <p><?php esc_html_e('Controleer uw e-mail voor de verificatielink.', 'sbdp'); ?></p>
            </div>
        <?php elseif ($msg === 'error') : ?>
            <div class="bsp-portal-notice bsp-portal-notice--error">
                <p><?php esc_html_e('Er is iets misgegaan. Probeer het opnieuw.', 'sbdp'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (empty($seeds)) : ?>
            <div class="bsp-claim-empty">
                <h2><?php esc_html_e('Er staan nog geen claimbare locaties klaar', 'sbdp'); ?></h2>
                <p><?php esc_html_e('De claimlijst is leeg. Dat betekent meestal dat de locaties nog niet zijn gesynchroniseerd, of dat alle locaties al in behandeling zijn.', 'sbdp'); ?></p>
                <div class="bsp-claim-actions">
                    <a class="bsp-btn bsp-btn--primary" href="<?php echo esc_url(home_url('/partner-portal/')); ?>">
                        <?php esc_html_e('Naar partnerportaal', 'sbdp'); ?>
                    </a>
                    <a class="bsp-btn bsp-btn--secondary" href="<?php echo esc_url(home_url('/contact/')); ?>">
                        <?php esc_html_e('Locatie aanmelden', 'sbdp'); ?>
                    </a>
                </div>
            </div>
        <?php else : ?>
            <div class="bsp-claim-form-wrap">
                <p class="bsp-claim-intro"><?php esc_html_e('Selecteer uw bedrijf uit de lijst en ontvang een verificatielink per e-mail.', 'sbdp'); ?></p>

                <form method="post" class="bsp-claim-form">
                    <?php wp_nonce_field('bsp_submit_claim', '_wpnonce'); ?>
                    <input type="hidden" name="bsp_submit_claim" value="1">

                    <div class="bsp-field">
                        <label for="bsp-place-seed"><?php esc_html_e('Uw locatie', 'sbdp'); ?></label>
                        <select id="bsp-place-seed" name="place_seed_id" required>
                            <option value=""><?php esc_html_e('Selecteer een locatie', 'sbdp'); ?></option>
                            <?php foreach ($seeds as $seed) : ?>
                                <option value="<?php echo (int) $seed['id']; ?>">
                                    <?php echo esc_html($seed['name']); ?>
                                    <?php if ($seed['city']) : ?> - <?php echo esc_html($seed['city']); ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="bsp-btn bsp-btn--primary">
                        <?php esc_html_e('Verstuur verificatielink', 'sbdp'); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>
