<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $this->sheetName     = config('services.google.sheets.sheet_tab', 'Inventory');

        $client  = new Client();
        $encoded = config('services.google.credentials_b64');
        $decoded = base64_decode($encoded, true);

        if (! $decoded || ! json_decode($decoded)) {
            throw new \RuntimeException('Invalid base64 or JSON in GOOGLE_APPLICATION_CREDENTIALS_B64');
        }

        $path = storage_path('app/google/freezer-key.json');
        if (! file_exists($path)) {
            file_put_contents($path, $decoded);
        }

        $client->setAuthConfig($path);
        $client->addScope(Sheets::SPREADSHEETS);

        $this->sheetService = new Sheets($client);

        $spreadsheet = $this->sheetService
            ->spreadsheets
            ->get($this->spreadsheetId);

        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === $this->sheetName) {
                $this->sheetId = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if (! isset($this->sheetId)) {
            throw new \Exception("Sheet tab '{$this->sheetName}' not found in spreadsheet.");
        }
    }

    public function add(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item'     => 'required|string',
            'quantity' => 'required|numeric',
            'category' => 'nullable|string',
            'notes'    => 'nullable|string',
        ]);

        $itemKey = Str::lower(Str::singular($validated['item']));
        $range   = $this->sheetName . '!A:D';
        $resp    = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows    = $resp->getValues() ?: [];
        $found   = false;

        // 1) Try to update an existing row
        foreach ($rows as $idx => $row) {
            $cand = $row[1] ?? '';
            if (Str::lower(Str::singular($cand)) !== $itemKey) {
                continue;
            }

            $current = is_numeric($row[2] ?? null)
                ? (float)$row[2]
                : 0.0;
            $addQty  = (float)$validated['quantity'];
            $newQty  = $current + $addQty;

            // update in-memory
            $rows[$idx][2] = $newQty;
            if (! empty($validated['category'])) {
                $rows[$idx][0] = $validated['category'];
            }
            if (! empty($validated['notes'])) {
                $rows[$idx][3] = $validated['notes'];
            }

            // write back
            $updateRange = $this->sheetName . '!A' . ($idx + 1) . ':D' . ($idx + 1);
            $body        = new Sheets\ValueRange(['values' => [ $rows[$idx] ]]);
            $params      = ['valueInputOption' => 'USER_ENTERED'];
            $this->sheetService
                ->spreadsheets_values
                ->update($this->spreadsheetId, $updateRange, $body, $params);

            $found = true;
            break;
        }

        // 2) If not found, append a new row
        if (! $found) {
            // if the user didn’t supply a category, use “Uncategorized”
            $category = ! empty($validated['category'])
                ? $validated['category']
                : 'Uncategorized';

            $addQty = (float)$validated['quantity'];
            $values = [[
                $category,
                $validated['item'],
                $addQty,
                $validated['notes'] ?? '',
            ]];
            $body   = new Sheets\ValueRange(['values' => $values]);
            $params = ['valueInputOption' => 'USER_ENTERED'];
            $this->sheetService
                ->spreadsheets_values
                ->append($this->spreadsheetId, $range, $body, $params);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Item added',
        ]);
    }

    public function remove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item'     => 'required|string',
            'quantity' => 'nullable|numeric',
        ]);

        $itemKey = Str::lower(Str::singular($validated['item']));
        $range   = $this->sheetName . '!A:D';
        $resp    = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows    = $resp->getValues() ?: [];
        $found   = false;

        foreach ($rows as $idx => $row) {
            if ($idx === 0) {
                continue; // skip header
            }
            $cand = $row[1] ?? '';
            if (Str::lower(Str::singular($cand)) !== $itemKey) {
                continue;
            }

            $current     = is_numeric($row[2] ?? null) ? (float)$row[2] : 0;
            $removeQty   = isset($validated['quantity'])
                ? (float)$validated['quantity']
                : $current;
            $newQuantity = $current - $removeQty;

            if ($newQuantity > 0) {
                $row[2] = $newQuantity;
                $body    = new Sheets\ValueRange(['values' => [ $row ]]);
                $params  = ['valueInputOption' => 'USER_ENTERED'];
                $updateRange = $this->sheetName . '!A' . ($idx+1) . ':D' . ($idx+1);
                $this->sheetService
                    ->spreadsheets_values
                    ->update($this->spreadsheetId, $updateRange, $body, $params);
            } else {
                $this->sheetService
                    ->spreadsheets
                    ->batchUpdate($this->spreadsheetId, new Sheets\BatchUpdateSpreadsheetRequest([
                        'requests' => [[
                            'deleteDimension' => [
                                'range' => [
                                    'sheetId'    => $this->sheetId,
                                    'dimension'  => 'ROWS',
                                    'startIndex' => $idx,
                                    'endIndex'   => $idx + 1,
                                ],
                            ],
                        ]],
                    ]));
            }

            $found = true;
            break;
        }

        if (! $found) {
            return response()->json(['status'=>'not_found','message'=>'Item not found']);
        }

        return response()->json(['status'=>'success','message'=>'Item removed']);
    }

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item' => 'required|string',
        ]);

        $itemKey = Str::lower(Str::singular($validated['item']));
        $range   = $this->sheetName . '!A:D';
        $resp    = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows    = $resp->getValues() ?: [];

        foreach ($rows as $idx => $row) {
            if ($idx === 0) {
                continue;
            }
            $cand = $row[1] ?? '';
            if (Str::lower(Str::singular($cand)) === $itemKey) {
                return response()->json([
                    'status'   => 'success',
                    'item'     => $row[1],
                    'quantity' => isset($row[2]) ? floatval($row[2]) : 0,
                    'category' => $row[0] ?? '',
                    'notes'    => $row[3] ?? '',
                ]);
            }
        }

        return response()->json(['status'=>'not_found','message'=>'Item not found']);
    }

    public function list(Request $request): JsonResponse
    {
        $range   = $this->sheetName . '!A:D';
        $resp    = $this->sheetService->spreadsheets_values->get($this->spreadsheetId, $range);
        $rows    = $resp->getValues() ?: [];

        if (count($rows) <= 1) {
            return response()->json(['status'=>'success','inventory'=>[]]);
        }

        $itemRawFilter  = $request->query('item','');
        $itemFilter     = Str::lower(Str::singular($itemRawFilter));
        $categoryFilter = Str::lower($request->query('category',''));

        $inventory = [];
        foreach (array_slice($rows, 1) as $row) {
            $category = $row[0] ?? '';
            $itemName = $row[1] ?? '';
            $qty      = isset($row[2]) ? floatval($row[2]) : 0;
            $notes    = $row[3] ?? '';

            if ($itemFilter && stripos(Str::lower(Str::singular($itemName)), $itemFilter) === false) {
                continue;
            }
            if ($categoryFilter && stripos($category, $categoryFilter) === false) {
                continue;
            }

            $inventory[] = compact('category','itemName','qty','notes');
        }

        return response()->json(['status'=>'success','inventory'=>$inventory]);
    }

    public function handleOpenAI(Request $request): \Illuminate\Http\JsonResponse
    {
        // 0) Log entry
        \Log::info('[handleOpenAI] entry at '.now()->toIso8601String());

        // 1) Validate incoming phrase
        $validated = $request->validate([
            'phrase' => 'required|string',
        ]);
        $phrase = $validated['phrase'];

        // 2) Strip off the Alexa invocation boilerplate
        //    e.g. "ask food tracker to remove three pizzas" → "remove three pizzas"
        $raw = preg_replace(
            '/^(?:ask|tell|have|use)\s+food\s+tracker(?:\s+to)?\s+/i',
            '',
            $phrase
        );
        $normalized = trim(strtolower($raw));
        \Log::info('[handleOpenAI] normalized phrase: '.$normalized);

        // 3) Load your categories for the system prompt
        $sheetRange = $this->sheetName . '!A2:A';
        $resp = $this->sheetService
            ->spreadsheets_values
            ->get($this->spreadsheetId, $sheetRange);
        $allowedCategories = collect($resp->getValues() ?: [])
            ->flatten()
            ->unique()
            ->values()
            ->all();
        $categoryList = implode(', ', $allowedCategories);

        // 4) Build a strict system prompt
        $systemPrompt = <<<EOT
You are a freezer inventory assistant.
You have exactly four functions—never reply in plain text, only call one function.
Functions:
  • addInventory(item: string, quantity: number, category?: string, notes?: string)
  • removeInventory(item: string, quantity?: number)
  • checkInventory(item: string)
  • listInventory(item?: string, category?: string)

Categories must be one of: {$categoryList}.
Always pick exactly one function.
EOT;

        // 5) Define the tools (closures just serialize args)
        $addTool = Tool::as('addInventory')
            ->for('Add or update an item in the freezer')
            ->withStringParameter('item',     'Name of the item')
            ->withNumberParameter('quantity', 'Quantity to add')
            ->withStringParameter('category', "Category, one of: {$categoryList}", false, $allowedCategories)
            ->withStringParameter('notes',    'Optional notes', false)
            ->using(fn(string $item, float $quantity, ?string $category = null, ?string $notes = null): string =>
            json_encode(compact('item','quantity','category','notes'))
            );

        $removeTool = Tool::as('removeInventory')
            ->for('Remove quantity of an item from the freezer')
            ->withStringParameter('item',     'Name of the item')
            ->withNumberParameter('quantity', 'Quantity to remove', false)
            ->using(fn(string $item, ?float $quantity = null): string =>
            json_encode(compact('item','quantity'))
            );

        $checkTool = Tool::as('checkInventory')
            ->for('Check the quantity of an item in the freezer')
            ->withStringParameter('item', 'Name of the item')
            ->using(fn(string $item): string =>
            json_encode(compact('item'))
            );

        $listTool = Tool::as('listInventory')
            ->for('List all or filtered items in the freezer')
            ->withStringParameter('item',     'Optional item filter', false)
            ->withStringParameter('category', 'Optional category filter', false)
            ->using(fn(?string $item = null, ?string $category = null): string =>
            json_encode(compact('item','category'))
            );

        // 6) Call Prism/OpenAI
        $response = Prism::text()
            ->using(Provider::OpenAI, 'gpt-4-0613')
            ->withMaxSteps(1)
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($normalized)
            ->withTools([$addTool, $removeTool, $checkTool, $listTool])
            ->withToolChoice(ToolChoice::Auto)
            ->asText();

        // 7) Inspect which tool (if any) was selected
        $toolCalls = collect($response->steps[0]->toolCalls ?? [])->pluck('name')->all();
        \Log::info('[handleOpenAI] Prism toolCalls:', ['called' => $toolCalls]);

        // 8) If Prism pick a tool, dispatch it
        if (! empty($toolCalls)) {
            $call = $response->steps[0]->toolCalls[0];
            $args = (array) $call->arguments();

            switch ($call->name) {
                case 'addInventory':
                    $this->add(new Request($args));
                    return response()->json([
                        'status' => 'success',
                        'speech' => "Added {$args['quantity']} {$args['item']}."
                    ]);

                case 'removeInventory':
                    $respRemove  = $this->remove(new Request($args));
                    $dataRemove  = $respRemove->getData(true);
                    $qty         = $args['quantity'] ?? ($dataRemove['quantity'] ?? 0);
                    $item        = $args['item']     ?? '';
                    $dataRemove['speech'] = "Removed {$qty} {$item}.";
                    return response()->json($dataRemove);

                case 'checkInventory':
                    $resCheck = $this->check(new Request($args))->getData(true);
                    $speech   = ($resCheck['status'] ?? '') === 'success'
                        ? "You have {$resCheck['quantity']} {$resCheck['item']}."
                        : ($resCheck['message'] ?? 'Item not found.');
                    return response()->json([
                        'status' => $resCheck['status'] ?? 'error',
                        'speech' => $speech,
                    ]);

                case 'listInventory':
                    $resList = $this->list(new Request($args))->getData(true);
                    if (empty($resList['inventory'])) {
                        $speech = 'Your freezer is empty.';
                    } else {
                        $lines  = array_map(fn($i) => "{$i['qty']} {$i['itemName']}", $resList['inventory']);
                        $speech = 'You have ' . implode(', ', $lines) . '.';
                    }
                    return response()->json(['status' => 'success', 'speech' => $speech]);
            }
        }

        // 9) Prism didn’t pick a tool → fallback to regex on $normalized
        \Log::warning('[handleOpenAI] no toolCalls, falling back to regex', ['phrase' => $normalized]);

        // add N ITEM
        if (preg_match('/^add\s+(\d+)\s+(.+)$/i', $normalized, $m)) {
            $qty  = (int)$m[1];
            $item = trim($m[2]);
            $this->add(new Request(['item' => $item, 'quantity' => $qty]));
            return response()->json(['status' => 'success', 'speech' => "Added {$qty} {$item}."]);
        }

        // remove N ITEM
        if (preg_match('/^remove\s+(\d+)\s+(.+)$/i', $normalized, $m)) {
            $qty  = (int)$m[1];
            $item = trim($m[2]);
            $this->remove(new Request(['item' => $item, 'quantity' => $qty]));
            return response()->json(['status' => 'success', 'speech' => "Removed {$qty} {$item}."]);
        }

        // check ITEM
        if (preg_match('/^(?:how many|how much)\s+(.+?)(?:\s+do we have)?$/i', $normalized, $m)) {
            $item  = trim($m[1]);
            $resCh = $this->check(new Request(['item' => $item]))->getData(true);
            $speech = ($resCh['status'] ?? '') === 'success'
                ? "You have {$resCh['quantity']} {$resCh['item']}."
                : ($resCh['message'] ?? 'Item not found.');
            return response()->json(['status' => $resCh['status'] ?? 'error', 'speech' => $speech]);
        }

        // 10) Nothing matched
        return response()->json([
            'status' => 'error',
            'speech' => "Sorry, I didn’t understand that. You can say add, remove, or check an item."
        ]);
    }
}
