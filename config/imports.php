<?php

return [
    'max_file_mb' => (int) env('IMPORT_MAX_FILE_MB', 20),
    'summary_year' => (int) env('IMPORT_SUMMARY_YEAR', 2026),

    'header_aliases' => [
        'project_code' => [
            'code',
            'project code',
            'project code no',
            'projectcode',
            'project no',
            'project number',
        ],
        'project_beneficiaries' => [
            'no. of beneficiaries',
            'no of beneficiaries',
            'number of beneficiaries',
            'project beneficiaries',
        ],
        'municipality' => ['municipality', 'municipalities'],
        'barangay' => ['barangay', 'barangays', 'barangay/s'],
        'per_barangay_total' => [
            'no. of bene per barangay',
            'no of bene per barangay',
            'no. of beneficiaries per barangay',
            'beneficiaries per barangay',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Group Project detection
    |--------------------------------------------------------------------------
    |
    | Any data row containing one of these exact Excel fill colors is treated
    | as a Group Project row. Group Project rows are stored separately and are
    | excluded from normal municipality, barangay, beneficiary, and undertaking
    | totals.
    |
    */
    'group_projects' => [
        'fill_colors' => [
            'EA9999',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Municipality lists used by Mapping Setup
    |--------------------------------------------------------------------------
    */
    'municipality_lists' => [
        'Albay' => [
            'Bacacay',
            'Camalig',
            'Daraga',
            'Guinobatan',
            'Jovellar',
            'Legazpi',
            'Libon',
            'Ligao',
            'Malilipot',
            'Malinao',
            'Manito',
            'Oas',
            'Pio Duran',
            'Polangui',
            'Rapu-Rapu',
            'Santo Domingo',
            'Tabaco',
            'Tiwi',
        ],
    ],

    'municipality_aliases' => [
        'Albay' => [
            'Bacacy' => 'Bacacay',
            'City of Legazpi' => 'Legazpi',
            'Legazpi City' => 'Legazpi',
            'City of Ligao' => 'Ligao',
            'Ligao City' => 'Ligao',
            'Sto. Domingo' => 'Santo Domingo',
            'Municipality of Santo Domingo' => 'Santo Domingo',
            'City of Tabaco' => 'Tabaco',
            'Tabaco City' => 'Tabaco',
        ],
    ],

    'miro' => [
        'panel_fill' => '#8FD246',
        'panel_border' => '#1A1A1A',
        'panel_text' => '#1A1A1A',

        // Group Projects are yellow on Miro even though #EA9999 is the Excel marker.
        'group_panel_fill' => '#FFD966',
        'group_panel_border' => '#BF9000',
        'group_panel_text' => '#1A1A1A',

        // Summary box palette based on the supplied reference layout.
        'summary_top_fill' => '#F9CB40',
        'summary_undertakings_fill' => '#E6B8AF',
        'summary_highest_fill' => '#D9EAD3',
        'summary_least_fill' => '#FFD966',
        'summary_beneficiaries_fill' => '#FF5050',
        'summary_group_fill' => '#B6D989',
        'summary_total_fill' => '#FFF07A',
        'summary_border' => '#B7B7B7',
        'summary_text' => '#1A1A1A',

        'connector_color' => '#FF3B1F',
        'anchor_fill' => '#FF4B64',
        'anchor_border' => '#FF4B64',
    ],

    'board_cleanup' => [
        'nearest_panel_distance' => 1800,
        'delete_red_legacy_connectors' => true,
    ],

    'layout' => [
        'panel_width' => 430,
        'panel_min_height' => 300,
        'panel_max_height' => 850,
        'panel_line_height' => 24,
        'panel_padding' => 56,
        'max_lines_per_panel' => 29,
        'panel_gap' => 70,

        'group_panel_width' => 470,
        'group_panel_min_height' => 280,
        'group_panel_max_height' => 900,
        'group_panel_line_height' => 24,
        'group_panel_padding' => 72,
        'group_panel_max_lines' => 30,
        'group_panel_gap' => 90,
        'group_panel_columns' => 2,
        'group_panel_x_gap_from_map' => 900,

        // One highlighted project block = one compact yellow Miro box.
        'group_panel_compact_height' => 220,

        // Summary block positioned after the map + Group Project section.
        'summary_x_gap' => 900,
        'summary_column_gap' => 90,
        'summary_row_gap' => 70,
        'summary_left_width' => 560,
        'summary_right_width' => 620,
        'summary_top_height' => 300,
        'summary_undertakings_min_height' => 850,
        'summary_undertakings_max_height' => 1800,
        'summary_right_box_height' => 360,
        'summary_small_box_height' => 250,
        'summary_font_size' => 18,
        'summary_highest_count' => 4,
        'summary_least_count' => 6,

        'anchor_size' => 18,
        'auto_panel_distance' => 620,
        'default_anchor_x_offset' => 0,
        'default_panel_x_offset' => 560,
        'default_municipality_vertical_gap' => 940,
    ],
];
