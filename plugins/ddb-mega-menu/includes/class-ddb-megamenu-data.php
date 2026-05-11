<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DDB_MegaMenu_Data
{
    public static function get_menu_items(array $settings = []): array
    {
        $custom_structure = self::parse_custom_menu_structure_json((string) ($settings['custom_menu_structure_json'] ?? ''));
        $items = !empty($custom_structure) ? $custom_structure : self::default_menu_items();

        $custom = self::parse_custom_menu_json((string) ($settings['custom_menu_json'] ?? ''));
        if (!empty($custom)) {
            foreach ($items as &$item) {
                $id = $item['id'] ?? '';
                if ($id === '' || !isset($custom[$id]) || !is_array($custom[$id])) {
                    continue;
                }

                if (!empty($custom[$id]['label'])) {
                    $item['label'] = sanitize_text_field((string) $custom[$id]['label']);
                }

                if (!empty($custom[$id]['url'])) {
                    $item['url'] = esc_url_raw((string) $custom[$id]['url']);
                }
            }
            unset($item);
        }

        return (array) apply_filters('ddb_megamenu_items', $items, $settings);
    }

    public static function get_default_menu_items(): array
    {
        return self::default_menu_items();
    }

    public static function sanitize_menu_items(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = self::sanitize_item($item, (int) $index);
            if ($row === null) {
                continue;
            }

            $sanitized[] = $row;
        }

        return $sanitized;
    }

    public static function get_actions(array $settings = []): array
    {
        $actions = [
            [
                'id' => 'search',
                'label' => 'Search',
                'url' => home_url('/activiteiten/'),
                'icon' => 'search',
            ],
            [
                'id' => 'planner',
                'label' => 'Planner',
                'url' => home_url('/plan-je-dag/'),
                'icon' => 'calendar',
            ],
            [
                'id' => 'account',
                'label' => 'Account',
                'url' => home_url('/my-account/'),
                'icon' => 'user',
            ],
        ];

        return (array) apply_filters('ddb_megamenu_actions', $actions, $settings);
    }

    private static function parse_custom_menu_json(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function parse_custom_menu_structure_json(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::sanitize_menu_items($decoded);
    }

    private static function sanitize_item(array $item, int $index): ?array
    {
        $label = sanitize_text_field((string) ($item['label'] ?? ''));
        if ($label === '') {
            return null;
        }

        $kind = sanitize_key((string) ($item['kind'] ?? 'link'));
        if (!in_array($kind, ['mega', 'dropdown', 'link'], true)) {
            $kind = 'link';
        }

        $id_raw = sanitize_key((string) ($item['id'] ?? ''));
        $id = $id_raw !== '' ? $id_raw : sanitize_key($label);
        if ($id === '') {
            $id = 'item-' . (string) ($index + 1);
        }

        $row = [
            'id' => $id,
            'label' => $label,
            'url' => esc_url_raw((string) ($item['url'] ?? '#')),
            'kind' => $kind,
        ];

        if ($kind === 'mega') {
            $columns = [];
            if (isset($item['columns']) && is_array($item['columns'])) {
                foreach ($item['columns'] as $column) {
                    if (!is_array($column)) {
                        continue;
                    }

                    $title = sanitize_text_field((string) ($column['title'] ?? ''));
                    if ($title === '') {
                        continue;
                    }

                    $links = self::sanitize_links((array) ($column['links'] ?? []));
                    if (empty($links)) {
                        continue;
                    }

                    $columns[] = [
                        'title' => $title,
                        'links' => $links,
                    ];
                }
            }

            if (!empty($columns)) {
                $row['columns'] = $columns;
            }

            $highlight = self::sanitize_highlight($item['highlight'] ?? null);
            if (!empty($highlight)) {
                $row['highlight'] = $highlight;
            }

            $footer_cta = self::sanitize_footer_cta($item['footer_cta'] ?? null);
            if (!empty($footer_cta)) {
                $row['footer_cta'] = $footer_cta;
            }
        }

        if ($kind === 'dropdown') {
            $links = self::sanitize_links((array) ($item['links'] ?? []));
            if (!empty($links)) {
                $row['links'] = $links;
            }
        }

        return $row;
    }

    private static function sanitize_links(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $label = sanitize_text_field((string) ($link['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $out[] = [
                'label' => $label,
                'url' => esc_url_raw((string) ($link['url'] ?? '#')),
            ];
        }

        return $out;
    }

    private static function sanitize_highlight(mixed $highlight): array
    {
        if (!is_array($highlight)) {
            return [];
        }

        $title = sanitize_text_field((string) ($highlight['title'] ?? ''));
        $text = sanitize_text_field((string) ($highlight['text'] ?? ''));
        $image_url = esc_url_raw((string) ($highlight['image_url'] ?? ''));
        $image_alt = sanitize_text_field((string) ($highlight['image_alt'] ?? ''));

        if ($title === '' && $text === '' && $image_url === '') {
            return [];
        }

        return [
            'eyebrow' => sanitize_text_field((string) ($highlight['eyebrow'] ?? '')),
            'title' => $title,
            'text' => $text,
            'image_url' => $image_url,
            'image_alt' => $image_alt,
            'cta_label' => sanitize_text_field((string) ($highlight['cta_label'] ?? '')),
            'cta_url' => esc_url_raw((string) ($highlight['cta_url'] ?? '')),
        ];
    }

    private static function sanitize_footer_cta(mixed $footer_cta): array
    {
        if (!is_array($footer_cta)) {
            return [];
        }

        $label = sanitize_text_field((string) ($footer_cta['label'] ?? ''));
        if ($label === '') {
            return [];
        }

        return [
            'label' => $label,
            'url' => esc_url_raw((string) ($footer_cta['url'] ?? '#')),
        ];
    }

    private static function default_menu_items(): array
    {
        return [
            [
                'id' => 'ontdek',
                'label' => 'Ontdek',
                'url' => home_url('/activiteiten/'),
                'kind' => 'mega',
                'columns' => [
                    [
                        'title' => 'Highlights',
                        'links' => [
                            ['label' => 'Top Spots', 'url' => home_url('/ontdek/top-spots/')],
                            ['label' => 'Hidden Gems', 'url' => home_url('/ontdek/hidden-gems/')],
                            ['label' => 'Nieuwe Hotspots', 'url' => home_url('/ontdek/nieuw/')],
                        ],
                    ],
                    [
                        'title' => 'Cultuur & Beleving',
                        'links' => [
                            ['label' => 'Musea', 'url' => home_url('/ontdek/musea/')],
                            ['label' => 'Monumenten', 'url' => home_url('/ontdek/monumenten/')],
                            ['label' => 'Stadswandelingen', 'url' => home_url('/ontdek/stadswandelingen/')],
                        ],
                    ],
                    [
                        'title' => 'Seizoens Tips',
                        'links' => [
                            ['label' => 'Lente', 'url' => home_url('/ontdek/lente/')],
                            ['label' => 'Zomer', 'url' => home_url('/ontdek/zomer/')],
                            ['label' => 'Winter', 'url' => home_url('/ontdek/winter/')],
                        ],
                    ],
                    [
                        'title' => 'Inspiratie',
                        'links' => [
                            ['label' => 'Weekend gids', 'url' => home_url('/inspiratie/weekend/')],
                            ['label' => 'Date ideeën', 'url' => home_url('/inspiratie/date/')],
                            ['label' => 'Met kinderen', 'url' => home_url('/inspiratie/kinderen/')],
                        ],
                    ],
                ],
                'highlight' => [
                    'eyebrow' => 'Aanrader',
                    'title' => '48 uur in Den Bosch',
                    'text' => 'Een compact programma met cultuur, food en verrassende stops in de stad.',
                    'cta_label' => 'Bekijk route',
                    'cta_url' => home_url('/plan-je-dag/'),
                ],
            ],
            [
                'id' => 'activiteiten',
                'label' => 'Activiteiten',
                'url' => home_url('/activiteiten/'),
                'kind' => 'mega',
                'columns' => [
                    [
                        'title' => 'Tours',
                        'links' => [
                            ['label' => 'Stadstours', 'url' => home_url('/activiteiten/stadstours/')],
                            ['label' => 'Private Tours', 'url' => home_url('/private-tour/')],
                            ['label' => 'Audio Tours', 'url' => home_url('/activiteiten/audio-tours/')],
                        ],
                    ],
                    [
                        'title' => 'Actief',
                        'links' => [
                            ['label' => 'Outdoor', 'url' => home_url('/activiteiten/outdoor/')],
                            ['label' => 'Fietsen', 'url' => home_url('/activiteiten/fietsen/')],
                            ['label' => 'Sportief', 'url' => home_url('/activiteiten/sportief/')],
                        ],
                    ],
                    [
                        'title' => 'Fun',
                        'links' => [
                            ['label' => 'Games', 'url' => home_url('/activiteiten/games/')],
                            ['label' => 'Familie', 'url' => home_url('/activiteiten/familie/')],
                            ['label' => 'Avond', 'url' => home_url('/activiteiten/avond/')],
                        ],
                    ],
                    [
                        'title' => 'Groepen',
                        'links' => [
                            ['label' => 'Bedrijfsuitjes', 'url' => home_url('/groepen/bedrijfsuitjes/')],
                            ['label' => 'Vrijgezellen', 'url' => home_url('/groepen/vrijgezellen/')],
                            ['label' => 'Schoolgroepen', 'url' => home_url('/groepen/schoolgroepen/')],
                        ],
                    ],
                ],
                'footer_cta' => [
                    'label' => 'Bekijk alle activiteiten',
                    'url' => home_url('/activiteiten/'),
                ],
            ],
            [
                'id' => 'eten-drinken',
                'label' => 'Eten & Drinken',
                'url' => home_url('/activiteiten/?ddb_type=restaurant'),
                'kind' => 'mega',
                'columns' => [
                    [
                        'title' => 'Restaurants',
                        'links' => [
                            ['label' => 'Lokaal', 'url' => home_url('/eten-drinken/lokaal/')],
                            ['label' => 'Fine Dining', 'url' => home_url('/eten-drinken/fine-dining/')],
                            ['label' => 'Snel & Goed', 'url' => home_url('/eten-drinken/snel-goed/')],
                        ],
                    ],
                    [
                        'title' => 'Moment',
                        'links' => [
                            ['label' => 'Ontbijt', 'url' => home_url('/eten-drinken/ontbijt/')],
                            ['label' => 'Lunch', 'url' => home_url('/eten-drinken/lunch/')],
                            ['label' => 'Diner', 'url' => home_url('/eten-drinken/diner/')],
                        ],
                    ],
                    [
                        'title' => 'Sfeer',
                        'links' => [
                            ['label' => 'Terrassen', 'url' => home_url('/eten-drinken/terrassen/')],
                            ['label' => 'Romantisch', 'url' => home_url('/eten-drinken/romantisch/')],
                            ['label' => 'Gezellig', 'url' => home_url('/eten-drinken/gezellig/')],
                        ],
                    ],
                    [
                        'title' => 'Special',
                        'links' => [
                            ['label' => 'Borrels', 'url' => home_url('/eten-drinken/borrels/')],
                            ['label' => 'High Tea', 'url' => home_url('/eten-drinken/high-tea/')],
                            ['label' => 'Vega', 'url' => home_url('/eten-drinken/vega/')],
                        ],
                    ],
                ],
                'footer_cta' => [
                    'label' => 'Bekijk alle horeca',
                    'url' => home_url('/eten-drinken/'),
                ],
            ],
            [
                'id' => 'plan-je-dag',
                'label' => 'Plan je Dag',
                'url' => home_url('/plan-je-dag/'),
                'kind' => 'mega',
                'columns' => [
                    [
                        'title' => 'Planner',
                        'links' => [
                            ['label' => 'Start planner', 'url' => home_url('/plan-je-dag/')],
                            ['label' => 'Dagdelen', 'url' => home_url('/plan-je-dag/dagdelen/')],
                            ['label' => 'Tijdsloten', 'url' => home_url('/plan-je-dag/tijdsloten/')],
                        ],
                    ],
                    [
                        'title' => 'Combinaties',
                        'links' => [
                            ['label' => 'Met eten', 'url' => home_url('/plan-je-dag/met-eten/')],
                            ['label' => 'Met kids', 'url' => home_url('/plan-je-dag/met-kids/')],
                            ['label' => 'Budgetproof', 'url' => home_url('/plan-je-dag/budget/')],
                        ],
                    ],
                    [
                        'title' => 'Thema dagen',
                        'links' => [
                            ['label' => 'Cultureel', 'url' => home_url('/plan-je-dag/cultuur/')],
                            ['label' => 'Actief', 'url' => home_url('/plan-je-dag/actief/')],
                            ['label' => 'Relaxed', 'url' => home_url('/plan-je-dag/relaxed/')],
                        ],
                    ],
                ],
                'highlight' => [
                    'eyebrow' => 'Slim plannen',
                    'title' => 'Bouw je perfecte dag',
                    'text' => 'Combineer spots, routes en deals in een helder dagplan dat direct boekbaar is.',
                    'cta_label' => 'Open planner',
                    'cta_url' => home_url('/plan-je-dag/'),
                ],
            ],
            [
                'id' => 'deals',
                'label' => 'Deals',
                'url' => home_url('/activiteiten/?ddb_q=deals'),
                'kind' => 'mega',
                'columns' => [
                    [
                        'title' => 'Populair',
                        'links' => [
                            ['label' => 'Top deals', 'url' => home_url('/deals/top/')],
                            ['label' => '2 voor 1', 'url' => home_url('/deals/2-voor-1/')],
                            ['label' => 'Last minute', 'url' => home_url('/deals/last-minute/')],
                        ],
                    ],
                    [
                        'title' => 'Actueel',
                        'links' => [
                            ['label' => 'Deze week', 'url' => home_url('/deals/deze-week/')],
                            ['label' => 'Weekend', 'url' => home_url('/deals/weekend/')],
                            ['label' => 'Seizoensdeals', 'url' => home_url('/deals/seizoen/')],
                        ],
                    ],
                    [
                        'title' => 'Voor groepen',
                        'links' => [
                            ['label' => 'Zakelijk', 'url' => home_url('/deals/zakelijk/')],
                            ['label' => 'Teamuitjes', 'url' => home_url('/deals/teamuitjes/')],
                            ['label' => 'Arrangementen', 'url' => home_url('/deals/arrangementen/')],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'groepen',
                'label' => 'Groepen',
                'url' => home_url('/activiteiten/?ddb_q=groepen'),
                'kind' => 'dropdown',
                'links' => [
                    ['label' => 'Bedrijfsuitjes', 'url' => home_url('/groepen/bedrijfsuitjes/')],
                    ['label' => 'Vrijgezellen', 'url' => home_url('/groepen/vrijgezellen/')],
                    ['label' => 'Scholen', 'url' => home_url('/groepen/scholen/')],
                    ['label' => 'Maatwerk aanvragen', 'url' => home_url('/groepen/aanvraag/')],
                ],
            ],
        ];
    }
}
