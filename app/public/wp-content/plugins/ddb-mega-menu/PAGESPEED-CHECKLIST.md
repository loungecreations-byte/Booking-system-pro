# PageSpeed Checklist (Header + Mega Menu)

## Delivery
- Use only:
  - `assets/css/megamenu.css`
  - `assets/js/megamenu.js`
- Script strategy is `defer`.
- Keep icon rendering inline SVG (no icon font request).

## Markup
- Do not add nested containers unless needed for accessibility or layout.
- Keep menu labels short to reduce reflow risk.
- Keep logo dimensions explicit to avoid layout shift.

## Interaction
- No slider/carousel dependencies.
- No animation libraries.
- Respect `prefers-reduced-motion`.

## Runtime
- Verify no duplicate header instances per page template.
- Avoid extra shortcode wrappers in Elementor.
- Keep mobile bottom bar optional on content-heavy pages if not needed.

## QA
- Check CLS when header switches transparent -> solid on scroll.
- Check TBT impact of menu script in Lighthouse mobile run.
- Check Largest Contentful Paint with header and hero together.
