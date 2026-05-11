from pathlib import Path
path = Path('modules/product-overview/assets/js/activity-overview/components/TopPicksStrip.tsx')
text = path.read_text()
needle = '{activity.durationLabel}  \x07 {activity.priceLabel}'
if needle not in text:
    raise SystemExit('needle missing')
replacement = '{activity.durationLabel}\n                <span aria-hidden="true" className="mx-2 text-slate-400">•</span>\n                {activity.priceLabel}'
path.write_text(text.replace(needle, replacement))
