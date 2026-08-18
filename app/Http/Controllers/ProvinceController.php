<?php

namespace App\Http\Controllers;

use App\Models\MiroConnection;
use App\Models\MunicipalityMapping;
use App\Models\Province;
use App\Services\MiroMappingDiscoveryService;
use App\Services\MiroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ProvinceController extends Controller
{
    public function index(): View
    {
        return view('provinces.index', [
            'provinces' => Province::query()
                ->withCount('municipalityMappings')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request, Province $province, MiroService $miro): RedirectResponse
    {
        $validated = $request->validate([
            'sheet_name' => ['required', 'string', 'max:100'],
            'miro_board_id' => ['nullable', 'string', 'max:2000'],
            'miro_frame_id' => ['nullable', 'string', 'max:255'],
            'base_x' => ['required', 'integer', 'between:-1000000,1000000'],
            'base_y' => ['required', 'integer', 'between:-1000000,1000000'],
            'active' => ['nullable', 'boolean'],
        ]);

        $validated['miro_board_id'] = $this->normalizeBoardId($validated['miro_board_id'] ?? null);

        if (!empty($validated['miro_board_id']) && MiroConnection::query()->exists()) {
            try {
                $miro->getBoard($validated['miro_board_id']);
            } catch (Throwable $e) {
                return back()->withInput()->with('error', 'Board validation failed: '.$e->getMessage());
            }
        }

        $province->update([
            ...$validated,
            'active' => $request->boolean('active'),
        ]);

        $message = $province->name.' mapping settings saved.';

        if (!empty($validated['miro_board_id']) && !MiroConnection::query()->exists()) {
            $message .= ' Connect Miro in Settings before importing so the board can be validated.';
        }

        return back()->with('success', $message);
    }

    public function municipalities(
        Province $province,
        MiroMappingDiscoveryService $discovery,
    ): View {
        $discovery->ensureProvinceMunicipalities($province);

        return view('provinces.municipalities', [
            'province' => $province,
            'mappings' => $province->municipalityMappings()
                ->orderBy('sort_order')
                ->orderBy('municipality')
                ->get(),
            'scanStatuses' => $discovery->statuses($province),
        ]);
    }

    public function scanMunicipalities(
        Province $province,
        MiroMappingDiscoveryService $discovery,
    ): RedirectResponse {
        if (!$province->miro_board_id) {
            return back()->with('error', 'Configure a Miro board for '.$province->name.' before scanning.');
        }

        try {
            $result = $discovery->scan($province);
        } catch (Throwable $e) {
            return back()->with('error', 'Miro mapping scan failed: '.$e->getMessage());
        }

        return back()->with(
            'success',
            sprintf(
                'Miro scan complete: %d of %d municipality anchors found; %d already have green boxes; %d will receive new boxes on import; %d anchors are still missing.',
                $result['anchors_found'],
                $result['municipality_count'],
                $result['with_existing_boxes'],
                $result['without_existing_boxes'],
                $result['anchors_missing'],
            )
        );
    }

    public function updateMunicipalities(Request $request, Province $province): RedirectResponse
    {
        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.id' => ['required', 'integer'],
            'mappings.*.anchor_x' => ['required', 'integer', 'between:-1000000,1000000'],
            'mappings.*.anchor_y' => ['required', 'integer', 'between:-1000000,1000000'],
            'mappings.*.panel_x' => ['required', 'integer', 'between:-1000000,1000000'],
            'mappings.*.panel_y' => ['required', 'integer', 'between:-1000000,1000000'],
            'mappings.*.flow_direction' => ['required', 'in:right,left,down,up'],
            'mappings.*.configured' => ['nullable', 'boolean'],
        ]);

        foreach ($validated['mappings'] as $values) {
            $mapping = MunicipalityMapping::query()
                ->where('province_id', $province->id)
                ->findOrFail($values['id']);

            $mapping->update([
                'anchor_x' => $values['anchor_x'],
                'anchor_y' => $values['anchor_y'],
                'panel_x' => $values['panel_x'],
                'panel_y' => $values['panel_y'],
                'flow_direction' => $values['flow_direction'],
                'configured' => (bool) ($values['configured'] ?? false),
            ]);
        }

        return back()->with('success', $province->name.' municipality placement settings saved.');
    }

    private function normalizeBoardId(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($value, PHP_URL_PATH);

            if (preg_match('~/board/([^/]+)~', $path, $matches)) {
                return rawurldecode($matches[1]);
            }

            throw \Illuminate\Validation\ValidationException::withMessages([
                'miro_board_id' => 'The Miro URL does not contain a recognizable board ID.',
            ]);
        }

        return rawurldecode($value);
    }
}
