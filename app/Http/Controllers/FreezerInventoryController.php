<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Google\Client;
use Google\Service\Sheets;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ToolChoice;
use Illuminate\Http\JsonResponse;

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
        $encoded = config('services.google.credentials_b64');
        $decoded = base64_decode($encoded, true);

        if (!$decoded || !json_decode($decoded)) {
            throw new \RuntimeException('Invalid base64 or JSON in GOOGLE_APPLICATION_CREDENTIALS_B64');
        }

        $tempCredentialsPath = storage_path('app/google/freezer-key.json');

        if (!file_exists($tempCredentialsPath)) {
            file_put_contents($tempCredentialsPath, $decoded);
        }

        $client->setAuthConfig($tempCredentialsPath);
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
            $values = [[
                $validated['category'] ?? '',
                $validated['item'],
                $validated['quantity'],
                $validated['notes'] ?? ''
            ]];

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

    public function list(Request $request): \Illuminate\Http\JsonResponse
    {
        $range = $this->sheetName . '!A:D';
        $response = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows = $response->getValues() ?: [];

        if (count($rows) <= 1) {
            return response()->json(['status' => 'success', 'inventory' => []]);
        }

        $inventory = [];
        $itemFilter = strtolower($request->query('item', ''));
        $categoryFilter = strtolower($request->query('category', ''));

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $category = $row[0] ?? '';
            $item = $row[1] ?? '';
            $quantity = isset($row[2]) ? floatval($row[2]) : 0;
            $notes = $row[3] ?? '';

            if ($itemFilter && stripos($item, $itemFilter) === false) {
                continue;
            }

            if ($categoryFilter && stripos($category, $categoryFilter) === false) {
                continue;
            }

            $inventory[] = compact('category', 'item', 'quantity', 'notes');
        }

        return response()->json(['status' => 'success', 'inventory' => $inventory]);
    }

    public function handleOpenAI(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phrase' => 'required|string',
        ]);

        $phrase = $validated['phrase'];

        // 2) Pull our controller‐method calls into standalone Closures
        $addFn = function(string $item, float $quantity, ?string $category = null, ?string $notes = null): string {
            return json_encode(
                $this->add(
                    new Request(compact('item','quantity','category','notes'))
                )->getData(true)
            );
        };

        $removeFn = function(string $item, ?float $quantity = null): string {
            return json_encode(
                $this->remove(
                    new Request(compact('item','quantity'))
                )->getData(true)
            );
        };

        $checkFn = function(string $item): string {
            return json_encode(
                $this->check(
                    new Request(['item' => $item])
                )->getData(true)
            );
        };

        $listFn = function(?string $item = null, ?string $category = null): string {
            return json_encode(
                $this->list(
                    new Request(compact('item','category'))
                )->getData(true)
            );
        };

        $addTool = Tool::as('addInventory')
            ->for('Add or update an item in the freezer')
            ->withStringParameter('item',     'Name of the item')
            ->withNumberParameter('quantity', 'Quantity to add')
            ->withStringParameter('category', 'Optional category', false)
            ->withStringParameter('notes',    'Optional notes',    false)
            ->using($addFn);

        $removeTool = Tool::as('removeInventory')
            ->for('Remove quantity of an item from the freezer')
            ->withStringParameter('item',     'Name of the item')
            ->withNumberParameter('quantity', 'Quantity to remove', false)
            ->using($removeFn);

        $checkTool = Tool::as('checkInventory')
            ->for('Check the quantity of an item in the freezer')
            ->withStringParameter('item', 'Name of the item')
            ->using($checkFn);

        $listTool = Tool::as('listInventory')
            ->for('List all or filtered items in the freezer')
            ->withStringParameter('item',     'Optional item name filter', false)
            ->withStringParameter('category', 'Optional category filter',    false)
            ->using($listFn);

        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4-0613')
            ->withMaxSteps(2)
            ->withSystemPrompt('You are a freezer inventory assistant.')
            ->withPrompt($phrase)
            ->withTools([$addTool, $removeTool, $checkTool, $listTool])
            ->withToolChoice(ToolChoice::Auto)
            ->asText();

        foreach ($response->steps as $step) {
            if (! empty($step->toolCalls)) {
                $call = $step->toolCalls[0];
                $args = (array) $call->arguments();

                $json = match ($call->name) {
                    'addInventory'    => $addFn(...array_values($args)),
                    'removeInventory' => $removeFn(...array_values($args)),
                    'checkInventory'  => $checkFn(...array_values($args)),
                    'listInventory'   => $listFn(...array_values($args)),
                    default           => json_encode(['status' => 'error', 'message' => 'Unknown tool']),
                };

                return response()->json(json_decode($json, true));
            }
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'Could not determine inventory action.',
        ]);
    }
}
