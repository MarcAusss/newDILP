<?php

namespace App\Services;

use App\Models\MunicipalityMapping;
use App\Models\Province;

class MunicipalityMappingService
{
    public function ensure(Province $province, array $municipalities): void
    {
        $settings = config('imports.layout');

        foreach ($municipalities as $index => $municipality) {
            $anchorX = $province->base_x + $settings['default_anchor_x_offset'];
            $anchorY = $province->base_y + ($index * $settings['default_municipality_vertical_gap']);
            $panelX = $province->base_x + $settings['default_panel_x_offset'];
            $panelY = $anchorY;

            $mapping = MunicipalityMapping::query()->firstOrNew([
                'province_id' => $province->id,
                'municipality_key' => $municipality['key'],
            ]);

            $mapping->municipality = $municipality['name'];
            $mapping->sort_order = $municipality['sort_order'];

            if (!$mapping->exists) {
                $mapping->anchor_x = $anchorX;
                $mapping->anchor_y = $anchorY;
                $mapping->panel_x = $panelX;
                $mapping->panel_y = $panelY;
                $mapping->flow_direction = 'right';
                $mapping->configured = false;
            }

            $mapping->save();
        }
    }
}
