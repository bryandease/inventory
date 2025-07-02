<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client;
use Google\Service\Sheets;

class FreezerInventoryController extends Controller
{
    protected $sheetService;
    protected $spreadsheetId;
    protected $sheetName;
    protected $sheetId;

    public function __construct()
    {
        $this->spreadsheetId = config('services.google.sheets.sheet_id');
        $this->sheetName = config('services.google.sheets.sheet_tab', 'Inventory');

        $client = new Client();
        $client->setAuthConfig(base_path(config('services.google.sheets.credentials_path')));
        $client->addScope(Sheets::SPREADSHEETS);

        $this->sheetService = new Sheets($client);

        $spreadsheet = $this->sheetService->spreadsheets->get($this->spreadsheetId);
        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === $this->sheetName) {
                $this->sheetId = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if (!isset($this->sheetId)) {
            throw new \Exception("Sheet tab '{$this->sheetName}' not found in spreadsheet.");
        }
    }

    public function add(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'item' => 'required|string',
            'quantity' => 'required|numeric',
            'category' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $range = $this->sheetName . '!A:D';
        $response = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows = $response->getValues() ?: [];

        $itemLower = strtolower($validated['item']);
        $found = false;

        foreach ($rows as $index => $row) {
            if (isset($row[1]) && strtolower($row[1]) === $itemLower) {
                $currentQuantity = isset($row[2]) ? floatval($row[2]) : 0;
                $newQuantity = $currentQuantity + $validated['quantity'];
                $rows[$index][2] = $newQuantity;

                if (isset($validated['category'])) {
                    $rows[$index][0] = $validated['category'];
                }
                if (isset($validated['notes'])) {
                    $rows[$index][3] = $validated['notes'];
                }

                $updateRange = $this->sheetName . '!A' . ($index + 1) . ':D' . ($index + 1);
                $body = new Sheets\ValueRange(['values' => [$rows[$index]]]);
                $params = ['valueInputOption' => 'USER_ENTERED'];
                $this->sheetService->spreadsheets_values->update(
                    $this->spreadsheetId,
                    $updateRange,
                    $body,
                    $params
                );

                $found = true;
                break;
            }
        }

        if (!$found) {
            $values = [
                [
                    $validated['category'] ?? '',
                    $validated['item'],
                    $validated['quantity'],
                    $validated['notes'] ?? ''
                ]
            ];

            $body = new Sheets\ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'USER_ENTERED'];
            $this->sheetService->spreadsheets_values->append(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );
        }

        return response()->json(['status' => 'success', 'message' => 'Item added']);
    }

    public function remove(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'item' => 'required|string',
            'quantity' => 'nullable|numeric',
        ]);

        $range = $this->sheetName . '!A:D';
        $response = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows = $response->getValues() ?: [];

        $itemLower = strtolower($validated['item']);
        $found = false;

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue; // skip header
            }

            if (isset($row[1]) && strtolower($row[1]) === $itemLower) {
                $found = true;

                $currentQuantity = isset($row[2]) ? floatval($row[2]) : 0;
                $removeQuantity = $validated['quantity'] ?? $currentQuantity;
                $newQuantity = $currentQuantity - $removeQuantity;

                if ($newQuantity > 0) {
                    $row[2] = $newQuantity;
                    $updateRange = $this->sheetName . '!A' . ($index + 1) . ':D' . ($index + 1);
                    $body = new Sheets\ValueRange(['values' => [$row]]);
                    $params = ['valueInputOption' => 'USER_ENTERED'];
                    $this->sheetService->spreadsheets_values->update(
                        $this->spreadsheetId,
                        $updateRange,
                        $body,
                        $params
                    );
                } else {
                    $this->sheetService->spreadsheets->batchUpdate($this->spreadsheetId, new Sheets\BatchUpdateSpreadsheetRequest([
                        'requests' => [
                            new Sheets\Request([
                                'deleteDimension' => [
                                    'range' => [
                                        'sheetId' => $this->sheetId,
                                        'dimension' => 'ROWS',
                                        'startIndex' => $index,
                                        'endIndex' => $index + 1,
                                    ]
                                ]
                            ])
                        ]
                    ]));
                }

                break;
            }
        }

        if (!$found) {
            return response()->json(['status' => 'not_found', 'message' => 'Item not found']);
        }

        return response()->json(['status' => 'success', 'message' => 'Item removed']);
    }

    public function check(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'item' => 'required|string',
        ]);

        $range = $this->sheetName . '!A:D';
        $response = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows = $response->getValues() ?: [];

        $itemLower = strtolower($validated['item']);

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            if (isset($row[1]) && strtolower($row[1]) === $itemLower) {
                return response()->json([
                    'status' => 'success',
                    'item' => $row[1],
                    'quantity' => isset($row[2]) ? floatval($row[2]) : 0,
                    'category' => $row[0] ?? '',
                    'notes' => $row[3] ?? ''
                ]);
            }
        }

        return response()->json([
            'status' => 'not_found',
            'message' => 'Item not found'
        ]);
    }
}
